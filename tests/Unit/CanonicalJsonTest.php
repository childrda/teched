<?php

use App\Support\CanonicalJson;

test('canonical encoding ignores object key order', function () {
    expect(CanonicalJson::equal(
        ['b' => 1, 'a' => ['y' => 2, 'x' => 3]],
        ['a' => ['x' => 3, 'y' => 2], 'b' => 1],
    ))->toBeTrue();
});

test('canonical encoding treats empty string and null as different', function () {
    expect(CanonicalJson::equal(['value' => ''], ['value' => null]))->toBeFalse()
        ->and(CanonicalJson::equal(['value' => ''], ['value' => '']))->toBeTrue();
});
