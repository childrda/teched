import { describe, expect, it } from 'vitest';

import {
    CONTRIBUTOR_CATEGORIES,
    RULE_CATEGORIES,
    createCompletionRegistry,
} from '../../resources/js/lesson-player/completion.js';

const PAGE = '01JPAGEULID';

const RULES = Object.keys(RULE_CATEGORIES);

function contributor(category, satisfied, overrides = {}) {
    return {
        id: `block:${category}`,
        category,
        isSatisfied: () => satisfied,
        message: `${category} is not finished`,
        ...overrides,
    };
}

describe('page rules against contributor categories', () => {
    it.each(RULES)('an unsatisfied contributor only blocks %s when its category is relevant', (rule) => {
        for (const category of CONTRIBUTOR_CATEGORIES) {
            const registry = createCompletionRegistry();
            registry.register(PAGE, contributor(category, false));

            const relevant = RULE_CATEGORIES[rule].includes(category);
            const result = registry.evaluate(PAGE, rule, { shown: true });

            expect(result.satisfied, `${rule} with an unsatisfied ${category} contributor`).toBe(
                !relevant,
            );

            expect(result.message).toBe(relevant ? `${category} is not finished` : null);
        }
    });

    it.each(RULES)('a satisfied contributor of any category leaves %s satisfied', (rule) => {
        for (const category of CONTRIBUTOR_CATEGORIES) {
            const registry = createCompletionRegistry();
            registry.register(PAGE, contributor(category, true));

            expect(registry.evaluate(PAGE, rule, { shown: true }).satisfied).toBe(true);
        }
    });

    it('confirm_video weighs confirmation contributors only', () => {
        const registry = createCompletionRegistry();
        registry.register(PAGE, contributor('confirmation', false));
        registry.register(PAGE, contributor('gradable', false));

        expect(registry.relevantContributors(PAGE, 'confirm_video').map((c) => c.category)).toEqual([
            'confirmation',
        ]);
    });

    it('pass_activity weighs gradable contributors only', () => {
        const registry = createCompletionRegistry();
        registry.register(PAGE, contributor('activity', false));
        registry.register(PAGE, contributor('gradable', true));

        // The unsatisfied activity contributor is irrelevant to this rule.
        expect(registry.evaluate(PAGE, 'pass_activity', { shown: true }).satisfied).toBe(true);
    });

    it('submit_required weighs responses, activities, and gradables', () => {
        const registry = createCompletionRegistry();
        registry.register(PAGE, contributor('confirmation', false));

        expect(registry.evaluate(PAGE, 'submit_required', { shown: true }).satisfied).toBe(true);

        registry.register(PAGE, contributor('response', false));

        expect(registry.evaluate(PAGE, 'submit_required', { shown: true }).satisfied).toBe(false);
    });
});

describe('finishing an activity versus passing it', () => {
    function gradable({ satisfied, passed }) {
        return {
            id: 'block:quiz',
            category: 'gradable',
            isSatisfied: () => satisfied,
            isPassed: () => passed,
            message: 'Score at least 80% to continue.',
        };
    }

    it('counts a submitted but failing activity as finished, not as passed', () => {
        const registry = createCompletionRegistry();
        registry.register(PAGE, gradable({ satisfied: true, passed: false }));

        for (const rule of ['complete_activity', 'submit_required']) {
            expect(registry.evaluate(PAGE, rule, { shown: true }).satisfied, rule).toBe(true);
        }

        const result = registry.evaluate(PAGE, 'pass_activity', { shown: true });

        expect(result.satisfied).toBe(false);
        expect(result.message).toBe('Score at least 80% to continue.');
    });

    it('blocks every gradable rule when the activity is neither finished nor passed', () => {
        const registry = createCompletionRegistry();
        registry.register(PAGE, gradable({ satisfied: false, passed: false }));

        for (const rule of ['complete_activity', 'submit_required', 'pass_activity']) {
            expect(registry.evaluate(PAGE, rule, { shown: true }).satisfied, rule).toBe(false);
        }
    });

    it('lets a finished and passed activity through every rule', () => {
        const registry = createCompletionRegistry();
        registry.register(PAGE, gradable({ satisfied: true, passed: true }));

        for (const rule of RULES) {
            expect(registry.evaluate(PAGE, rule, { shown: true }).satisfied, rule).toBe(true);
        }
    });

    it('falls back to isSatisfied when a gradable contributor has no isPassed', () => {
        const registry = createCompletionRegistry();
        registry.register(PAGE, contributor('gradable', true));

        expect(registry.evaluate(PAGE, 'pass_activity', { shown: true }).satisfied).toBe(true);

        registry.register(PAGE, contributor('gradable', false));

        expect(registry.evaluate(PAGE, 'pass_activity', { shown: true }).satisfied).toBe(false);
    });

    it('ignores isPassed outside pass_activity and outside the gradable category', () => {
        const registry = createCompletionRegistry();

        // Finished but failing: only pass_activity should care.
        registry.register(PAGE, gradable({ satisfied: true, passed: false }));
        expect(registry.evaluate(PAGE, 'complete_activity', { shown: true }).satisfied).toBe(true);

        // An unfinished activity contributor is not gradable, so its passing
        // state is not consulted even under pass_activity.
        const other = createCompletionRegistry();
        other.register(PAGE, {
            id: 'block:sort',
            category: 'activity',
            isSatisfied: () => false,
            isPassed: () => true,
            message: 'Finish the sort.',
        });

        expect(other.evaluate(PAGE, 'complete_activity', { shown: true }).satisfied).toBe(false);
    });

    it('rejects an isPassed that is not callable rather than silently falling back', () => {
        const registry = createCompletionRegistry();

        expect(() =>
            registry.register(PAGE, {
                id: 'block:quiz',
                category: 'gradable',
                isSatisfied: () => true,
                isPassed: true,
                message: '',
            }),
        ).toThrow(/isPassed that is not a function/);
    });
});

