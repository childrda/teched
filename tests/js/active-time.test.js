import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { createActiveTimeTracker } from '../../resources/js/lesson-player/active-time.js';

describe('createActiveTimeTracker', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-07-29T12:00:00Z'));

    globalThis.document = {
      visibilityState: 'visible',
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    };
  });

  afterEach(() => {
    vi.useRealTimers();
    delete globalThis.document;
  });

  it('pauses when hidden and after idle, then resumes', () => {
    const tracker = createActiveTimeTracker({
      attemptId: 1,
      getCsrf: () => 'token',
      fetchImpl: vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) }),
      now: () => Date.now(),
    });

    tracker.start();
    vi.advanceTimersByTime(3000);
    tracker._closeSegment();

    expect(tracker._getAccumulated()).toBe(3);

    document.visibilityState = 'hidden';
    tracker._openSegment();
    expect(tracker._getAccumulated()).toBe(3);

    document.visibilityState = 'visible';
    tracker._setLastInteractionAt(Date.now() - tracker.IDLE_MS - 1);
    tracker._openSegment();
    expect(tracker._getAccumulated()).toBe(3);

    tracker.noteInteraction();
    tracker._openSegment();
    vi.advanceTimersByTime(2000);
    tracker._closeSegment();

    expect(tracker._getAccumulated()).toBe(5);

    tracker.destroy();
  });
});
