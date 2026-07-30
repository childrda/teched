<?php

use App\Support\SeededShuffle;

/**
 * Shared fixture with resources/js/lesson-player/seeded-shuffle.js —
 * both must produce this order for seed "seed-a" / block "block-1".
 */
const SHUFFLE_FIXTURE_ITEMS = ['a', 'b', 'c', 'd', 'e'];

test('the same seed and block id produce the same order twice', function () {
    $first = SeededShuffle::shuffle(SHUFFLE_FIXTURE_ITEMS, 'seed-a', 'block-1');
    $second = SeededShuffle::shuffle(SHUFFLE_FIXTURE_ITEMS, 'seed-a', 'block-1');

    expect($first)->toBe($second)
        ->and($first)->not->toBe(SHUFFLE_FIXTURE_ITEMS);
});

test('two block ids produce different orders', function () {
    $a = SeededShuffle::shuffle(SHUFFLE_FIXTURE_ITEMS, 'seed-a', 'block-1');
    $b = SeededShuffle::shuffle(SHUFFLE_FIXTURE_ITEMS, 'seed-a', 'block-2');

    expect($a)->not->toBe($b);
});

test('shared PHP/JS fixture order is stable', function () {
    // If this expectation changes, update tests/js/seeded-shuffle.test.js too.
    expect(SeededShuffle::shuffle(SHUFFLE_FIXTURE_ITEMS, 'seed-a', 'block-1'))
        ->toBe(['e', 'c', 'b', 'a', 'd']);
});
