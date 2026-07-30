/**
 * Local-first block autosave shared by stateful Alpine components.
 *
 * Typing and dragging update local state immediately. Server writes are
 * debounced (~1s). Pending state lives in localStorage until the server
 * acknowledges that entry's pendingSequence (or a later one).
 */

const DEBOUNCE_MS = 1000;
const BACKOFF_START_MS = 1000;
const BACKOFF_MAX_MS = 30000;
const STORAGE_PREFIX = 'teched.autosave.v1';

/**
 * @param {{
 *   attemptId: number|string,
 *   getCsrf: () => string,
 *   onStatus?: (status: 'saving'|'saved'|'pending'|'conflict', detail?: object) => void,
 *   fetchImpl?: typeof fetch,
 * }} options
 */
export function createAutosaveController(options = {}) {
  const attemptId = options.attemptId;
  const getCsrf = options.getCsrf ?? (() => '');
  const onStatus = options.onStatus ?? (() => {});
  const fetchImpl = options.fetchImpl ?? fetch;

  /** @type {Map<string, number>} */
  const acknowledged = new Map();

  /** @type {Map<string, number>} */
  const pendingSequences = new Map();

  /** @type {Map<string, ReturnType<typeof setTimeout>>} */
  const debounceTimers = new Map();

  /** @type {Map<string, ReturnType<typeof setTimeout>>} */
  const retryTimers = new Map();

  /** @type {Map<string, number>} */
  const backoffMs = new Map();

  /** @type {Set<string>} */
  const conflicted = new Set();

  /** @type {Map<string, {state: object, baseRevision: number, pendingSequence: number}>} */
  const inFlight = new Map();

  let destroyed = false;
  let lastAnnouncedStatus = null;
  let announceTimer = null;

  function storageKey(blockId) {
    return `${STORAGE_PREFIX}:${attemptId}:${blockId}`;
  }

  function readPending(blockId) {
    try {
      const raw = localStorage.getItem(storageKey(blockId));

      if (! raw) {
        return null;
      }

      const parsed = JSON.parse(raw);

      if (! parsed || typeof parsed !== 'object' || typeof parsed.pendingSequence !== 'number') {
        return null;
      }

      return parsed;
    } catch {
      return null;
    }
  }

  function writePending(blockId, entry) {
    localStorage.setItem(storageKey(blockId), JSON.stringify(entry));
  }

  function clearPending(blockId) {
    localStorage.removeItem(storageKey(blockId));
  }

  function setAcknowledged(blockId, revision) {
    acknowledged.set(blockId, revision);
  }

  function getAcknowledged(blockId) {
    return acknowledged.get(blockId) ?? 0;
  }

  function nextSequence(blockId) {
    const next = (pendingSequences.get(blockId) ?? 0) + 1;
    pendingSequences.set(blockId, next);

    return next;
  }

  function setStatus(status, detail) {
    onStatus(status, detail);

    if (announceTimer) {
      clearTimeout(announceTimer);
    }

    // Debounce polite announcements so "Saving… Saved" is not recited on
    // every keystroke pause.
    announceTimer = setTimeout(() => {
      if (lastAnnouncedStatus !== status) {
        lastAnnouncedStatus = status;
        onStatus('announce', { status, ...detail });
      }
    }, 800);
  }

  function queue(blockId, state, baseRevision) {
    if (destroyed || conflicted.has(blockId)) {
      return;
    }

    const pendingSequence = nextSequence(blockId);
    const entry = {
      state,
      baseRevision,
      pendingSequence,
      savedAt: new Date().toISOString(),
    };

    writePending(blockId, entry);
    setStatus('pending', { blockId });

    if (debounceTimers.has(blockId)) {
      clearTimeout(debounceTimers.get(blockId));
    }

    debounceTimers.set(
      blockId,
      setTimeout(() => {
        debounceTimers.delete(blockId);
        void flushBlock(blockId);
      }, DEBOUNCE_MS),
    );
  }

  async function flushBlock(blockId, { keepalive = false } = {}) {
    if (destroyed || conflicted.has(blockId)) {
      return false;
    }

    const pending = readPending(blockId);

    if (! pending) {
      return true;
    }

    if (inFlight.has(blockId)) {
      return false;
    }

    inFlight.set(blockId, pending);
    setStatus('saving', { blockId });

    try {
      const response = await fetchImpl(
        `/player/attempts/${encodeURIComponent(attemptId)}/blocks/${encodeURIComponent(blockId)}/state`,
        {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': getCsrf(),
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({
            state: pending.state,
            revision: pending.baseRevision,
          }),
          keepalive,
        },
      );

      let body = null;

      try {
        body = await response.json();
      } catch {
        body = null;
      }

      if (response.status === 409) {
        conflicted.add(blockId);
        setStatus('conflict', { blockId, body, pending });

        return false;
      }

      if (! response.ok || typeof body?.revision !== 'number') {
        scheduleRetry(blockId);
        setStatus('pending', { blockId });

        return false;
      }

      setAcknowledged(blockId, body.revision);
      backoffMs.delete(blockId);

      const current = readPending(blockId);

      // Never clear until the server acknowledges this pendingSequence or later.
      if (current && current.pendingSequence <= pending.pendingSequence) {
        clearPending(blockId);
        setStatus('saved', { blockId, revision: body.revision });
      } else if (current) {
        // A newer edit arrived while this write was in flight — rebase onto
        // the revision the server just issued and flush again.
        writePending(blockId, {
          ...current,
          baseRevision: body.revision,
        });
        void flushBlock(blockId, { keepalive });
      } else {
        setStatus('saved', { blockId, revision: body.revision });
      }

      return true;
    } catch {
      scheduleRetry(blockId);
      setStatus('pending', { blockId });

      return false;
    } finally {
      inFlight.delete(blockId);
    }
  }

  function scheduleRetry(blockId) {
    if (retryTimers.has(blockId) || conflicted.has(blockId)) {
      return;
    }

    const delay = backoffMs.get(blockId) ?? BACKOFF_START_MS;
    backoffMs.set(blockId, Math.min(delay * 2, BACKOFF_MAX_MS));

    retryTimers.set(
      blockId,
      setTimeout(() => {
        retryTimers.delete(blockId);
        void flushBlock(blockId);
      }, delay),
    );
  }

  function cancelDebounce(blockId) {
    if (debounceTimers.has(blockId)) {
      clearTimeout(debounceTimers.get(blockId));
      debounceTimers.delete(blockId);
    }
  }

  function pendingBlockIds() {
    const prefix = `${STORAGE_PREFIX}:${attemptId}:`;
    const ids = [];

    for (let index = 0; index < localStorage.length; index += 1) {
      const key = localStorage.key(index);

      if (typeof key === 'string' && key.startsWith(prefix)) {
        ids.push(key.slice(prefix.length));
      }
    }

    return ids;
  }

  async function flushAll({ keepalive = false } = {}) {
    const blockIds = new Set(pendingBlockIds());

    for (const blockId of debounceTimers.keys()) {
      cancelDebounce(blockId);
      blockIds.add(blockId);
    }

    const results = await Promise.all(
      [...blockIds].map((blockId) => flushBlock(blockId, { keepalive })),
    );

    return results.every(Boolean);
  }

  /**
   * Replay localStorage pending entries whose pendingSequence exceeds what
   * we last acknowledged. Do not compare against server revision to decide
   * freshness — an unsent edit has no newer revision.
   */
  function replayPending(blockIds = null) {
    const ids = blockIds ?? pendingBlockIds();

    for (const blockId of ids) {
      const pending = readPending(blockId);

      if (! pending) {
        continue;
      }

      pendingSequences.set(
        blockId,
        Math.max(pendingSequences.get(blockId) ?? 0, pending.pendingSequence),
      );

      void flushBlock(blockId);
    }
  }

  function isSynced(blockId) {
    if (conflicted.has(blockId)) {
      return false;
    }

    if (debounceTimers.has(blockId) || inFlight.has(blockId) || retryTimers.has(blockId)) {
      return false;
    }

    return readPending(blockId) === null;
  }

  function isPageSynced(blockIds) {
    return blockIds.every((blockId) => isSynced(blockId));
  }

  function hasConflict(blockId) {
    return conflicted.has(blockId);
  }

  function destroy() {
    destroyed = true;

    for (const timer of debounceTimers.values()) {
      clearTimeout(timer);
    }

    for (const timer of retryTimers.values()) {
      clearTimeout(timer);
    }

    if (announceTimer) {
      clearTimeout(announceTimer);
    }

    debounceTimers.clear();
    retryTimers.clear();
  }

  return {
    queue,
    flushBlock,
    flushAll,
    replayPending,
    cancelDebounce,
    setAcknowledged,
    getAcknowledged,
    isSynced,
    isPageSynced,
    hasConflict,
    readPending,
    destroy,
    DEBOUNCE_MS,
    BACKOFF_START_MS,
    BACKOFF_MAX_MS,
  };
}
