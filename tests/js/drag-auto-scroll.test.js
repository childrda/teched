import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { createDragAutoScroll } from '../../resources/js/lesson-player/drag-auto-scroll.js';

describe('drag edge auto-scroll', () => {
    let scrollElement;
    let listeners;
    let rafCallbacks;
    let nextRafId;
    let matchMediaMatches;
    let measureInsets;
    let documentScroller;
    let api;
    let detach;

    function documentStub() {
        listeners = new Map();

        return {
            scrollingElement: documentScroller,
            documentElement: documentScroller,
            body: documentScroller,
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

    function makeScroller(overrides = {}) {
        return {
            nodeType: 1,
            parentElement: null,
            scrollTop: 0,
            clientHeight: 200,
            scrollHeight: 800,
            overflowY: 'auto',
            getBoundingClientRect: () => ({ top: 100, bottom: 300, height: 200 }),
            ...overrides,
        };
    }

    beforeEach(() => {
        scrollElement = vi.fn();
        rafCallbacks = [];
        nextRafId = 1;
        matchMediaMatches = false;
        measureInsets = vi.fn(() => ({ top: 0, bottom: 0 }));

        documentScroller = makeScroller({
            scrollTop: 0,
            clientHeight: 768,
            scrollHeight: 4000,
            overflowY: 'auto',
            getBoundingClientRect: () => ({ top: 0, bottom: 768, height: 768 }),
        });

        api = createDragAutoScroll({
            edgeZone: 100,
            minVelocity: 4,
            maxVelocity: 20,
            reducedVelocity: 6,
            measureInsets,
            scrollElement,
            getOverflowY: (el) => el.overflowY ?? 'auto',
            getScrollMetrics: (el) => ({
                scrollTop: el.scrollTop,
                clientHeight: el.clientHeight,
                scrollHeight: el.scrollHeight,
            }),
            getClientRect: (el) => el.getBoundingClientRect(),
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
                cancelAnimationFrame: () => {
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
        fire('dragover', { clientY: 750, clientX: 400, target: documentScroller });

        expect(api.isScrolling()).toBe(true);
        expect(api.velocity()).toBeGreaterThan(0);

        flushRaf();

        expect(scrollElement).toHaveBeenCalled();
        expect(scrollElement.mock.calls[0][0]).toBe(documentScroller);
        expect(scrollElement.mock.calls[0][2]).toBeGreaterThan(0);
    });

    it('starts a scroll loop when the pointer enters the top edge during a drag', () => {
        documentScroller.scrollTop = 200;
        fire('dragstart', {});
        fire('dragover', { clientY: 20, clientX: 400, target: documentScroller });

        expect(api.isScrolling()).toBe(true);
        expect(api.velocity()).toBeLessThan(0);

        flushRaf();

        expect(scrollElement.mock.calls[0][2]).toBeLessThan(0);
    });

    it('stops the loop when the pointer leaves the edge zone', () => {
        fire('dragstart', {});
        fire('dragover', { clientY: 750, clientX: 400, target: documentScroller });
        expect(api.isScrolling()).toBe(true);

        fire('dragover', { clientY: 400, clientX: 400, target: documentScroller });

        expect(api.isScrolling()).toBe(false);
        expect(api.velocity()).toBe(0);

        const before = scrollElement.mock.calls.length;
        flushRaf();
        expect(scrollElement.mock.calls.length).toBe(before);
    });

    it('cancels on drop', () => {
        fire('dragstart', {});
        fire('dragover', { clientY: 750, clientX: 400, target: documentScroller });
        fire('drop', {});

        expect(api.isScrolling()).toBe(false);
        expect(api.velocity()).toBe(0);
    });

    it('cancels on dragend', () => {
        fire('dragstart', {});
        fire('dragover', { clientY: 750, clientX: 400, target: documentScroller });
        fire('dragend', {});

        expect(api.isScrolling()).toBe(false);
        expect(api.velocity()).toBe(0);
    });

    it('cancels on Escape', () => {
        fire('dragstart', {});
        fire('dragover', { clientY: 750, clientX: 400, target: documentScroller });
        fire('keydown', { key: 'Escape' });

        expect(api.isScrolling()).toBe(false);
        expect(api.velocity()).toBe(0);
    });

    it('cancels when dragleave reports coordinates outside the window', () => {
        fire('dragstart', {});
        fire('dragover', { clientY: 750, clientX: 400, target: documentScroller });
        fire('dragleave', { clientY: -1, clientX: 400 });

        expect(api.isScrolling()).toBe(false);
    });

    it('scales velocity with proximity to the edge', () => {
        fire('dragstart', {});

        fire('dragover', { clientY: 720, clientX: 400, target: documentScroller });
        const nearBoundary = api.velocity();

        fire('dragover', { clientY: 767, clientX: 400, target: documentScroller });
        const atExtreme = api.velocity();

        expect(nearBoundary).toBeGreaterThan(0);
        expect(atExtreme).toBeGreaterThan(nearBoundary);
        expect(atExtreme).toBeLessThanOrEqual(20);
    });

    it('uses a constant gentler velocity under prefers-reduced-motion', () => {
        matchMediaMatches = true;
        fire('dragstart', {});
        fire('dragover', { clientY: 767, clientX: 400, target: documentScroller });

        expect(api.velocity()).toBe(6);

        documentScroller.scrollTop = 50;
        fire('dragover', { clientY: 10, clientX: 400, target: documentScroller });
        expect(api.velocity()).toBe(-6);
    });

    it('does not start scrolling without an active drag', () => {
        fire('dragover', { clientY: 750, clientX: 400, target: documentScroller });

        expect(api.isScrolling()).toBe(false);
        expect(scrollElement).not.toHaveBeenCalled();
    });

    it('never leaves a scroll loop running after the drag ends', () => {
        fire('dragstart', {});
        fire('dragover', { clientY: 750, clientX: 400, target: documentScroller });
        flushRaf();
        fire('dragend', {});

        expect(api.isScrolling()).toBe(false);

        const before = scrollElement.mock.calls.length;
        flushRaf();
        expect(scrollElement.mock.calls.length).toBe(before);
    });

    it('shifts the bottom trigger upward by the chrome inset', () => {
        // Usable bottom = 768 - 80 = 688; zone starts at 588.
        measureInsets.mockReturnValue({ top: 0, bottom: 80 });
        fire('dragstart', {});

        fire('dragover', { clientY: 600, clientX: 400, target: documentScroller });
        expect(api.isScrolling()).toBe(true);
        expect(api.velocity()).toBeGreaterThan(0);
    });

    it('does not treat the same coordinate as a bottom edge without the inset', () => {
        measureInsets.mockReturnValue({ top: 0, bottom: 0 });
        fire('dragstart', {});

        // With no inset, zone starts at 668 — 600 is mid-viewport.
        fire('dragover', { clientY: 600, clientX: 400, target: documentScroller });
        expect(api.isScrolling()).toBe(false);
        expect(api.velocity()).toBe(0);
    });

    it('re-measures chrome insets at each dragstart', () => {
        measureInsets.mockReturnValueOnce({ top: 0, bottom: 40 });
        fire('dragstart', {});
        expect(api._insets()).toEqual({ top: 0, bottom: 40 });

        measureInsets.mockReturnValueOnce({ top: 0, bottom: 120 });
        fire('dragend', {});
        fire('dragstart', {});
        expect(api._insets()).toEqual({ top: 0, bottom: 120 });
        expect(measureInsets).toHaveBeenCalledTimes(2);
    });

    it('scrolls a scrollable ancestor under the pointer instead of the document', () => {
        const bank = makeScroller({
            scrollTop: 0,
            clientHeight: 200,
            scrollHeight: 600,
            getBoundingClientRect: () => ({ top: 500, bottom: 700, height: 200 }),
            parentElement: documentScroller,
        });

        fire('dragstart', {});
        // Near the bottom edge of the bank (zone = 100px above 700 → from 600).
        fire('dragover', { clientY: 680, clientX: 400, target: bank });

        expect(api.scrollTarget()).toBe(bank);
        flushRaf();
        expect(scrollElement.mock.calls[0][0]).toBe(bank);
        expect(scrollElement.mock.calls.some((call) => call[0] === documentScroller)).toBe(false);
    });

    it('hands off to the document when the nested scroller is already at its bottom', () => {
        const bank = makeScroller({
            scrollTop: 400,
            clientHeight: 200,
            scrollHeight: 600, // already at bottom
            getBoundingClientRect: () => ({ top: 500, bottom: 700, height: 200 }),
            parentElement: documentScroller,
        });

        fire('dragstart', {});
        // In the bank's bottom zone, but bank has no room — document bottom zone also covers 680.
        fire('dragover', { clientY: 680, clientX: 400, target: bank });

        expect(api.scrollTarget()).toBe(documentScroller);
        flushRaf();
        expect(scrollElement.mock.calls[0][0]).toBe(documentScroller);
    });

    it('uses the document scrolling element when no ancestor qualifies', () => {
        const plain = {
            nodeType: 1,
            parentElement: documentScroller,
            overflowY: 'visible',
            scrollTop: 0,
            clientHeight: 100,
            scrollHeight: 100,
            getBoundingClientRect: () => ({ top: 200, bottom: 300, height: 100 }),
        };

        fire('dragstart', {});
        fire('dragover', { clientY: 750, clientX: 400, target: plain });

        expect(api.scrollTarget()).toBe(documentScroller);
    });

    it('re-resolves the scroll target between dragover events', () => {
        const bank = makeScroller({
            scrollTop: 0,
            clientHeight: 200,
            scrollHeight: 600,
            getBoundingClientRect: () => ({ top: 500, bottom: 700, height: 200 }),
            parentElement: documentScroller,
        });

        fire('dragstart', {});
        fire('dragover', { clientY: 680, clientX: 400, target: bank });
        expect(api.scrollTarget()).toBe(bank);

        // Pointer moves onto the page near the viewport bottom — document takes over.
        fire('dragover', { clientY: 750, clientX: 400, target: documentScroller });
        expect(api.scrollTarget()).toBe(documentScroller);
    });
});
