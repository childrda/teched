import { describe, expect, it } from 'vitest';

import { seededShuffle } from '../../resources/js/lesson-player/seeded-shuffle.js';

const ITEMS = ['a', 'b', 'c', 'd', 'e'];

describe('seededShuffle', () => {
  it('produces the same order twice for the same seed and block id', () => {
    const first = seededShuffle(ITEMS, 'seed-a', 'block-1');
    const second = seededShuffle(ITEMS, 'seed-a', 'block-1');

    expect(first).toEqual(second);
    expect(first).not.toEqual(ITEMS);
  });

  it('produces different orders for different block ids', () => {
    expect(seededShuffle(ITEMS, 'seed-a', 'block-1')).not.toEqual(
      seededShuffle(ITEMS, 'seed-a', 'block-2'),
    );
  });

  it('agrees with the PHP SeededShuffle fixture', () => {
    // Keep in lockstep with tests/Unit/SeededShuffleTest.php
    expect(seededShuffle(ITEMS, 'seed-a', 'block-1')).toEqual(['e', 'c', 'b', 'a', 'd']);
  });
});
