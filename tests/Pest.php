<?php

use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\User;
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
 * Authenticate as a student for protected player / API / grading routes.
 * Role comes from the database default (student) — not mass-assigned.
 */
function asStudent(?User $user = null): User
{
    $user ??= User::factory()->create();

    // Role comes from the column default and is not fillable, so refresh
    // before callers assert isStudent() on the returned model.
    $user->refresh();

    test()->actingAs($user);

    return $user;
}

/** Authenticate as a teacher (role set explicitly — not mass-assignable). */
function asTeacher(?User $user = null): User
{
    $user ??= User::factory()->create();
    $user->forceFill(['role' => App\Enums\UserRole::Teacher])->save();
    $user->refresh();
    test()->actingAs($user);

    return $user;
}

/** Authenticate as an admin (role set explicitly — not mass-assignable). */
function asAdmin(?User $user = null): User
{
    $user ??= User::factory()->create();
    $user->forceFill(['role' => App\Enums\UserRole::Admin])->save();
    $user->refresh();
    test()->actingAs($user);

    return $user;
}

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
/**
 * Student-facing payloads must never leak answer keys, rubrics, source refs,
 * or internal details[]. Feedback is forbidden everywhere except inside a
 * reveal.items[] entry (earned disclosure).
 */
function assertNoForbiddenKeys(array $data): void
{
    $alwaysForbidden = ['answer_id', 'rubric_html', 'source_ref', 'details'];

    $walk = function ($value, string $path) use (&$walk, $alwaysForbidden) {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $childPath = is_string($key) ? "{$path}.{$key}" : "{$path}[{$key}]";

            if (is_string($key)) {
                $lower = strtolower($key);

                expect(in_array($lower, $alwaysForbidden, true))
                    ->toBeFalse("Forbidden key \"{$key}\" found at {$childPath}");

                if ($lower === 'feedback') {
                    $inRevealItem = (bool) preg_match('/\.reveal\.items\[\d+\]$/', $path);
                    expect($inRevealItem)
                        ->toBeTrue("Forbidden key \"feedback\" found at {$childPath}");
                }
            }

            $walk($child, $childPath);
        }
    };

    $walk($data, '$');
}

/**
 * Finds the first block of the given type in a manifest, an API payload, or
 * anything else shaped like a list of pages.
 *
 * @param array<int, array<string, mixed>> $pages
 * @return array<string, mixed>
 */
function blockOfType(array $pages, string $type): array
{
    foreach ($pages as $page) {
        foreach ($page['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) === $type) {
                return $block;
            }
        }
    }

    throw new RuntimeException("No {$type} block to inspect.");
}

/** 'bank-weld-pool' and 'hs-weld-pool' both stem from 'weld-pool'. */
function idStem(string $id): string
{
    $parts = preg_split('/[-_:.]/', mb_strtolower($id));

    return count($parts) > 1 ? implode('-', array_slice($parts, 1)) : $parts[0];
}

/**
 * Every placement a student could construct knowing only ids and order,
 * keyed by the assumption each one exploits. Used to prove that no such
 * assumption pays off: see tests/Feature/RedactionLeakTest.php.
 *
 * @param array<int, array<string, mixed>> $slots
 * @param array<int, array<string, mixed>> $bank
 * @return array<string, array<string, ?string>>
 */
function placementGuesses(array $slots, array $bank): array
{
    $slotIds = array_column($slots, 'id');
    $itemIds = array_column($bank, 'id');

    $pairBy = function (callable $choose) use ($slotIds) {
        $matches = [];

        foreach ($slotIds as $index => $slotId) {
            $matches[$slotId] = $choose($slotId, $index);
        }

        return $matches;
    };

    return [
        'a slot id doubles as its own answer' => $pairBy(
            fn (string $slotId) => in_array($slotId, $itemIds, true) ? $slotId : null
        ),
        'the nth bank item answers the nth slot' => $pairBy(
            fn (string $slotId, int $index) => $itemIds[$index] ?? null
        ),
        'a slot id and an item id share a stem' => $pairBy(
            function (string $slotId) use ($itemIds) {
                foreach ($itemIds as $itemId) {
                    if (idStem($slotId) === idStem($itemId)) {
                        return $itemId;
                    }
                }

                return null;
            }
        ),
    ];
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
        'reveal_policy' => 'never',
        'reveal_answers' => false,
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
