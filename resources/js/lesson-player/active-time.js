/**
 * Active-time tracking: ticks only while the document is visible and the
 * last interaction was under five minutes ago. The client sends deltas; the
 * server adds them. Label surfaces as "active time", never "time spent".
 */

const IDLE_MS = 5 * 60 * 1000;
const FLUSH_MS = 30 * 1000;

/**
 * @param {{
 *   attemptId: number|string,
 *   getCsrf: () => string,
 *   fetchImpl?: typeof fetch,
 *   now?: () => number,
 * }} options
 */
export function createActiveTimeTracker(options = {}) {
  const attemptId = options.attemptId;
  const getCsrf = options.getCsrf ?? (() => '');
  const fetchImpl = options.fetchImpl ?? fetch;
  const now = options.now ?? (() => Date.now());

  let accumulated = 0;
  let segmentStartedAt = null;
  let lastInteractionAt = now();
  let flushTimer = null;
  let tickTimer = null;
  let destroyed = false;

  function isActive() {
    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
      return false;
    }

    return now() - lastInteractionAt < IDLE_MS;
  }

  function closeSegment() {
    if (segmentStartedAt === null) {
      return;
    }

    const delta = Math.floor((now() - segmentStartedAt) / 1000);

    if (delta > 0) {
      accumulated += delta;
    }

    segmentStartedAt = null;
  }

  function openSegment() {
    if (segmentStartedAt === null && isActive()) {
      segmentStartedAt = now();
    }
  }

  function noteInteraction() {
    lastInteractionAt = now();
    openSegment();
  }

  function onVisibility() {
    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
      closeSegment();
      void flush({ keepalive: true });
    } else {
      openSegment();
    }
  }

  async function flush({ keepalive = false } = {}) {
    closeSegment();

    const delta = accumulated;

    if (delta <= 0) {
      openSegment();

      return;
    }

    accumulated = 0;

    try {
      await fetchImpl(`/player/attempts/${encodeURIComponent(attemptId)}/activity`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': getCsrf(),
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ active_seconds_delta: delta }),
        keepalive,
      });
    } catch {
      // Best-effort: fold the delta back so the next flush retries it.
      accumulated += delta;
    }

    openSegment();
  }

  function start() {
    noteInteraction();
    openSegment();

    if (typeof document !== 'undefined') {
      document.addEventListener('visibilitychange', onVisibility);
      ['pointerdown', 'keydown', 'touchstart'].forEach((eventName) => {
        document.addEventListener(eventName, noteInteraction, { passive: true });
      });
    }

    flushTimer = setInterval(() => {
      void flush();
    }, FLUSH_MS);

    tickTimer = setInterval(() => {
      if (! isActive()) {
        closeSegment();
      } else {
        openSegment();
      }
    }, 1000);
  }

  function destroy() {
    destroyed = true;
    closeSegment();

    if (flushTimer) {
      clearInterval(flushTimer);
    }

    if (tickTimer) {
      clearInterval(tickTimer);
    }

    if (typeof document !== 'undefined') {
      document.removeEventListener('visibilitychange', onVisibility);
      ['pointerdown', 'keydown', 'touchstart'].forEach((eventName) => {
        document.removeEventListener(eventName, noteInteraction);
      });
    }
  }

  return {
    start,
    flush,
    noteInteraction,
    destroy,
    /** @internal test helpers */
    _closeSegment: closeSegment,
    _openSegment: openSegment,
    _getAccumulated: () => accumulated,
    _setLastInteractionAt: (value) => {
      lastInteractionAt = value;
    },
    IDLE_MS,
    FLUSH_MS,
  };
}
