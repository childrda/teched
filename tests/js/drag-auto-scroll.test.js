import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { createDragAutoScroll } from '../../resources/js/lesson-player/drag-auto-scroll.js';

describe('drag edge auto-scroll', () => {
    let scrollBy;
    let listeners;
    let rafCallbacks;
    let nextRafId;
    let matchMediaMatches;
    let api;
    let detach;

    function documentStub() {
        listeners = new Map();

        return {
            addEventListener: (type, handler, options) => {
                const key = `${type}:${options === true ? 'capture' : ''}`;
                listeners.set(key, handler);
            },
            removeEventListener: (type, handler, options) => {
                const key = `${type}:${options === true ? 'capture' : ''}`;

                if (listeners.get(key) === handler) {
                    listeners.delete(key);
                }
            },
        };
    }

    function fire(type, event = {}) {
        const handler = listeners.get(`${type}:capture`);

        handler?.(event);
    }

    function flushRaf() {
        const callbacks = [...rafCallbacks];
        rafCallbacks = [];

        for (const callback of callbacks) {
            callback();
        }
    }

    beforeEach(() => {
        scrollBy = vi.fn();
        rafCallbacks = [];
        nextRafId = 1;
        matchMediaMatches = false;

        api = createDragAutoScroll({
            edgeZone: 100,
            minVelocity: 4,
            maxVelocity: 20,
            reducedVelocity: 6,
            scrollBy,
            matchMedia: () => ({ matches: matchMediaMatches }),
            document: documentStub(),
            window: {
                innerHeight: 768,
                innerWidth: 1366,
                requestAnimationFrame: (callback) => {
                    const id = nextRafId;
                    nextRafId += 1;
                    rafCallbacks.push(callback);

                    return id;
                },
                cancelAnimationFrame: (id) => {
                    // Drop queued callbacks when cancelled mid-test.
                    void id;
                    rafCallbacks = [];
                },
            },
        });

        detach = api.attach();
    });

    afterEach(() => {
        detach?.();
    });

    it('starts a scroll loop when the pointer enters the bottom edge during a drag', () => {
        fire('dragstart', {});
        fire('dragover', { clientY: 750, clientX: 400 });

        expect(api.isScrolling()).toBe(true);
        expect(api.velocity()).toBeGreaterThan(0);

        flushRaf();

        expect(scrollBy).toHaveBeenCalled();
        expect(scrollBy.mock.calls[0][1]).toBeGreaterThan(0);
    });

    it('starts a scroll loop when the pointer enters the top edge during a drag', () => {
        fire('dragstart', {});
        fire('dragover', { clientY: 20, clientX: 400 });

        expect(api.isScrolling()).toBe(true);
        expect(api.velocity()).toBeLessThan(0);

        flushRaf();

        expect(scrollBy.mock.calls[0][1]).toBeLessThan(0);
    });

    it('stops the loop when the pointer leaves the edge zone', () => {
        fire('dragstart', {});
        fire('dragover', { clientY: 750, clientX: 400 });
        expect(api.isScrolling()).toBe(true);

        fire('dragover', { clientY: 400, clientX: 400 });

        expect(api.isScrolling()).toBe(false);
        expect(api.velocity()).toBe(0);

        const before = scrollBy.mock.calls.length;
        flushRaf();
        expect(scrollBy.mock.calls.length).toBe(before);
    });

    it('cancels on drop', () => {
        fire('dragstart', {});
        fire('dragover', { clientY: 750, clientX: 400 });
        fire('drop', {});

        expect(api.isScrolling()).toBe(false);
        expect(api.velocity()).toBe(0);
    });

    it('cancels on dragend', () => {
        fire('dragstart', {});
        fire('dragover', { clientY: 750, clientX: 400 });
        fire('dragend', {});

        expect(api.isScrolling()).toBe(false);
        expect(api.velocity()).toBe(0);
    });

    it('cancels on Escape', () => {
        fire('dragstart', {});
        fire('dragover', { clientY: 750, clientX: 400 });
        fire('keydown', { key: 'Escape' });

        expect(api.isScrolling()).toBe(false);
        expect(api.velocity()).toBe(0);
    });

    it('cancels when dragleave reports coordinates outside the window', () => {
        fire('dragstart', {});
        fire('dragover', { clientY: 750, clientX: 400 });
        fire('dragleave', { clientY: -1, clientX: 400 });

        expect(api.isScrolling()).toBe(false);
    });

    it('scales velocity with proximity to the edge', () => {
        fire('dragstart', {});

        fire('dragover', { clientY: 720, clientX: 400 }); // near boundary of 100px zone
        const nearBoundary = api.velocity();

        fire('dragover', { clientY: 767, clientX: 400 }); // at extreme
        const atExtreme = api.velocity();

        expect(nearBoundary).toBeGreaterThan(0);
        expect(atExtreme).toBeGreaterThan(nearBoundary);
        expect(atExtreme).toBeLessThanOrEqual(20);
    });

    it('uses a constant gentler velocity under prefers-reduced-motion', () => {
        matchMediaMatches = true;
        fire('dragstart', {});
        fire('dragover', { clientY: 767, clientX: 400 });

        expect(api.velocity()).toBe(6);

        fire('dragover', { clientY: 10, clientX: 400 });
        expect(api.velocity()).toBe(-6);
    });

    it('does not start scrolling without an active drag', () => {
        fire('dragover', { clientY: 750, clientX: 400 });

        expect(api.isScrolling()).toBe(false);
        expect(scrollBy).not.toHaveBeenCalled();
    });

    it('never leaves a scroll loop running after the drag ends', () => {
        fire('dragstart', {});
        fire('dragover', { clientY: 750, clientX: 400 });
        flushRaf();
        fire('dragend', {});

        expect(api.isScrolling()).toBe(false);

        const before = scrollBy.mock.calls.length;
        flushRaf();
        expect(scrollBy.mock.calls.length).toBe(before);
    });
});
