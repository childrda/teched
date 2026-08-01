import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { createSpeechController } from '../../resources/js/lesson-player/speech.js';

/**
 * Chrome and Edge drop an utterance queued in the same task as a cancel(),
 * which made read-aloud silent. These tests hold the queueing off that task.
 *
 * The fake records the order of the calls it receives, because the ordering is
 * the whole contract: cancel, then a later task, then resume, then speak.
 */
class FakeUtterance {
    constructor(text) {
        this.text = text;
    }
}

function fakeSynthesis() {
    return {
        speaking: false,
        pending: false,
        paused: false,
        log: [],
        spoken: [],
        cancel() {
            this.log.push('cancel');
            this.speaking = false;
            this.pending = false;
        },
        pause() {
            this.log.push('pause');
            this.paused = true;
        },
        resume() {
            this.log.push('resume');
            this.paused = false;
        },
        speak(utterance) {
            this.log.push('speak');
            this.spoken.push(utterance);
            this.pending = true;
        },
        getVoices: () => [],
        addEventListener() {},
        removeEventListener() {},
    };
}

const SEGMENTS = [
    { id: 'html:0', label: null, text: 'First' },
    { id: 'html:1', label: null, text: 'Second' },
];

function spokenText(synthesis) {
    return synthesis.spoken.map((utterance) => utterance.text);
}

let synthesis;
let state;
let speech;

beforeEach(() => {
    vi.useFakeTimers();

    synthesis = fakeSynthesis();

    const store = new Map();

    globalThis.window = {
        speechSynthesis: synthesis,
        SpeechSynthesisUtterance: FakeUtterance,
        localStorage: {
            getItem: (key) => (store.has(key) ? store.get(key) : null),
            setItem: (key, value) => store.set(key, String(value)),
        },
    };

    state = {};
    speech = createSpeechController(state);
});

afterEach(() => {
    vi.useRealTimers();
});

describe('queueing around a cancel', () => {
    it('queues nothing in the task that cancels, and speaks once the queue has drained', () => {
        // Another block is already being read, so this one must cancel first.
        synthesis.speaking = true;

        speech.speak('B1', SEGMENTS);

        expect(synthesis.log).toEqual(['cancel']);
        expect(spokenText(synthesis)).toEqual([]);

        // A setTimeout(0) is not enough in Chrome once a cancel has happened.
        vi.advanceTimersByTime(0);

        expect(spokenText(synthesis)).toEqual([]);

        vi.advanceTimersByTime(100);

        expect(synthesis.log).toEqual(['cancel', 'resume', 'speak', 'speak']);
        expect(spokenText(synthesis)).toEqual(['First', 'Second']);
    });

    it('does not cancel when nothing is queued, and needs only the next task', () => {
        speech.speak('B1', SEGMENTS);

        expect(synthesis.log).toEqual([]);
        expect(spokenText(synthesis)).toEqual([]);

        vi.advanceTimersByTime(0);

        expect(synthesis.log).toEqual(['resume', 'speak', 'speak']);
        expect(spokenText(synthesis)).toEqual(['First', 'Second']);
    });

    it('resumes before queueing, since speak() on a paused engine is silent', () => {
        // Chrome can leave the object paused with nothing playing.
        synthesis.paused = true;

        speech.speak('B1', SEGMENTS);
        vi.advanceTimersByTime(0);

        expect(synthesis.log.indexOf('resume')).toBeLessThan(synthesis.log.indexOf('speak'));
        expect(synthesis.paused).toBe(false);
    });
});

describe('a run that went stale before its queueing task ran', () => {
    it('queues nothing after a stop', () => {
        synthesis.speaking = true;

        speech.speak('B1', SEGMENTS);
        speech.stop();

        vi.advanceTimersByTime(1000);

        expect(spokenText(synthesis)).toEqual([]);
        expect(state.speaking).toBe(false);
    });

    it('speaks only the second of two rapid clicks', () => {
        speech.speak('B1', SEGMENTS);
        speech.speak('B2', [{ id: 'only', label: null, text: 'Second block' }]);

        vi.advanceTimersByTime(1000);

        expect(spokenText(synthesis)).toEqual(['Second block']);
        expect(state.activeBlockId).toBe('B2');
    });
});

describe('what the deferral must not break', () => {
    it('still reports each segment as it starts speaking', () => {
        const changes = [];
        const localState = {};
        const controller = createSpeechController(localState, {
            onSegmentChange: (blockId, segmentId) => changes.push(`${blockId}/${segmentId}`),
        });

        controller.speak('B1', SEGMENTS);
        vi.advanceTimersByTime(0);

        synthesis.spoken.forEach((utterance) => utterance.onstart());

        expect(changes.slice(-2)).toEqual(['B1/html:0', 'B1/html:1']);
        expect(localState.activeSegmentId).toBe('html:1');
    });

    it('clears its state when the last segment ends', () => {
        speech.speak('B1', SEGMENTS);
        vi.advanceTimersByTime(0);

        synthesis.spoken[0].onend();

        expect(state.speaking).toBe(true);

        synthesis.spoken[1].onend();

        expect(state.speaking).toBe(false);
        expect(state.activeBlockId).toBeNull();
    });

    it('leaves a student who paused during the delay paused', () => {
        speech.speak('B1', SEGMENTS);

        speech.pause();

        vi.advanceTimersByTime(1000);

        // Queued while paused, so the audio waits for the student's resume
        // rather than the defensive one overriding them.
        expect(synthesis.log).toEqual(['pause', 'speak', 'speak']);
        expect(state.paused).toBe(true);
    });

    it('speaks a label before the text it belongs to', () => {
        speech.speak('B1', [{ id: 'row:0', label: 'Row 1', text: 'Steel' }]);
        vi.advanceTimersByTime(0);

        expect(spokenText(synthesis)).toEqual(['Row 1. Steel']);
    });

    it('applies the current rate to every utterance', () => {
        speech.setRate(1.5);
        speech.speak('B1', SEGMENTS);
        vi.advanceTimersByTime(0);

        expect(synthesis.spoken.map((utterance) => utterance.rate)).toEqual([1.5, 1.5]);
    });
});

