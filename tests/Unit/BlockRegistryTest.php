<?php

use App\Blocks\BlockTypeRegistry;
use App\Exceptions\UnknownBlockTypeException;

const EXPECTED_CAPABILITIES = [
    // type => [isAutoGradable, collectsResponse]
    'rich_text' => [false, false],
    'image' => [false, false],
    'video' => [false, false],
    'file_link' => [false, false],
    'callout' => [false, false],
    'static_table' => [false, false],
    'vocabulary_cards' => [false, false],
    'matching' => [true, true],
    'image_labeling' => [true, true],
    'short_response' => [false, true],
    'cer' => [false, true],
    'quiz' => [true, true],
];

test('all 12 block types are registered', function () {
    $registry = app(BlockTypeRegistry::class);

    expect(array_keys($registry->all()))->toEqualCanonicalizing(array_keys(EXPECTED_CAPABILITIES));
});

test('every registered type round-trips defaultConfig through validate, compile, and redact', function () {
    foreach (app(BlockTypeRegistry::class)->all() as $key => $type) {
        $validated = $type->validateConfig($type->defaultConfig());
        $compiled = $type->compileConfig($validated);
        $redacted = $type->redactConfig($compiled);

        expect($validated)->toBeArray("validateConfig failed for {$key}")
            ->and($compiled)->toBeArray("compileConfig failed for {$key}")
            ->and($redacted)->toBeArray("redactConfig failed for {$key}");
    }
});

test('the capability matrix is correct for all 12 types', function () {
    $registry = app(BlockTypeRegistry::class);

    foreach (EXPECTED_CAPABILITIES as $key => [$autoGradable, $collects]) {
        $type = $registry->get($key);

        expect($type->isAutoGradable())->toBe($autoGradable, "isAutoGradable wrong for {$key}")
            ->and($type->collectsResponse())->toBe($collects, "collectsResponse wrong for {$key}");
    }
});

test('grade() returns null for every non-auto-gradable type', function () {
    foreach (app(BlockTypeRegistry::class)->all() as $key => $type) {
        if ($type->isAutoGradable()) {
            continue;
        }

        $compiled = $type->compileConfig($type->validateConfig($type->defaultConfig()));

        expect($type->grade($compiled, fullGradingShape(), ['text' => 'anything']))
            ->toBeNull("grade() should return null for {$key}");
    }
});

test('grade() returns the standard result shape for every auto-gradable type', function () {
    foreach (app(BlockTypeRegistry::class)->all() as $key => $type) {
        if (! $type->isAutoGradable()) {
            continue;
        }

        $compiled = $type->compileConfig($type->validateConfig($type->defaultConfig()));
        $result = $type->grade($compiled, fullGradingShape(), []);

        expect($result)->toBeArray()
            ->toHaveKeys(['correct', 'score', 'max_score', 'percentage', 'passed', 'requires_manual_review', 'details']);

        foreach ($result['details'] as $detail) {
            expect($detail)->toHaveKeys(['item_id', 'correct', 'feedback']);
        }
    }
});

test('an unregistered type key throws a descriptive exception', function () {
    expect(fn () => app(BlockTypeRegistry::class)->get('teleporter'))
        ->toThrow(UnknownBlockTypeException::class, 'teleporter');
});

test('quiz grading scores correct, incorrect, and unanswered items', function () {
    $quiz = app(BlockTypeRegistry::class)->get('quiz');

    $config = $quiz->defaultConfig();
    $config['questions'][] = [
        'id' => 'question-2',
        'prompt' => 'Second?',
        'options' => [
            ['id' => 'q2-a', 'text' => 'A'],
            ['id' => 'q2-b', 'text' => 'B'],
        ],
        'answer_id' => 'q2-a',
        'feedback' => 'Because A.',
        'source_ref' => null,
    ];
    $config['questions'][] = [
        'id' => 'question-3',
        'prompt' => 'Third?',
        'options' => [
            ['id' => 'q3-a', 'text' => 'A'],
            ['id' => 'q3-b', 'text' => 'B'],
        ],
        'answer_id' => 'q3-b',
        'feedback' => null,
        'source_ref' => null,
    ];

    $compiled = $quiz->compileConfig($quiz->validateConfig($config));

    $result = $quiz->grade($compiled, fullGradingShape('min_score', 60), [
        'answers' => [
            'question-1' => 'option-1', // correct
            'question-2' => 'q2-b',     // incorrect
            'question-3' => null,       // unanswered
        ],
    ]);

    expect($result['score'])->toBe(1)
        ->and($result['max_score'])->toBe(3)
        ->and($result['percentage'])->toBe(33)
        ->and($result['correct'])->toBeFalse()
        ->and($result['passed'])->toBeFalse()
        ->and($result['requires_manual_review'])->toBeFalse()
        ->and(array_column($result['details'], 'correct'))->toBe([true, false, false]);

    // min_score rule passes at or above the threshold.
    $passing = $quiz->grade($compiled, fullGradingShape('min_score', 60), [
        'answers' => [
            'question-1' => 'option-1',
            'question-2' => 'q2-a',
            'question-3' => null,
        ],
    ]);

    expect($passing['percentage'])->toBe(67)
        ->and($passing['passed'])->toBeTrue()
        ->and($passing['correct'])->toBeFalse();
});
