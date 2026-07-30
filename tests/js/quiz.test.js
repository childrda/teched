import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { isGradingEnvelope, isPublicResult, quizActivity } from '../../resources/js/lesson-player/quiz.js';

const QUESTIONS = [
    {
        id: 'q1',
        prompt: 'One?',
        options: [
            { id: 'q1-a', text: 'A' },
            { id: 'q1-b', text: 'B' },
        ],
    },
    {
        id: 'q2',
        prompt: 'Two?',
        options: [
            { id: 'q2-a', text: 'A' },
            { id: 'q2-b', text: 'B' },
        ],
    },
];

const STRINGS = {
    gate: 'Submit the quiz to continue.',
    gate_pass: 'Pass the quiz to continue.',
    submit: 'Submit answers',
    submitting: 'Checking your answers…',
    retry: 'Try again',
    answer_every: 'Answer every question before submitting.',
    score: 'Score: :score of :max (:percentage%)',
    passed: 'Passed',
    failed: 'Not yet passed',
    no_attempts_remaining: 'No attempts remain. Ask your teacher to grant more so you can try again.',
    submit_unavailable: 'Submit is unavailable — no attempts remain. Ask your teacher to grant more.',
    error: 'Something went wrong checking your answers. Please try again.',
};

function publicResult(overrides = {}) {
    return {
        score: 1,
        max_score: 2,
        percentage: 50,
        passed: false,
        requires_manual_review: false,
        reveal: null,
        ...overrides,
    };
}

function envelope(resultOverrides = {}, attemptsOverrides = {}) {
    return {
        result: publicResult(resultOverrides),
        attempts: {
            used: 1,
            allowed: 3,
            remaining: 2,
            ...attemptsOverrides,
        },
    };
}

function activity(overrides = {}) {
    const component = quizActivity({
        blockId: 'QUIZ-1',
        pageId: 'PAGE-1',
        completionType: 'complete_activity',
        shuffle: false,
        questions: QUESTIONS,
        strings: STRINGS,
        ...overrides,
    });

    const playerEl = {
        dataset: { lessonCode: 'WEL-6.1.1' },
    };

    component.$el = {
        closest: (selector) => (selector === '[data-lesson-code]' ? playerEl : null),
    };
    component.$root = {
        querySelector: () => ({ focus: () => {} }),
    };
    component.$nextTick = (callback) => callback();

    globalThis.window = {
        Alpine: {
            $data: () => ({
                manifest: { grading_token: 'TOKEN-1' },
            }),
        },
    };

    globalThis.document = {
        querySelector: (selector) =>
            selector === 'meta[name="csrf-token"]'
                ? { getAttribute: () => 'csrf-test' }
                : null,
    };

    return component;
}

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: true,
            json: async () => envelope({ passed: true, score: 2, percentage: 100 }),
        })),
    );
});

afterEach(() => {
    vi.unstubAllGlobals();
    delete globalThis.window;
    delete globalThis.document;
});

describe('incomplete submit', () => {
    it('fires no request and announces when a question is unanswered', async () => {
        const component = activity();

        component.answers.q1 = 'q1-a';

        await component.submit();

        expect(fetch).not.toHaveBeenCalled();
        expect(component.announcement).toBe(STRINGS.answer_every);
        expect(component.attemptCount).toBe(0);
        expect(component.latestResult).toBeNull();
    });
});

describe('successful grading', () => {
    it('posts exactly version_token and response', async () => {
        const component = activity();

        component.answers.q1 = 'q1-a';
        component.answers.q2 = 'q2-b';

        await component.submit();

        expect(fetch).toHaveBeenCalledOnce();

        const [url, options] = fetch.mock.calls[0];

        expect(url).toBe('/player/lessons/WEL-6.1.1/blocks/QUIZ-1/grade');
        expect(options.method).toBe('POST');
        expect(options.headers['X-CSRF-TOKEN']).toBe('csrf-test');

        expect(JSON.parse(options.body)).toEqual({
            version_token: 'TOKEN-1',
            response: { q1: 'q1-a', q2: 'q2-b' },
        });
    });

    it('advances attemptCount, firstResult, and latestResult across two submissions', async () => {
        const component = activity();

        component.answers.q1 = 'q1-a';
        component.answers.q2 = 'q2-b';

        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => envelope({ score: 1, percentage: 50, passed: false }, { used: 1, remaining: 2 }),
        });

        await component.submit();

        expect(component.attemptCount).toBe(1);
        expect(component.firstResult).toEqual(publicResult({ score: 1, percentage: 50, passed: false }));
        expect(component.latestResult.passed).toBe(false);

        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => envelope({ score: 2, percentage: 100, passed: true }, { used: 2, remaining: 1 }),
        });

        await component.submit();

        expect(component.attemptCount).toBe(2);
        expect(component.firstResult.score).toBe(1);
        expect(component.latestResult).toEqual(publicResult({ score: 2, percentage: 100, passed: true }));
    });
});

