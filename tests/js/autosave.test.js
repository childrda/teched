import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { createAutosaveController } from '../../resources/js/lesson-player/autosave.js';

function installLocalStorage() {
  const store = new Map();

  globalThis.localStorage = {
    getItem: (key) => (store.has(key) ? store.get(key) : null),
    setItem: (key, value) => store.set(key, String(value)),
    removeItem: (key) => store.delete(key),
    clear: () => store.clear(),
    key: (index) => [...store.keys()][index] ?? null,
    get length() {
      return store.size;
    },
  };

  // Object.keys(localStorage) is empty for this stub — replayPending walks
  // Object.keys, so also expose entries via a plain object mirror when needed.
  Object.defineProperty(globalThis.localStorage, Symbol.iterator, {
    value: function* iterator() {
      yield* store.keys();
    },
  });

  return store;
}

describe('createAutosaveController', () => {
  beforeEach(() => {
    installLocalStorage();
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
    localStorage.clear();
  });

  it('debounces rapid edits into one write', async () => {
    const fetchImpl = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ revision: 1 }),
    });

    const autosave = createAutosaveController({
      attemptId: 9,
      getCsrf: () => 'token',
      fetchImpl,
    });

    autosave.queue('block-a', { value: '1' }, 0);
    autosave.queue('block-a', { value: '2' }, 0);
    autosave.queue('block-a', { value: '3' }, 0);

    expect(fetchImpl).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(1000);

    expect(fetchImpl).toHaveBeenCalledTimes(1);
    expect(JSON.parse(fetchImpl.mock.calls[0][1].body).state.value).toBe('3');

    autosave.destroy();
  });

  it('keeps a pending entry until the matching sequence is acknowledged', async () => {
    const fetchImpl = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ revision: 1 }),
    });

    const autosave = createAutosaveController({
      attemptId: 9,
      getCsrf: () => 'token',
      fetchImpl,
    });

    autosave.queue('block-a', { value: 'pending' }, 0);
    await vi.advanceTimersByTimeAsync(1000);
    await Promise.resolve();

    expect(autosave.readPending('block-a')).toBeNull();
    expect(autosave.getAcknowledged('block-a')).toBe(1);

    autosave.destroy();
  });

  it('retries failed saves with growing delay and reports pending', async () => {
    const statuses = [];
    const fetchImpl = vi
      .fn()
      .mockRejectedValueOnce(new Error('network'))
      .mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({ revision: 1 }),
      });

    const autosave = createAutosaveController({
      attemptId: 9,
      getCsrf: () => 'token',
      fetchImpl,
      onStatus: (status) => statuses.push(status),
    });

    autosave.queue('block-a', { value: 'x' }, 0);
    await vi.advanceTimersByTimeAsync(1000);
    await Promise.resolve();

    expect(statuses).toContain('pending');

    await vi.advanceTimersByTimeAsync(1000);
    await Promise.resolve();

    expect(fetchImpl.mock.calls.length).toBeGreaterThanOrEqual(2);

    autosave.destroy();
  });

  it('stops on 409 and surfaces conflict without clearing local pending', async () => {
    const statuses = [];
    const fetchImpl = vi.fn().mockResolvedValue({
      ok: false,
      status: 409,
      json: async () => ({ revision: 2, state: { value: 'other' } }),
    });

    const autosave = createAutosaveController({
      attemptId: 9,
      getCsrf: () => 'token',
      fetchImpl,
      onStatus: (status) => statuses.push(status),
    });

    autosave.queue('block-a', { value: 'mine' }, 0);
    await vi.advanceTimersByTimeAsync(1000);
    await Promise.resolve();

    expect(statuses).toContain('conflict');
    expect(autosave.hasConflict('block-a')).toBe(true);
    expect(autosave.readPending('block-a')).not.toBeNull();

    autosave.destroy();
  });

  it('tracks revisions per block', async () => {
    const fetchImpl = vi.fn().mockImplementation(async (url) => {
      const revision = url.includes('block-a') ? 1 : 3;

      return {
        ok: true,
        status: 200,
        json: async () => ({ revision }),
      };
    });

    const autosave = createAutosaveController({
      attemptId: 9,
      getCsrf: () => 'token',
      fetchImpl,
    });

    autosave.setAcknowledged('block-b', 2);
    autosave.queue('block-a', { value: 'a' }, 0);
    autosave.queue('block-b', { value: 'b' }, 2);

    await vi.advanceTimersByTimeAsync(1000);
    await Promise.resolve();

    const bodies = fetchImpl.mock.calls.map((call) => JSON.parse(call[1].body));
    const forA = bodies.find((body) => body.state.value === 'a');
    const forB = bodies.find((body) => body.state.value === 'b');

    expect(forA.revision).toBe(0);
    expect(forB.revision).toBe(2);

    autosave.destroy();
  });

  it('replays pending entries after a simulated reload', async () => {
    const key = 'teched.autosave.v1:9:block-a';
    localStorage.setItem(
      key,
      JSON.stringify({
        state: { value: 'replay' },
        baseRevision: 0,
        pendingSequence: 4,
        savedAt: new Date().toISOString(),
      }),
    );

    const fetchImpl = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ revision: 1 }),
    });

    const autosave = createAutosaveController({
      attemptId: 9,
      getCsrf: () => 'token',
      fetchImpl,
    });

    autosave.replayPending(['block-a']);
    await Promise.resolve();
    await Promise.resolve();

    expect(fetchImpl).toHaveBeenCalled();
    expect(JSON.parse(fetchImpl.mock.calls[0][1].body).state.value).toBe('replay');

    autosave.destroy();
  });
});
