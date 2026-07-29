import { beforeAll, beforeEach, describe, expect, it } from 'vitest';

import { lessonPlayer } from '../../resources/js/lesson-player/player.js';

// The component is exercised without Alpine, so the few browser and Alpine
// affordances it reaches for are stubbed here.
beforeAll(() => {
    globalThis.window = { scrollTo() {} };
});

function manifest(pages) {
    return { schema_version: 1, code: 'WEL-6.1.1', title: 'What Is Welding?', pages };
}

function page(overrides = {}) {
    return {
        page_id: 'PAGE-1',
        title: 'Welcome',
        position: 1,
        completion_type: 'view',
        settings: {
            allow_back_navigation: true,
            allow_skip: false,
            allow_read_aloud: true,
        },
        blocks: [],
        ...overrides,
    };
}

function makePlayer(pages) {
    const player = lessonPlayer(manifest(pages));

    player.$nextTick = (callback) => callback();
    player.$root = { querySelector: () => null, querySelectorAll: () => [] };
    player.$refs = {};

    return player;
}

describe('navigation', () => {
    let player;

    beforeEach(() => {
        player = makePlayer([
            page({ page_id: 'P1', title: 'One' }),
            page({ page_id: 'P2', title: 'Two', position: 2 }),
            page({ page_id: 'P3', title: 'Three', position: 3 }),
        ]);
    });

    it('starts on the first page', () => {
        expect(player.currentIndex).toBe(0);
        expect(player.totalPages).toBe(3);
        expect(player.isLastPage).toBe(false);
        expect(player.canGoBack).toBe(false);
        expect(player.progressPercent).toBe(33);
    });

    it('moves forward and back', () => {
        player.goForward();

        expect(player.currentIndex).toBe(1);
        expect(player.canGoBack).toBe(true);

        player.goBack();

        expect(player.currentIndex).toBe(0);
    });

    it('announces the page it moved to', () => {
        player.goForward();

        expect(player.announcement).toBe('Page 2 of 3: Two');
    });

    it('stops at the last page', () => {
        player.goTo(2);

        expect(player.isLastPage).toBe(true);

        player.goForward();

        expect(player.currentIndex).toBe(2);
    });

    it('hides Back when the author turned back navigation off', () => {
        const locked = makePlayer([
            page({ page_id: 'P1' }),
            page({
                page_id: 'P2',
                position: 2,
                settings: { allow_back_navigation: false, allow_skip: false },
            }),
        ]);

        locked.goForward();

        expect(locked.currentIndex).toBe(1);
        expect(locked.canGoBack).toBe(false);

        locked.goBack();

        expect(locked.currentIndex).toBe(1);
    });
});

describe('the Continue gate', () => {
    function videoPagePlayer() {
        const player = makePlayer([
            page({
                page_id: 'P1',
                completion_type: 'confirm_video',
                blocks: [{ block_id: 'B1', type: 'video', config: {}, speech: [] }],
            }),
            page({ page_id: 'P2', position: 2, title: 'Next' }),
        ]);

        return player;
    }

    it('is open on a view page with no contributors', () => {
        const player = makePlayer([page(), page({ page_id: 'P2', position: 2 })]);

        expect(player.canContinue).toBe(true);
        expect(player.gateMessage).toBe('');
    });

    it('closes while a video confirmation is outstanding, then opens', () => {
        const player = videoPagePlayer();
        let confirmed = false;

        player.registerContributor('P1', {
            id: 'video-confirmation:B1',
            category: 'confirmation',
            isSatisfied: () => confirmed,
            message: 'Confirm that you have watched the video to continue.',
        });

        expect(player.canContinue).toBe(false);
        expect(player.gateMessage).toBe('Confirm that you have watched the video to continue.');

        player.goForward();

        // Blocked, and the reason was announced.
        expect(player.currentIndex).toBe(0);
        expect(player.announcement).toBe('Confirm that you have watched the video to continue.');

        confirmed = true;

        expect(player.canContinue).toBe(true);
        expect(player.gateMessage).toBe('');

        player.goForward();

        expect(player.currentIndex).toBe(1);
    });

    it('registers a contributor without handing back anything callable', () => {
        // Alpine invokes whatever an expression evaluates to, so returning the
        // registry's remove handle here would undo the registration at once.
        const player = videoPagePlayer();

        const returned = player.registerContributor('P1', {
            id: 'video-confirmation:B1',
            category: 'confirmation',
            isSatisfied: () => false,
            message: 'Confirm first.',
        });

        expect(returned).toBeUndefined();
        expect(player.registryVersion).toBe(1);
        expect(player.completion.contributors('P1')).toHaveLength(1);
        expect(player.canContinue).toBe(false);
    });
});

describe('skipping', () => {
    it('is offered only where the author allowed it, and satisfies nothing', () => {
        const player = makePlayer([
            page({
                page_id: 'P1',
                completion_type: 'confirm_video',
                settings: { allow_back_navigation: true, allow_skip: true },
            }),
            page({ page_id: 'P2', position: 2 }),
        ]);

        player.registerContributor('P1', {
            id: 'video-confirmation:B1',
            category: 'confirmation',
            isSatisfied: () => false,
            message: 'Confirm that you have watched the video to continue.',
        });

        expect(player.allowSkip).toBe(true);
        expect(player.canContinue).toBe(false);

        player.skip();

        expect(player.currentIndex).toBe(1);

        // Nothing was marked satisfied on the page that was skipped.
        expect(player.completion.evaluate('P1', 'confirm_video', { shown: true }).satisfied).toBe(
            false,
        );
    });

    it('is not offered when the author did not allow it', () => {
        const player = makePlayer([page(), page({ page_id: 'P2', position: 2 })]);

        expect(player.allowSkip).toBe(false);

        player.skip();

        expect(player.currentIndex).toBe(0);
    });
});

describe('read-aloud segments', () => {
    it('are found by block id across the whole lesson', () => {
        const segments = [{ id: 'html:0', label: null, text: 'Hello' }];

        const player = makePlayer([
            page({ page_id: 'P1' }),
            page({
                page_id: 'P2',
                position: 2,
                blocks: [{ block_id: 'B9', type: 'rich_text', config: {}, speech: segments }],
            }),
        ]);

        expect(player.speechSegmentsFor('B9')).toEqual(segments);
        expect(player.speechSegmentsFor('nope')).toEqual([]);
    });

    it('reports nothing as being read before speech starts', () => {
        const player = makePlayer([page()]);

        expect(player.isReading('B1')).toBe(false);
    });
});
