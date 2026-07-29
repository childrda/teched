<?php

use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator as JsonSchemaValidator;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Validates a decoded manifest array against docs/schemas/lesson-manifest.schema.json.
 * Returns [bool $valid, string $errors].
 */
function validateManifestAgainstSchema(array $manifest): array
{
    $validator = new JsonSchemaValidator();
    $schemaId = 'https://teched.example/schemas/lesson-manifest.schema.json';

    $validator->resolver()->registerFile(
        $schemaId,
        base_path('docs/schemas/lesson-manifest.schema.json')
    );

    $result = $validator->validate(
        json_decode(json_encode($manifest)),
        $schemaId
    );

    if ($result->isValid()) {
        return [true, ''];
    }

    return [false, json_encode((new ErrorFormatter())->format($result->error()), JSON_PRETTY_PRINT)];
}

/**
 * Recursively asserts (case-insensitively) that none of the forbidden
 * answer-revealing keys appear anywhere in the given data.
 */
function assertNoForbiddenKeys(array $data): void
{
    $forbidden = ['answer_id', 'feedback', 'rubric_html', 'source_ref'];

    $walk = function ($value, string $path) use (&$walk, $forbidden) {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            if (is_string($key)) {
                expect(in_array(strtolower($key), $forbidden, true))
                    ->toBeFalse("Forbidden key \"{$key}\" found at {$path}.{$key}");
            }

            $walk($child, is_string($key) ? "{$path}.{$key}" : "{$path}[{$key}]");
        }
    };

    $walk($data, '$');
}

function fullGradingShape(string $rule = 'all_correct', ?int $minScore = null): array
{
    return [
        'rule' => $rule,
        'min_score' => $minScore,
        'allow_retry' => true,
        'max_attempts' => null,
        'show_feedback' => true,
        'record_first_attempt' => true,
        'points' => null,
    ];
}

/**
 * Builds an unpublished lesson whose pages contain every registered block
 * type, using each type's defaultConfig() (valid by contract). Gradable
 * blocks get the full standard grading shape.
 */
function createLessonWithAllBlockTypes(): Lesson
{
    $registry = app(App\Blocks\BlockTypeRegistry::class);

    $lesson = Lesson::factory()->create();

    $page = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 1,
        'title' => 'Everything Page',
    ]);

    $position = 1;

    foreach ($registry->all() as $key => $type) {
        LessonBlock::factory()->create([
            'lesson_page_id' => $page->id,
            'type' => $key,
            'position' => $position++,
            'config' => $type->defaultConfig(),
            'grading' => $type->isAutoGradable() ? fullGradingShape() : null,
        ]);
    }

    return $lesson->fresh();
}
