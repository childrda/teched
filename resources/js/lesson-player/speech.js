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
 * @param {{ onSegmentChange?: (blockId: string|null, segmentId: string|null) => void }} hooks
 */
export function createSpeechController(state, { onSegmentChange } = {}) {
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

  function clearActive() {
    state.speaking = false;
    state.paused = false;
    state.activeBlockId = null;
    state.activeSegmentId = null;
    currentBlockId = null;
    currentSegments = [];

    onSegmentChange?.(null, null);
  }

  function stop() {
    run += 1;

    if (supported) {
      synthesis.cancel();
    }

    clearActive();
  }

  function speak(blockId, segments) {
    if (!supported) {
      return;
    }

    const speakable = (segments ?? []).filter(
      (segment) => segment && typeof segment.text === 'string' && segment.text !== '',
    );

    if (speakable.length === 0) {
      return;
    }

    // Anything queued for another block is dropped before this one starts.
    stop();

    const thisRun = run;
    const voice = resolveVoice();
    const rate = clampRate(state.rate);

    currentBlockId = blockId;
    currentSegments = speakable;

    state.speaking = true;
    state.paused = false;
    state.activeBlockId = blockId;

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

  /** A new rate or voice takes effect at once, on the block being read. */
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
