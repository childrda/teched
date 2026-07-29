<?php

use App\Blocks\BlockTypeRegistry;
use App\Models\Lesson;
use Database\Seeders\WeldingLessonSeeder;

// The player view loads built assets; these tests are about its markup.
beforeEach(function () {
    $this->withoutVite();
    asStudent();
    $this->seed(WeldingLessonSeeder::class);
});

function weldingLesson(): Lesson
{
    return Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
}

/**
 * Every payload an Alpine placement controller is initialised with, decoded
 * out of the rendered page. This is exactly what the browser gets, so it is
 * what any claim about "no answer mapping in the client" has to be made of.
 *
 * @js() renders an array as JSON.parse('…') with the inner JSON escaped, so
 * unwrapping it takes two passes: one to undo the escaping, one to decode.
 *
 * @return list<array<string, mixed>>
 */
function placementPayloads(string $html): array
{
    preg_match_all('/x-data="placementActivity\(JSON\.parse\(\'(.*?)\'\)\)"/s', $html, $matches);

    return array_map(function (string $escaped) {
        $json = json_decode('"' . $escaped . '"', flags: JSON_THROW_ON_ERROR);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }, $matches[1]);
}

/**
 * @param array<int, array<string, mixed>> $pages
 * @return array<string, mixed>
 */
function manifestBlock(array $pages, string $blockId): array
{
    foreach ($pages as $page) {
        foreach ($page['blocks'] ?? [] as $block) {
            if (($block['block_id'] ?? null) === $blockId) {
                return $block;
            }
        }
    }

    throw new RuntimeException("No block {$blockId} in the manifest.");
}

test('the seeded activity pages render through their own partials', function () {
    $lesson = weldingLesson();

    $html = $this->get("/lessons/{$lesson->code}")->assertOk()->getContent();

    expect(view()->exists('lesson-player.blocks.matching'))->toBeTrue()
        ->and(view()->exists('lesson-player.blocks.image_labeling'))->toBeTrue()
        ->and($html)->toContain('data-block-type="matching"')
        ->and($html)->toContain('data-block-type="image_labeling"');

    // The seeded lesson also carries the block types Phase 2C brings, and
    // those do fall back. Counting rather than asserting absence keeps this
    // test honest about which blocks are still waiting for a renderer.
    $awaitingRenderer = 0;

    foreach ($lesson->currentVersion()->manifest['pages'] as $page) {
        foreach ($page['blocks'] as $block) {
            if (! view()->exists("lesson-player.blocks.{$block['type']}")) {
                $awaitingRenderer += 1;

                expect($block['type'])->not->toBeIn(['matching', 'image_labeling']);
            }
        }
    }

    expect(substr_count($html, 'This content is currently unavailable'))->toBe($awaitingRenderer);
});

test('both layers of the image activity place into the same slots', function () {
    $html = $this->get('/lessons/' . weldingLesson()->code)->assertOk()->getContent();

    $hotspots = blockOfType(
        $this->getJson('/api/lessons/' . weldingLesson()->code)->json()['pages'],
        'image_labeling'
    )['config']['hotspots'];

    // A marker on the diagram and a row in the list, per hotspot, both
    // carrying the one slot id: nothing to keep in step by hand.
    expect(substr_count($html, 'data-placement-layer="hotspots"'))->toBe(1)
        ->and(substr_count($html, 'data-placement-layer="list"'))->toBe(1);

    foreach ($hotspots as $hotspot) {
        expect(substr_count($html, 'data-slot-id="' . $hotspot['id'] . '"'))
            ->toBe(2, "hotspot {$hotspot['id']} should be drawn in both layers");
    }
});

test('the payload the browser is initialised with carries no answer mapping', function () {
    $lesson = weldingLesson();

    $html = $this->get("/lessons/{$lesson->code}")->assertOk()->getContent();
    $payloads = placementPayloads($html);

    expect($payloads)->toHaveCount(2);

    expect($html)->not->toContain('answer_id');

    foreach ($payloads as $payload) {
        assertNoForbiddenKeys($payload);

        // Grade the payload against the real key using every mechanical
        // shortcut it offers. Anything above zero means the markup handed a
        // student what redaction was there to withhold.
        $block = manifestBlock($lesson->currentVersion()->manifest['pages'], $payload['blockId']);

        $compiled = $block['config'];
        $type = app(BlockTypeRegistry::class)->get($block['type']);
        $responseKey = $block['type'] === 'matching' ? 'matches' : 'placements';

        foreach (placementGuesses($payload['slots'], $payload['items']) as $assumption => $matches) {
            $result = $type->grade($compiled, fullGradingShape(), [$responseKey => $matches]);

            expect($result['score'])->toBe(0, "The Alpine payload leaked: {$assumption}.");
        }
    }
});

test('read-aloud reaches both activities through the shared wrapper', function () {
    $lesson = weldingLesson();

    $payload = $this->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();
    $html = $this->get("/lessons/{$lesson->code}")->assertOk()->getContent();

    foreach (['matching', 'image_labeling'] as $typeKey) {
        $block = blockOfType($payload['pages'], $typeKey);

        expect($block['speech'])->not->toBe([], "{$typeKey} should have speech segments");

        // Exactly one read-aloud control, drawn by the wrapper: neither
        // renderer adds a second, and the wrapper special-cases neither.
        expect(substr_count($html, "toggleReadAloud('{$block['block_id']}')"))->toBe(1);
    }
});

test('nothing spoken aloud reveals which item answers which slot', function () {
    $lesson = weldingLesson();

    $manifest = $lesson->currentVersion()->manifest;
    $payload = $this->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();

    $cases = [
        ['matching', 'matches', 'slots'],
        ['image_labeling', 'placements', 'hotspots'],
    ];

    foreach ($cases as [$typeKey, $responseKey, $slotKey]) {
        $compiled = blockOfType($manifest['pages'], $typeKey)['config'];
        $block = blockOfType($payload['pages'], $typeKey);
        $type = app(BlockTypeRegistry::class)->get($typeKey);

        // Spoken text is what a student hears, and no id belongs in it.
        foreach ($block['speech'] as $segment) {
            foreach (array_column($compiled[$slotKey], 'answer_id') as $answerId) {
                expect($segment['text'])->not->toContain($answerId);
            }
        }

        // Segment ids are built from item and slot ids, so the same
        // reconstruction has to come up empty there too.
        $spokenIds = array_map(
            fn (array $segment) => ['id' => strstr($segment['id'], ':', true) ?: $segment['id']],
            $block['speech']
        );

        foreach (placementGuesses($compiled[$slotKey], $spokenIds) as $assumption => $matches) {
            $result = $type->grade($compiled, fullGradingShape(), [$responseKey => $matches]);

            expect($result['score'])->toBe(0, "Speech ids leaked: {$assumption}.");
        }
    }
});
