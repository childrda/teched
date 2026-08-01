/**
 * Read-aloud through the browser's own SpeechSynthesis. No cloud service,
 * no audio files, and never automatic: a student always starts it.
 *
 * The controller mutates a plain `state` object that the UI binds to, and
 * keeps every live SpeechSynthesis object in this closure. That separation
 * matters: a reactive framework proxy around an utterance or a voice makes
 * the browser reject it.
 */

/** The only things read-aloud may persist, and the only keys it may use. */
export const SPEECH_STORAGE_KEYS = Object.freeze({
  rate: 'lesson_player.speech.rate',
  voiceUri: 'lesson_player.speech.voice_uri',
});

export const RATE = Object.freeze({ min: 0.5, max: 2, step: 0.1, default: 1 });

/**
 * Chrome and Edge silently discard an utterance handed to speak() in the same
 * task as a cancel(), so queueing always waits for a later task. After a real
 * cancel a setTimeout(0) is still unreliable there — the queue needs a moment
 * to actually drain — hence the two delays.
 */
const QUEUE_DELAY_MS = Object.freeze({ afterCancel: 100, otherwise: 0 });

export function clampRate(value) {
  const rate = Number(value);

  if (!Number.isFinite(rate)) {
    return RATE.default;
  }

  return Math.min(RATE.max, Math.max(RATE.min, rate));
}

/** Label first when present, so "Row 2" precedes the row's contents. */
export function utteranceTextFor(segment) {
  return segment.label ? `${segment.label}. ${segment.text}` : segment.text;
}

/**
 * @param {object} state plain object the UI binds to; mutated in place
 * @param {{
 *   onSegmentChange?: (blockId: string|null, segmentId: string|null) => void,
 *   onResumePointChange?: (blockId: string|null, segmentId: string|null) => void,
 * }} hooks
 */
