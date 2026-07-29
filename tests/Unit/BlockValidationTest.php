<?php

use App\Blocks\BlockTypeRegistry;
use Illuminate\Validation\ValidationException;

test('a quiz answer_id not among that question\'s options fails validation', function () {
    $quiz = app(BlockTypeRegistry::class)->get('quiz');

    $config = $quiz->defaultConfig();
    $config['questions'][0]['answer_id'] = 'not-one-of-the-options';

    expect(fn () => $quiz->validateConfig($config))->toThrow(ValidationException::class);
});

test('a quiz answer_id belonging to a DIFFERENT question fails validation', function () {
    $quiz = app(BlockTypeRegistry::class)->get('quiz');

    $config = $quiz->defaultConfig();
    $config['questions'][] = [
        'id' => 'question-2',
        'prompt' => 'Second question?',
        'options' => [
            ['id' => 'q2-option-1', 'text' => 'Yes'],
            ['id' => 'q2-option-2', 'text' => 'No'],
        ],
        // References an option that exists, but on question 1.
        'answer_id' => 'option-1',
        'feedback' => null,
        'source_ref' => null,
    ];

    expect(fn () => $quiz->validateConfig($config))->toThrow(ValidationException::class);
});

test('a hotspot answer_id not in the bank fails validation', function () {
    $labeling = app(BlockTypeRegistry::class)->get('image_labeling');

    $config = $labeling->defaultConfig();
    $config['hotspots'][0]['answer_id'] = 'not-in-bank';

    expect(fn () => $labeling->validateConfig($config))->toThrow(ValidationException::class);
});

test('hotspot coordinates outside 0-100 fail validation', function (float $x, float $y) {
    $labeling = app(BlockTypeRegistry::class)->get('image_labeling');

    $config = $labeling->defaultConfig();
    $config['hotspots'][0]['x_pct'] = $x;
    $config['hotspots'][0]['y_pct'] = $y;

    expect(fn () => $labeling->validateConfig($config))->toThrow(ValidationException::class);
})->with([
    'x too high' => [100.1, 50.0],
    'x negative' => [-0.1, 50.0],
    'y too high' => [50.0, 101.0],
    'y negative' => [50.0, -5.0],
]);

test('boundary coordinates 0 and 100 are accepted', function () {
    $labeling = app(BlockTypeRegistry::class)->get('image_labeling');

    $config = $labeling->defaultConfig();
    $config['hotspots'][0]['x_pct'] = 0;
    $config['hotspots'][0]['y_pct'] = 100;

    expect($labeling->validateConfig($config))->toBeArray();
});

test('duplicate hotspot numbers fail validation', function () {
    $labeling = app(BlockTypeRegistry::class)->get('image_labeling');

    $config = $labeling->defaultConfig();
    $config['bank'][] = ['id' => 'bank-2', 'label' => 'Second label'];
    $config['hotspots'][] = [
        'id' => 'hotspot-2',
        'number' => 1, // duplicate of hotspot-1's number
        'x_pct' => 10,
        'y_pct' => 10,
        'answer_id' => 'bank-2',
        'description' => null,
    ];

    expect(fn () => $labeling->validateConfig($config))->toThrow(ValidationException::class);
});

test('duplicate stable IDs within a block fail validation', function (string $typeKey, callable $mutate) {
    $type = app(BlockTypeRegistry::class)->get($typeKey);

    $config = $mutate($type->defaultConfig());

    expect(fn () => $type->validateConfig($config))->toThrow(ValidationException::class);
})->with([
    'quiz question ids' => [
        'quiz',
        function (array $config) {
            $duplicate = $config['questions'][0];
            $duplicate['options'] = [
                ['id' => 'other-1', 'text' => 'A'],
                ['id' => 'other-2', 'text' => 'B'],
            ];
            $duplicate['answer_id'] = 'other-1';
            $config['questions'][] = $duplicate; // same question id twice

            return $config;
        },
    ],
    'quiz option ids within a question' => [
        'quiz',
        function (array $config) {
            $config['questions'][0]['options'][1]['id'] = $config['questions'][0]['options'][0]['id'];

            return $config;
        },
    ],
    'matching pair ids' => [
        'matching',
        function (array $config) {
            $config['pairs'][1]['id'] = $config['pairs'][0]['id'];

            return $config;
        },
    ],
    'vocabulary term ids' => [
        'vocabulary_cards',
        function (array $config) {
            $config['terms'][] = $config['terms'][0];

            return $config;
        },
    ],
    'cer field ids' => [
        'cer',
        function (array $config) {
            $config['fields'][1]['id'] = $config['fields'][0]['id'];

            return $config;
        },
    ],
    'image labeling bank ids' => [
        'image_labeling',
        function (array $config) {
            $config['bank'][] = $config['bank'][0];
            $config['hotspots'][0]['number'] = 1;

            return $config;
        },
    ],
]);

test('empty nested stable IDs fail validation', function () {
    $matching = app(BlockTypeRegistry::class)->get('matching');

    $config = $matching->defaultConfig();
    $config['pairs'][0]['id'] = '  ';

    expect(fn () => $matching->validateConfig($config))->toThrow(ValidationException::class);
});

test('static table rows with the wrong cell count fail validation', function () {
    $table = app(BlockTypeRegistry::class)->get('static_table');

    $config = $table->defaultConfig();
    $config['rows'][] = ['only one cell'];

    expect(fn () => $table->validateConfig($config))->toThrow(ValidationException::class);
});