describe('remembering where reading stopped', () => {
    /** Speak, let the queue drain, and report the segment that is speaking. */
    function playTo(controller, blockId, segments, index) {
        controller.speak(blockId, segments);
        vi.advanceTimersByTime(1000);

        for (let i = 0; i <= index; i += 1) {
            synthesis.spoken[i].onstart();
        }
    }

    it('resumes from the segment that was active when stopped', () => {
        playTo(speech, 'B1', SEGMENTS, 1);

        speech.stop();

        expect(state.resumeBlockId).toBe('B1');
        expect(state.resumeSegmentId).toBe('html:1');

        synthesis.spoken.length = 0;

        speech.speak('B1', SEGMENTS, state.resumeSegmentId);
        vi.advanceTimersByTime(1000);

        expect(spokenText(synthesis)).toEqual(['Second']);
    });

    it('remembers nothing when a block is stopped before any segment started', () => {
        speech.speak('B1', SEGMENTS);
        vi.advanceTimersByTime(1000);

        speech.stop();

        expect(state.resumeBlockId).toBeNull();
        expect(state.resumeSegmentId).toBeNull();
    });

    it('leaves no resume point behind when a block finishes on its own', () => {
        playTo(speech, 'B1', SEGMENTS, 1);

        synthesis.spoken[1].onend();

        expect(state.resumeBlockId).toBeNull();
        expect(state.resumeSegmentId).toBeNull();
        expect(state.speaking).toBe(false);
    });

    it('replaces the remembered position when a second block is interrupted', () => {
        playTo(speech, 'B1', SEGMENTS, 0);
        speech.stop();

        expect(state.resumeBlockId).toBe('B1');

        synthesis.spoken.length = 0;

        const other = [{ id: 'x:0', label: null, text: 'Other one' }];

        playTo(speech, 'B2', other, 0);
        speech.stop();

        expect(state.resumeBlockId).toBe('B2');
        expect(state.resumeSegmentId).toBe('x:0');
    });

    it('reports the resume point through its own hook, set and cleared', () => {
        const events = [];
        const localState = {};
        const controller = createSpeechController(localState, {
            onResumePointChange: (blockId, segmentId) => events.push(`${blockId}/${segmentId}`),
        });

        controller.speak('B1', SEGMENTS);
        vi.advanceTimersByTime(1000);
        synthesis.spoken[0].onstart();

        controller.stop();

        expect(events).toEqual(['B1/html:0']);

        controller.speak('B1', SEGMENTS);
        vi.advanceTimersByTime(1000);

        expect(events).toEqual(['B1/html:0', 'null/null']);
    });

    it('starts over from the first segment and forgets the position', () => {
        playTo(speech, 'B1', SEGMENTS, 1);
        speech.stop();

        synthesis.spoken.length = 0;

        // No starting point: this is the Start over control.
        speech.speak('B1', SEGMENTS);
        vi.advanceTimersByTime(1000);

        expect(spokenText(synthesis)).toEqual(['First', 'Second']);
        expect(state.resumeBlockId).toBeNull();
    });

    it('reads from the start when the remembered segment is gone from the block', () => {
        playTo(speech, 'B1', SEGMENTS, 1);
        speech.stop();

        synthesis.spoken.length = 0;

        // The manifest changed under the recorded position.
        const rewritten = [{ id: 'html:9', label: null, text: 'Rewritten' }];

        speech.speak('B1', rewritten, state.resumeSegmentId);
        vi.advanceTimersByTime(1000);

        expect(spokenText(synthesis)).toEqual(['Rewritten']);
    });

    it('does not record or consult a resume point when a rate change restarts a read', () => {
        playTo(speech, 'B1', SEGMENTS, 1);

        synthesis.spoken.length = 0;

        // setRate() restarts the block in flight.
        speech.setRate(1.5);
        vi.advanceTimersByTime(1000);

        expect(state.resumeBlockId).toBeNull();
        expect(spokenText(synthesis)).toEqual(['First', 'Second']);
    });

    it('keeps another block’s remembered position when a different block finishes', () => {
        playTo(speech, 'B1', SEGMENTS, 0);
        speech.stop();

        synthesis.spoken.length = 0;

        const other = [{ id: 'x:0', label: null, text: 'Other one' }];

        speech.speak('B2', other);
        vi.advanceTimersByTime(1000);
        synthesis.spoken[0].onend();

        expect(state.resumeBlockId).toBe('B1');
        expect(state.resumeSegmentId).toBe('html:0');
    });
});