export function createSpeechController(state, { onSegmentChange, onResumePointChange } = {}) {
  const synthesis = typeof window === 'undefined' ? undefined : window.speechSynthesis;
  const Utterance = typeof window === 'undefined' ? undefined : window.SpeechSynthesisUtterance;
  const supported = Boolean(synthesis && Utterance);

  // Bumped on every stop and every new block, so an utterance event that
  // arrives after its run was cancelled cannot touch current state.
  let run = 0;
  let currentBlockId = null;
  let currentSegments = [];

  function readStored(key, fallback) {
    try {
      const stored = window.localStorage.getItem(key);

      return stored === null ? fallback : stored;
    } catch {
      // Storage can be denied outright; preferences are not worth failing for.
      return fallback;
    }
  }

  function writeStored(key, value) {
    try {
      window.localStorage.setItem(key, String(value));
    } catch {
      // Ignored for the same reason.
    }
  }

  state.supported = supported;
  state.speaking = false;
  state.paused = false;
  state.activeBlockId = null;
  state.activeSegmentId = null;
  // Where reading was interrupted, so the next play can pick it up. One slot,
  // not a map: speak() already replaces whatever was in flight, so there is no
  // such thing as two live sessions to remember separately. In memory only —
  // it resets on reload, like the rest of playback position.
  state.resumeBlockId = null;
  state.resumeSegmentId = null;
  state.rate = clampRate(readStored(SPEECH_STORAGE_KEYS.rate, RATE.default));
  state.voiceUri = readStored(SPEECH_STORAGE_KEYS.voiceUri, '');
  state.voices = [];

  /**
   * Voices arrive asynchronously in most browsers: some are there on the
   * first call, the rest turn up with a voiceschanged event.
   */
  function loadVoices() {
    if (!supported) {
      return;
    }

    // Plain copies only. The live voice objects stay in this closure and are
    // looked up again by voiceURI when speaking.
    state.voices = synthesis.getVoices().map((voice) => ({
      voiceURI: voice.voiceURI,
      name: voice.name,
      lang: voice.lang,
      label: `${voice.name} (${voice.lang})`,
    }));
  }

  /**
   * The stored preference is kept even when the voice is missing: voices
   * load late, and the student's choice should survive until it is there.
   * Until then the browser default is used, silently.
   */
  function resolveVoice() {
    if (!supported || !state.voiceUri) {
      return null;
    }

    return synthesis.getVoices().find((voice) => voice.voiceURI === state.voiceUri) ?? null;
  }

  /** Fires the hook only when the slot actually moves, so the DOM is not swept for nothing. */
  function setResumePoint(blockId, segmentId) {
    if (state.resumeBlockId === blockId && state.resumeSegmentId === segmentId) {
      return;
    }

    state.resumeBlockId = blockId;
    state.resumeSegmentId = segmentId;

    onResumePointChange?.(blockId, segmentId);
  }

  function clearActive() {
    state.speaking = false;
    state.paused = false;
    state.activeBlockId = null;
    state.activeSegmentId = null;
    currentBlockId = null;
    currentSegments = [];

    onSegmentChange?.(null, null);
  }

  /**
   * Cancelling nothing still costs the caller a settling delay, so it is only
   * done when there is something to cancel.
   *
   * @returns {boolean} whether a cancel was actually issued
   */
  function cancelIfQueued() {
    if (!supported || !(synthesis.speaking || synthesis.pending)) {
      return false;
    }

    synthesis.cancel();

    return true;
  }

  /**
   * speak() stops whatever is in flight before starting, and restart() reaches
   * speak() the same way — so recording lives on a parameter rather than in
   * stop() itself. Only a stop a student actually asked for is worth
   * remembering; a stop that exists to make room for the next utterance is not.
   *
   * @returns {boolean} whether a cancel was actually issued
   */
  function stopInternal(shouldRecord) {
    run += 1;

    const cancelled = cancelIfQueued();

    // Before clearActive(), which is what nulls these.
    if (shouldRecord && state.activeBlockId !== null && state.activeSegmentId !== null) {
      setResumePoint(state.activeBlockId, state.activeSegmentId);
    }

    clearActive();

    return cancelled;
  }

  /**
   * The student-facing stop, and the one wired to visibilitychange and
   * beforeunload — hence no parameters: those call it with an Event.
   *
   * @returns {boolean} whether a cancel was actually issued
   */
  function stop() {
    return stopInternal(true);
  }

  /**
   * @param {string} blockId
   * @param {Array<object>} segments
   * @param {string|null} [startAtSegmentId] begin here instead of at the first
   *   speakable segment. An id that is no longer in the block — the manifest
   *   can have changed since the position was recorded — reads from the start.
   */
  function speak(blockId, segments, startAtSegmentId = null) {
    if (!supported) {
      return;
    }

    const all = (segments ?? []).filter(
      (segment) => segment && typeof segment.text === 'string' && segment.text !== '',
    );

    if (all.length === 0) {
      return;
    }

    const startIndex = startAtSegmentId === null
      ? 0
      : Math.max(0, all.findIndex((segment) => segment.id === startAtSegmentId));

    const speakable = all.slice(startIndex);

    // Anything queued for another block is dropped before this one starts.
    // Not stop(): this one is the machinery making room, not a student
    // stopping, so it must not record a position.
    const cancelled = stopInternal(false);

    // Reading this block again — from its remembered point or from the top —
    // consumes the marker either way.
    if (state.resumeBlockId === blockId) {
      setResumePoint(null, null);
    }

    const thisRun = run;
    const voice = resolveVoice();
    const rate = clampRate(state.rate);

    currentBlockId = blockId;
    currentSegments = speakable;

    state.speaking = true;
    state.paused = false;
    state.activeBlockId = blockId;

    // Queueing is deferred to a later task; see QUEUE_DELAY_MS.
    setTimeout(() => {
      // A second click, a page change, or a teardown in the meantime already
      // started its own run, and speaking now would double up on it.
      if (thisRun !== run) {
        return;
      }

      // Chrome can leave the synthesis object paused, and speak() on a paused
      // object queues silently and never plays. A student who paused during
      // the delay above is left alone: their utterances wait for resume().
      if (!state.paused) {
        synthesis.resume();
      }

      speakable.forEach((segment, index) => {
        const utterance = new Utterance(utteranceTextFor(segment));

        utterance.rate = rate;

        if (voice) {
          utterance.voice = voice;
        }

        utterance.onstart = () => {
          if (thisRun !== run) {
            return;
          }

          state.activeSegmentId = segment.id;
          onSegmentChange?.(blockId, segment.id);
        };

        utterance.onend = () => {
          if (thisRun !== run || index !== speakable.length - 1) {
            return;
          }

          // Finishing is not being interrupted: a block read to its end has
          // nothing left to resume, so it must not look like one that was
          // stopped partway.
          if (state.resumeBlockId === blockId) {
            setResumePoint(null, null);
          }

          clearActive();
        };

        utterance.onerror = () => {
          if (thisRun !== run) {
            return;
          }

          clearActive();
        };

        synthesis.speak(utterance);
      });
    }, cancelled ? QUEUE_DELAY_MS.afterCancel : QUEUE_DELAY_MS.otherwise);
  }

  function pause() {
    if (!supported || !state.speaking || state.paused) {
      return;
    }

    synthesis.pause();
    state.paused = true;
  }

  function resume() {
    if (!supported || !state.paused) {
      return;
    }

    synthesis.resume();
    state.paused = false;
  }

  /**
   * A new rate or voice takes effect at once, on the block being read. Always
   * from the true beginning: it reaches speak() with no starting point, and
   * speak()'s internal stop does not record one, so no resume state is created
   * or consulted here.
   */
  function restart() {
    const blockId = currentBlockId;
    const segments = currentSegments;

    if (blockId === null) {
      return;
    }

    speak(blockId, segments);
  }

  function setRate(value) {
    state.rate = clampRate(value);
    writeStored(SPEECH_STORAGE_KEYS.rate, state.rate);

    if (state.speaking) {
      restart();
    }
  }

  function setVoice(voiceUri) {
    state.voiceUri = voiceUri ?? '';
    writeStored(SPEECH_STORAGE_KEYS.voiceUri, state.voiceUri);

    if (state.speaking) {
      restart();
    }
  }

  function onVisibilityChange() {
    if (document.visibilityState === 'hidden') {
      stop();
    }
  }

  function init() {
    if (!supported) {
      return;
    }

    loadVoices();

    synthesis.addEventListener('voiceschanged', loadVoices);
    document.addEventListener('visibilitychange', onVisibilityChange);

    // Chrome keeps speaking across a navigation unless it is told not to.
    window.addEventListener('beforeunload', stop);
  }

  function destroy() {
    if (supported) {
      synthesis.removeEventListener('voiceschanged', loadVoices);
      document.removeEventListener('visibilitychange', onVisibilityChange);
      window.removeEventListener('beforeunload', stop);
    }

    stop();
  }

  return { init, destroy, speak, stop, pause, resume, setRate, setVoice, loadVoices };
}