describe('rules with nothing relevant to weigh', () => {
    it.each(RULES)('%s is satisfied when no contributor is registered', (rule) => {
        const registry = createCompletionRegistry();

        const result = registry.evaluate(PAGE, rule, { shown: true });

        expect(result.satisfied).toBe(true);
        expect(result.message).toBeNull();
    });

    it('an unknown rule cannot trap a student on a page', () => {
        const registry = createCompletionRegistry();
        registry.register(PAGE, contributor('gradable', false));

        expect(registry.evaluate(PAGE, 'rule_from_a_future_phase', { shown: true }).satisfied).toBe(
            true,
        );
    });

    it('view is satisfied by the page having been shown', () => {
        const registry = createCompletionRegistry();

        expect(registry.evaluate(PAGE, 'view', { shown: false }).satisfied).toBe(false);
        expect(registry.evaluate(PAGE, 'view', { shown: true }).satisfied).toBe(true);
    });
});

describe('the message a student is shown', () => {
    it('surfaces the first unsatisfied contributor in registration order', () => {
        const registry = createCompletionRegistry();

        registry.register(PAGE, contributor('response', true, { id: 'block:one' }));
        registry.register(PAGE, {
            id: 'block:two',
            category: 'response',
            isSatisfied: () => false,
            message: 'Answer question 2.',
        });
        registry.register(PAGE, {
            id: 'block:three',
            category: 'activity',
            isSatisfied: () => false,
            message: 'Finish the drag and drop.',
        });

        expect(registry.evaluate(PAGE, 'submit_required', { shown: true }).message).toBe(
            'Answer question 2.',
        );
    });

    it('falls back to a general message when a contributor supplies none', () => {
        const registry = createCompletionRegistry();

        registry.register(PAGE, {
            id: 'block:quiet',
            category: 'response',
            isSatisfied: () => false,
            message: '',
        });

        expect(registry.evaluate(PAGE, 'submit_required', { shown: true }).message).toBe(
            'Finish this page to continue.',
        );
    });
});

describe('registration', () => {
    it('keeps contributors scoped to their own page', () => {
        const registry = createCompletionRegistry();
        registry.register(PAGE, contributor('response', false));

        expect(registry.evaluate('another-page', 'submit_required', { shown: true }).satisfied).toBe(
            true,
        );
    });

    it('reflects the current answer each time it is evaluated', () => {
        const registry = createCompletionRegistry();
        let confirmed = false;

        registry.register(PAGE, {
            id: 'block:video',
            category: 'confirmation',
            isSatisfied: () => confirmed,
            message: 'Confirm that you have watched the video to continue.',
        });

        expect(registry.evaluate(PAGE, 'confirm_video', { shown: true }).satisfied).toBe(false);

        confirmed = true;

        expect(registry.evaluate(PAGE, 'confirm_video', { shown: true }).satisfied).toBe(true);
    });

    it('replaces a re-registered id in place rather than appending it', () => {
        const registry = createCompletionRegistry();

        registry.register(PAGE, { id: 'a', category: 'response', isSatisfied: () => false, message: 'A' });
        registry.register(PAGE, { id: 'b', category: 'response', isSatisfied: () => false, message: 'B' });
        registry.register(PAGE, { id: 'a', category: 'response', isSatisfied: () => false, message: 'A again' });

        expect(registry.contributors(PAGE).map((c) => c.id)).toEqual(['a', 'b']);
        expect(registry.evaluate(PAGE, 'submit_required', { shown: true }).message).toBe('A again');
    });

    it('removes a contributor through the handle returned by register', () => {
        const registry = createCompletionRegistry();

        const remove = registry.register(PAGE, contributor('response', false));

        expect(registry.evaluate(PAGE, 'submit_required', { shown: true }).satisfied).toBe(false);

        remove();

        expect(registry.contributors(PAGE)).toEqual([]);
        expect(registry.evaluate(PAGE, 'submit_required', { shown: true }).satisfied).toBe(true);
    });

    it('rejects a contributor that cannot be evaluated', () => {
        const registry = createCompletionRegistry();

        expect(() => registry.register(PAGE, { category: 'response', isSatisfied: () => true })).toThrow(
            /non-empty string id/,
        );

        expect(() =>
            registry.register(PAGE, { id: 'x', category: 'decorative', isSatisfied: () => true }),
        ).toThrow(/Unknown completion category/);

        expect(() => registry.register(PAGE, { id: 'x', category: 'response' })).toThrow(
            /isSatisfied/,
        );
    });
});