describe('failed or malformed grading responses', () => {
    it('leaves attempt state untouched on a failed request', async () => {
        const component = activity();

        component.answers.q1 = 'q1-a';
        component.answers.q2 = 'q2-b';

        fetch.mockResolvedValueOnce({
            ok: false,
            status: 500,
            json: async () => ({ message: 'nope' }),
        });

        await component.submit();

        expect(component.attemptCount).toBe(0);
        expect(component.firstResult).toBeNull();
        expect(component.latestResult).toBeNull();
        expect(component.error).toBe(STRINGS.error);
    });

    it('treats a 200 whose body is not a grading envelope as an error', async () => {
        const component = activity();

        component.answers.q1 = 'q1-a';
        component.answers.q2 = 'q2-b';

        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ score: 2, max_score: 2, details: [] }),
        });

        await component.submit();

        expect(component.attemptCount).toBe(0);
        expect(component.firstResult).toBeNull();
        expect(component.latestResult).toBeNull();
        expect(component.error).toBe(STRINGS.error);
    });
});

describe('blocked state', () => {
    it('hides submit, announces once, and does not call fetch', async () => {
        const component = activity();

        component.answers.q1 = 'q1-a';
        component.answers.q2 = 'q2-b';
        component.attemptsInfo = { used: 2, allowed: 2, remaining: 0 };
        component.latestResult = publicResult({ passed: false });

        expect(component.isBlocked).toBe(true);

        await component.submit();

        expect(fetch).not.toHaveBeenCalled();
        expect(component.error).toBe(STRINGS.submit_unavailable);

        component.announceBlockedOnce();
        const first = component.announcement;
        component.announceBlockedOnce();
        expect(component.announcement).toBe(first);
    });

    it('does not disturb autosave while blocked — onAnswer is a no-op', () => {
        const queueSave = vi.fn();
        const component = activity();

        component.attemptsInfo = { used: 2, allowed: 2, remaining: 0 };
        component.latestResult = publicResult({ passed: false });
        component.player = () => ({ queueSave });

        component.onAnswer();

        expect(queueSave).not.toHaveBeenCalled();
    });
});

describe('completion contributor', () => {
    it('is unsatisfied before grading and satisfied after', async () => {
        const component = activity();
        const contributor = component.contributor();

        expect(contributor.category).toBe('gradable');
        expect(contributor.isSatisfied()).toBe(false);
        expect(contributor.isPassed()).toBe(false);

        component.answers.q1 = 'q1-a';
        component.answers.q2 = 'q2-b';

        await component.submit();

        expect(contributor.isSatisfied()).toBe(true);
        expect(contributor.isPassed()).toBe(true);
    });

    it('isPassed follows latestResult.passed', () => {
        const component = activity();

        component.latestResult = publicResult({ passed: false });
        expect(component.contributor().isPassed()).toBe(false);

        component.latestResult = publicResult({ passed: true });
        expect(component.contributor().isPassed()).toBe(true);
    });
});

describe('isPublicResult', () => {
    it('accepts the six-key shape and rejects five keys, seven keys, and malformed reveal', () => {
        expect(isPublicResult(publicResult())).toBe(true);
        expect(isPublicResult({
            score: 1,
            max_score: 1,
            percentage: 100,
            passed: true,
            requires_manual_review: false,
        })).toBe(false);
        expect(isPublicResult({ ...publicResult(), details: [] })).toBe(false);
        expect(isPublicResult({
            ...publicResult(),
            reveal: { trigger: 'passed' },
        })).toBe(false);
        expect(isPublicResult(null)).toBe(false);
    });
});

describe('isGradingEnvelope', () => {
    it('requires result and attempts only', () => {
        expect(isGradingEnvelope(envelope())).toBe(true);
        expect(isGradingEnvelope(publicResult())).toBe(false);
        expect(isGradingEnvelope({ result: publicResult(), attempts: { used: 1 } })).toBe(false);
    });
});
