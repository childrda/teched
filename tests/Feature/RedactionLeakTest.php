<?php

use App\Blocks\BlockTypeRegistry;
use App\Models\Lesson;
use Database\Seeders\WeldingLessonSeeder;

beforeEach(fn () => asStudent());

/**
 * Redaction has to survive more than a search for forbidden key names.
 *
 * These tests take only what a student's browser actually receives, rebuild
 * a complete answer from it using each mechanical shortcut the payload
 * allows, and then grade that answer with the real key. Any score above zero
 * means the answer key shipped, whatever the key names say.
 *
 * Reasoning from the content is not a leak. A hotspot described as "vertical
 * tool at the top that carries electricity" is meant to lead a student to
 * "Electrode"; that is the activity working as designed. What must never be
 * possible is reaching the answer from ids, ordering, or structure.
 */

/**
 * blockOfType(), idStem(), and placementGuesses() live in tests/Pest.php:
 * the renderer tests reconstruct answers the same way, from the payload the
 * browser is initialised with.
 */

/** [block type, response key, slot list key] for the placement types. */
dataset('placement blocks', [
    'matching' => ['matching', 'matches', 'slots'],
    'image labeling' => ['image_labeling', 'placements', 'hotspots'],
]);

test('a placement answer rebuilt from what students receive scores nothing', function (
    string $typeKey,
    string $responseKey,
    string $slotKey
) {
    $this->seed(WeldingLessonSeeder::class);

    $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
    $manifest = $lesson->currentVersion()->manifest;
    $payload = $this->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();

    $compiled = blockOfType($manifest['pages'], $typeKey)['config'];
    $redacted = blockOfType($payload['pages'], $typeKey)['config'];

    $type = app(BlockTypeRegistry::class)->get($typeKey);

    foreach (placementGuesses($redacted[$slotKey], $redacted['bank']) as $assumption => $matches) {
        $result = $type->grade($compiled, fullGradingShape(), [$responseKey => $matches]);

        // A two-slot activity cannot hide a coin flip; the seeded blocks
        // have seven and ten, so a single correct guess is already a leak.
        expect($result['max_score'])->toBeGreaterThan(2)
            ->and($result['score'])->toBe(0, "Redaction leaked: {$assumption}.");
    }
})->with('placement blocks');

test('a quiz answer rebuilt from what students receive scores nothing', function () {
    $this->seed(WeldingLessonSeeder::class);

    $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
    $manifest = $lesson->currentVersion()->manifest;
    $payload = $this->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();

    $compiled = blockOfType($manifest['pages'], 'quiz')['config'];
    $redacted = blockOfType($payload['pages'], 'quiz')['config'];

    // Option order is deliberately authored — a distractor like "all of the
    // above" has to stay last — so position is not a property redaction can
    // control here. What must not survive is any link between a question's
    // id and the id of the option that answers it.
    $guesses = [
        'an option id repeats its question id' => function (array $question) {
            $optionIds = array_column($question['options'], 'id');

            return in_array($question['id'], $optionIds, true) ? $question['id'] : null;
        },
        'a question id and an option id share a stem' => function (array $question) {
            foreach (array_column($question['options'], 'id') as $optionId) {
                if (idStem($question['id']) === idStem($optionId)) {
                    return $optionId;
                }
            }

            return null;
        },
    ];

    $type = app(BlockTypeRegistry::class)->get('quiz');

    foreach ($guesses as $assumption => $choose) {
        $answers = [];

        foreach ($redacted['questions'] as $question) {
            $answers[$question['id']] = $choose($question);
        }

        $result = $type->grade($compiled, fullGradingShape(), ['answers' => $answers]);

        expect($result['max_score'])->toBeGreaterThan(2)
            ->and($result['score'])->toBe(0, "Redaction leaked: {$assumption}.");
    }
});

test('what students receive does not change when the answers do', function (
    string $typeKey,
    string $responseKey,
    string $slotKey
) {
    $this->seed(WeldingLessonSeeder::class);

    $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
    $compiled = blockOfType($lesson->currentVersion()->manifest['pages'], $typeKey)['config'];

    // Rotating the assignment keeps the config valid — the same items still
    // answer the same set of slots — while changing every answer.
    $answerIds = array_column($compiled[$slotKey], 'answer_id');

    $rotated = $compiled;
    $rotated[$slotKey] = array_map(
        fn (array $slot, int $index) => [
            ...$slot,
            'answer_id' => $answerIds[($index + 1) % count($answerIds)],
        ],
        $compiled[$slotKey],
        array_keys($compiled[$slotKey])
    );

    $type = app(BlockTypeRegistry::class)->get($typeKey);

    expect($rotated)->not->toBe($compiled)
        ->and($type->redactConfig($rotated))->toBe($type->redactConfig($compiled));
})->with('placement blocks');

test('what students receive of a quiz does not change when the answers do', function () {
    $this->seed(WeldingLessonSeeder::class);

    $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
    $compiled = blockOfType($lesson->currentVersion()->manifest['pages'], 'quiz')['config'];

    $rotated = $compiled;
    $rotated['questions'] = array_map(function (array $question) {
        $optionIds = array_column($question['options'], 'id');
        $moved = array_values(array_diff($optionIds, [$question['answer_id']]));

        return [...$question, 'answer_id' => $moved[0]];
    }, $compiled['questions']);

    $type = app(BlockTypeRegistry::class)->get('quiz');

    expect($rotated)->not->toBe($compiled)
        ->and($type->redactConfig($rotated))->toBe($type->redactConfig($compiled));
});

/**
 * The seeded content could pass the positional guess by luck. This builds
 * the worst case on purpose: an author who enters the bank slot by slot, so
 * that authored order *is* the answer key, and checks that compiling refuses
 * to publish that order.
 */
test('compiling a bank does not preserve the order an author entered it in', function () {
    $authored = [
        'instructions' => 'Match each term to its description.',
        'bank' => [
            ['id' => 'i-zinc', 'label' => 'Zinc'],
            ['id' => 'i-yield', 'label' => 'Yield'],
            ['id' => 'i-xenon', 'label' => 'Xenon'],
            ['id' => 'i-weld', 'label' => 'Weld'],
            ['id' => 'i-vise', 'label' => 'Vise'],
            ['id' => 'i-under', 'label' => 'Undercut'],
        ],
        'slots' => [
            ['id' => 's-1', 'description' => 'First description', 'answer_id' => 'i-zinc'],
            ['id' => 's-2', 'description' => 'Second description', 'answer_id' => 'i-yield'],
            ['id' => 's-3', 'description' => 'Third description', 'answer_id' => 'i-xenon'],
            ['id' => 's-4', 'description' => 'Fourth description', 'answer_id' => 'i-weld'],
            ['id' => 's-5', 'description' => 'Fifth description', 'answer_id' => 'i-vise'],
            ['id' => 's-6', 'description' => 'Sixth description', 'answer_id' => 'i-under'],
        ],
        'shuffle' => true,
    ];

    $type = app(BlockTypeRegistry::class)->get('matching');

    $compiled = $type->compileConfig($type->validateConfig($authored));
    $redacted = $type->redactConfig($compiled);

    $positional = placementGuesses($redacted['slots'], $redacted['bank'])['the nth bank item answers the nth slot'];

    expect($type->grade($compiled, fullGradingShape(), ['matches' => $positional])['score'])->toBe(0);
});
