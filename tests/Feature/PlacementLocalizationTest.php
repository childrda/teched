<?php

use App\Support\PlacementActivity;

/**
 * The placement controller says a great deal to a student, and none of it is
 * written in JavaScript. Blade translates every string and passes it in, so
 * that a second language is a language file rather than a rewrite of the
 * interaction code.
 */

/** The controller with its comments stripped: only what actually runs. */
function controllerCode(): string
{
    $source = file_get_contents(base_path('resources/js/lesson-player/placement-controller.js'));

    return preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);
}

test('every string the controller reads is declared and translated', function () {
    preg_match_all('/strings\.([a-z_]+)/', controllerCode(), $matches);

    $used = array_unique($matches[1]);

    expect($used)->not->toBeEmpty();

    foreach ($used as $key) {
        expect(in_array($key, PlacementActivity::STRINGS, true))
            ->toBeTrue("The controller reads strings.{$key}, which nothing passes in.");

        expect(__("placement.{$key}"))
            ->not->toBe("placement.{$key}", "lang/en/placement.php has no {$key} entry.");
    }
});

test('the payload carries a translated value for every declared string', function () {
    $activity = PlacementActivity::forMatching(
        [
            'instructions' => 'Match them up.',
            'bank' => [['id' => 'i1', 'label' => 'Weld']],
            'slots' => [['id' => 's1', 'description' => 'A fused joint']],
            'shuffle' => false,
        ],
        'BLOCK-1',
        'PAGE-1',
        'complete_activity'
    );

    expect(array_keys($activity['strings']))->toBe(PlacementActivity::STRINGS);

    foreach ($activity['strings'] as $key => $value) {
        expect($value)->toBeString()->not->toBe('')->not->toBe("placement.{$key}");
    }
});

test('the controller announces nothing written in English', function () {
    $source = controllerCode();

    // Only translated templates reach announce() and fill(); a literal in
    // either place would be wording no language file could reach.
    expect($source)->not->toMatch("/announce\(\s*['\"`]/")
        ->and($source)->not->toMatch("/fill\(\s*['\"`]/");
});

test('a repeated label is still named distinctly', function () {
    $activity = PlacementActivity::forImageLabeling(
        [
            'image_url' => '/lessons/x.png',
            'image_alt' => 'A diagram',
            'hotspots' => [
                ['id' => 'h1', 'number' => 1, 'x_pct' => 10.0, 'y_pct' => 20.0, 'description' => 'First point'],
                ['id' => 'h2', 'number' => 2, 'x_pct' => 30.0, 'y_pct' => 40.0, 'description' => 'Second point'],
            ],
            'bank' => [
                ['id' => 'b1', 'label' => 'Arc'],
                ['id' => 'b2', 'label' => 'Arc'],
                ['id' => 'b3', 'label' => 'Electrode'],
            ],
        ],
        'BLOCK-2',
        'PAGE-2',
        'complete_activity'
    );

    expect(array_column($activity['items'], 'name'))->toBe(['Arc (1)', 'Arc (2)', 'Electrode'])
        ->and(array_column($activity['items'], 'label'))->toBe(['Arc', 'Arc', 'Electrode']);
});

test('slot names and coordinates come from the hotspot itself', function () {
    $activity = PlacementActivity::forImageLabeling(
        [
            'image_url' => '/lessons/x.png',
            'image_alt' => 'A diagram',
            'hotspots' => [
                ['id' => 'h9', 'number' => 9, 'x_pct' => 12.5, 'y_pct' => 87.5, 'description' => 'A point'],
            ],
            'bank' => [['id' => 'b1', 'label' => 'Arc']],
        ],
        'BLOCK-3',
        'PAGE-3',
        'complete_activity'
    );

    expect($activity['slots'][0])->toMatchArray([
        'id' => 'h9',
        'number' => 9,
        'name' => 'Point 9',
        'description' => 'A point',
        'x' => 12.5,
        'y' => 87.5,
    ]);
});

test('matching rows are numbered by position, and bank labels carry a speech segment', function () {
    $activity = PlacementActivity::forMatching(
        [
            'instructions' => null,
            'bank' => [['id' => 'i1', 'label' => 'Weld'], ['id' => 'i2', 'label' => 'Filler']],
            'slots' => [
                ['id' => 's1', 'description' => 'First'],
                ['id' => 's2', 'description' => 'Second'],
            ],
            'shuffle' => true,
        ],
        'BLOCK-4',
        'PAGE-4',
        'complete_activity'
    );

    expect(array_column($activity['slots'], 'name'))->toBe(['Row 1', 'Row 2'])
        ->and(array_column($activity['items'], 'speechId'))->toBe(['i1:label', 'i2:label'])
        ->and($activity['shuffle'])->toBeTrue();
});
