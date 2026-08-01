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

function makePlayer(pages, attempt = null, capabilities = null) {
    const player = lessonPlayer(manifest(pages), attempt, capabilities);

    player.$nextTick = (callback) => callback();
    player.$root = { querySelector: () => null, querySelectorAll: () => [] };
    player.$refs = {};

    return player;
}

function initPlayer(pages, attempt = null, capabilities = null) {
    const player = makePlayer(pages, attempt, capabilities);

    player.init();

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

describe('numbered page breadcrumb', () => {
    const threePages = () => [
        page({ page_id: 'P1', title: 'One' }),
        page({ page_id: 'P2', title: 'Two', position: 2 }),
        page({
            page_id: 'P3',
            title: 'Three',
            position: 3,
            settings: { allow_back_navigation: true, allow_skip: false },
        }),
    ];

    it('seeds furthestIndexReached from the restored current page on an active attempt', () => {
        const player = initPlayer(threePages(), {
            id: 1,
            status: 'in_progress',
            current_page_id: 'P2',
            revision: 1,
            block_states: [],
        }, {
            // Keep persist off so init() does not touch document/localStorage;
            // furthest seeding only depends on restore + gate flags.
            canPersist: false,
            canGrade: true,
            canAdvancePersistently: true,
            bypassCompletionGates: false,
        });

        expect(player.currentIndex).toBe(1);
        expect(player.furthestIndexReached).toBe(1);
    });

    it('marks every page reached for a completed read-only attempt', () => {
        const player = initPlayer(threePages(), {
            id: 1,
            status: 'completed',
            read_only: true,
            current_page_id: 'P3',
            revision: 3,
            block_states: [],
        }, {
            canPersist: false,
            canGrade: false,
            canAdvancePersistently: false,
            bypassCompletionGates: false,
        });

        expect(player.readOnly).toBe(true);
        expect(player.furthestIndexReached).toBe(2);
        expect(player.canGoToBreadcrumb(0)).toBe(true);
        expect(player.canGoToBreadcrumb(2)).toBe(true);
    });

    it('opens every page for preview (bypassCompletionGates)', () => {
        const player = initPlayer(threePages(), {
            current_page_id: 'P1',
            status: 'in_progress',
            read_only: false,
        }, {
            canPersist: false,
            canGrade: false,
            canAdvancePersistently: false,
            bypassCompletionGates: true,
        });

        expect(player.furthestIndexReached).toBe(2);
        expect(player.canGoToBreadcrumb(2)).toBe(true);
    });

    it('renders no reached index when the lesson has zero pages', () => {
        const player = initPlayer([]);

        expect(player.totalPages).toBe(0);
        expect(player.furthestIndexReached).toBe(-1);
        expect(player.pageIndexes).toEqual([]);
        expect(player.canGoToBreadcrumb(0)).toBe(false);
    });

    it('navigates to an already-reached page via goToBreadcrumb', async () => {
        const player = makePlayer(threePages());

        await player.goForward();
        await player.goForward();

        expect(player.furthestIndexReached).toBe(2);

        player.goToBreadcrumb(0);

        expect(player.currentIndex).toBe(0);
    });

    it('refuses an unreached page even when goToBreadcrumb is called directly', async () => {
        const player = makePlayer(threePages());

        await player.goForward();

        expect(player.furthestIndexReached).toBe(1);

        player.goToBreadcrumb(2);

        expect(player.currentIndex).toBe(1);
        expect(player.furthestIndexReached).toBe(1);
    });

    it('does not advance furthestIndexReached from goTo or goBack', async () => {
        const player = makePlayer(threePages());

        await player.goForward();

        expect(player.furthestIndexReached).toBe(1);

        player.goTo(0);
        expect(player.furthestIndexReached).toBe(1);

        player.goBack();
        expect(player.currentIndex).toBe(0);
        expect(player.furthestIndexReached).toBe(1);

        // Raw goTo past the watermark still moves (generic jump) but must not
        // widen the breadcrumb's earned range.
        player.goTo(2);
        expect(player.currentIndex).toBe(2);
        expect(player.furthestIndexReached).toBe(1);
    });

    it('advances furthestIndexReached after a local goForward', async () => {
        const player = makePlayer(threePages());

        expect(player.furthestIndexReached).toBe(0);

        await player.goForward();

        expect(player.currentIndex).toBe(1);
        expect(player.furthestIndexReached).toBe(1);
    });

    it('blocks backward breadcrumb targets when allow_back_navigation is false', async () => {
        const player = makePlayer([
            page({ page_id: 'P1' }),
            page({
                page_id: 'P2',
                position: 2,
                settings: { allow_back_navigation: false, allow_skip: false },
            }),
            page({ page_id: 'P3', position: 3 }),
        ]);

        await player.goForward();
        await player.goForward();

        // Step back to P2 via goTo (simulating having been there), then lock.
        player.goTo(1);

        expect(player.currentIndex).toBe(1);
        expect(player.furthestIndexReached).toBe(2);
        expect(player.canGoToBreadcrumb(0)).toBe(false);
        expect(player.canGoToBreadcrumb(2)).toBe(true);

        player.goToBreadcrumb(0);
        expect(player.currentIndex).toBe(1);

        player.goToBreadcrumb(2);
        expect(player.currentIndex).toBe(2);
    });

    it('no-ops when the current page breadcrumb is activated', async () => {
        const player = makePlayer(threePages());

        await player.goForward();

        expect(player.currentIndex).toBe(1);
        expect(player.canGoToBreadcrumb(1)).toBe(true);

        const sideEffects = [];

        player.announce = (message) => sideEffects.push(message);
        player.focusPageHeading = () => sideEffects.push('focus');
        player.scrollToTop = () => sideEffects.push('scroll');

        player.goToBreadcrumb(1);

        expect(player.currentIndex).toBe(1);
        expect(sideEffects).toEqual([]);
    });

    it('does not treat unreached pages as activatable', () => {
        const player = makePlayer(threePages());

        expect(player.isBreadcrumbReached(0)).toBe(true);
        expect(player.isBreadcrumbReached(1)).toBe(false);
        expect(player.canGoToBreadcrumb(1)).toBe(false);
        expect(player.isBreadcrumbCurrent(0)).toBe(true);
    });

    it('advances furthestIndexReached when the author allows skip', () => {
        const player = makePlayer([
            page({
                page_id: 'P1',
                settings: { allow_back_navigation: true, allow_skip: true },
            }),
            page({ page_id: 'P2', position: 2 }),
        ]);

        player.skip();

        expect(player.currentIndex).toBe(1);
        expect(player.furthestIndexReached).toBe(1);
        expect(player.canGoToBreadcrumb(1)).toBe(true);
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

    /**
     * The marker is applied imperatively, so these stand in for the elements
     * it reaches — enough of one to record what was done to it, and no more.
     * The suite stays on the node environment it was written for.
     */
    function fakeElement() {
        const element = {
            classes: new Set(),
            attributes: {},
            children: [],
            classList: {
                add: (name) => element.classes.add(name),
                remove: (name) => element.classes.delete(name),
                contains: (name) => element.classes.has(name),
            },
            setAttribute: (name, value) => {
                element.attributes[name] = value;
            },
            removeAttribute: (name) => {
                delete element.attributes[name];
            },
            append: (child) => element.children.push(child),
            querySelectorAll: (selector) => element.children.filter(
                (child) => selector.includes(child.className.split(' ').pop()),
            ),
        };

        return element;
    }

    function playerWithSegment(element) {
        const player = makePlayer([page()]);

        // Every selector this component builds resolves to the one element
        // under test, except the sweeps, which are matched on class.
        player.$root = {
            querySelector: () => null,
            querySelectorAll: (selector) => {
                if (selector.startsWith('[data-speech-id].')) {
                    const wanted = selector.split('.').pop();

                    return element.classes.has(wanted) ? [element] : [];
                }

                return [element];
            },
        };

        return player;
    }

    beforeEach(() => {
        globalThis.document = {
            createElement: () => {
                const node = { className: '', textContent: '', remove: null };

                node.remove = () => {
                    node.removed = true;
                };

                return node;
            },
        };
    });

    it('marks the resume point with a hidden label rather than aria-current', () => {
        const element = fakeElement();
        const player = playerWithSegment(element);

        player.markResumePoint('B1', 'html:1');

        expect(element.classes.has('speech-resume-marker')).toBe(true);
        expect(element.attributes['aria-current']).toBeUndefined();
        expect(element.children).toHaveLength(1);
        expect(element.children[0].textContent).toBe('Reading paused here');
        expect(element.children[0].className).toContain('sr-only');
    });

    it('clears the class and its label together, never one alone', () => {
        const element = fakeElement();
        const player = playerWithSegment(element);

        player.markResumePoint('B1', 'html:1');
        player.markResumePoint(null, null);

        expect(element.classes.has('speech-resume-marker')).toBe(false);
        expect(element.children.filter((child) => ! child.removed)).toHaveLength(0);
    });

    it('never leaves a second marker in the document', () => {
        const element = fakeElement();
        const player = playerWithSegment(element);

        player.markResumePoint('B1', 'html:1');
        player.markResumePoint('B1', 'html:0');

        expect(element.classes.has('speech-resume-marker')).toBe(true);
        expect(element.children.filter((child) => ! child.removed)).toHaveLength(1);
    });

    it('drops the marker from a segment that starts speaking', () => {
        const element = fakeElement();
        const player = playerWithSegment(element);

        player.markResumePoint('B1', 'html:1');
        player.highlightSegment('B1', 'html:1');

        expect(element.classes.has('is-speaking')).toBe(true);
        expect(element.classes.has('speech-resume-marker')).toBe(false);
        expect(element.children.filter((child) => ! child.removed)).toHaveLength(0);
        expect(element.attributes['aria-current']).toBe('true');
    });

    it('reports a resume point only for the block that owns it', () => {
        const player = makePlayer([page()]);

        expect(player.hasResumePoint('B1')).toBe(false);

        player.speech.resumeBlockId = 'B1';
        player.speech.resumeSegmentId = 'html:1';

        expect(player.hasResumePoint('B1')).toBe(true);
        expect(player.hasResumePoint('B2')).toBe(false);
    });

    it('asks to resume only where a position was remembered', () => {
        const calls = [];
        const player = makePlayer([page()]);

        player.speechController = { speak: (...args) => calls.push(args) };

        player.toggleReadAloud('B1');

        player.speech.resumeBlockId = 'B1';
        player.speech.resumeSegmentId = 'html:1';
        player.toggleReadAloud('B1');

        // Start over ignores the remembered position on purpose.
        player.startOverReading('B1');

        expect(calls.map((call) => call[2])).toEqual([null, 'html:1', undefined]);
    });
});
