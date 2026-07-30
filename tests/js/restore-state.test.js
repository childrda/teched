import { describe, expect, it } from 'vitest';

import { cerActivity } from '../../resources/js/lesson-player/cer.js';
import { placementActivity } from '../../resources/js/lesson-player/placement-controller.js';
import { quizActivity } from '../../resources/js/lesson-player/quiz.js';
import { shortResponseActivity } from '../../resources/js/lesson-player/short-response.js';

describe('restoreState is a no-op on missing or malformed state', () => {
  it('short response', () => {
    const activity = shortResponseActivity({ blockId: 'b1', pageId: 'p1', minLength: null, strings: {} });

    activity.restoreState(null);
    activity.restoreState({ value: 12 });
    expect(activity.value).toBe('');

    activity.restoreState({ value: ' kept ' });
    expect(activity.value).toBe(' kept ');
  });

  it('cer', () => {
    const activity = cerActivity({
      blockId: 'b1',
      pageId: 'p1',
      fields: [{ id: 'claim', minLength: null }],
      strings: {},
    });

    activity.restoreState(undefined);
    activity.restoreState({ values: 'nope' });
    expect(activity.values.claim).toBe('');

    activity.restoreState({ values: { claim: 'ok' } });
    expect(activity.values.claim).toBe('ok');
  });

  it('quiz', () => {
    const activity = quizActivity({
      blockId: 'b1',
      pageId: 'p1',
      questions: [{ id: 'q1', prompt: 'Q?', options: [{ id: 'o1', text: 'A' }] }],
      strings: {},
    });

    activity.restoreState(null);
    expect(activity.answers.q1).toBeNull();

    activity.restoreState({ answers: { q1: 'o1' } });
    expect(activity.answers.q1).toBe('o1');
  });

  it('placement', () => {
    const activity = placementActivity({
      blockId: 'b1',
      pageId: 'p1',
      shuffle: false,
      items: [
        { id: 'i1', label: 'One', name: 'One' },
        { id: 'i2', label: 'Two', name: 'Two' },
      ],
      slots: [
        { id: 's1', name: 'Slot 1', description: 'D1' },
        { id: 's2', name: 'Slot 2', description: 'D2' },
      ],
      strings: {},
    });

    activity.init();
    activity.restoreState(null);
    activity.restoreState({ placements: 'bad' });
    expect(activity.itemIn('s1')).toBeNull();

    activity.restoreState({ placements: { s1: 'i1', s2: null } });
    expect(activity.itemIn('s1')).toBe('i1');
  });
});
