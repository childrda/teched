<?php

use App\Blocks\BlockTypeRegistry;
use App\Enums\ClassRole;
use App\Enums\PageCompletionType;
use App\Models\BlockState;
use App\Models\BlockSubmission;
use App\Models\ClassMembership;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\AttemptStateFormatter;
use App\Services\LessonPublisher;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * One lesson carrying every stateful block type plus a purely informational
 * one, so "does this block deserve a state section at all?" is a real question
 * on the rendered page rather than a foregone conclusion.
 */
function responseRenderingFixture(): array
{
    $registry = app(BlockTypeRegistry::class);

    $teacher = asTeacher();
    $student = User::factory()->create();
    $student->refresh();

    $lesson = Lesson::factory()->create();
    $page = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 1,
        'completion_type' => PageCompletionType::View,
        'title' => 'Everything page',
    ]);

    $position = 1;
    $make = function (string $type, array $config, ?array $grading = null) use (&$position, $page) {
        LessonBlock::factory()->create([
            'lesson_page_id' => $page->id,
            'type' => $type,
            'position' => $position++,
            'config' => $config,
            'grading' => $grading,
        ]);
    };

    // Content-only: holds no student state, collects nothing.
    $make('rich_text', ['html' => '<p>Read this first.</p>']);

    $make('matching', [
        'instructions' => 'Match each term to its description.',
        'shuffle' => false,
        'bank' => [
            ['id' => 'mi-weld', 'label' => 'Welding'],
            ['id' => 'mi-arc', 'label' => 'Arc'],
        ],
        'slots' => [
            ['id' => 'ms-1', 'description' => 'Joining metal permanently', 'answer_id' => 'mi-weld'],
            ['id' => 'ms-2', 'description' => 'The electrical discharge', 'answer_id' => 'mi-arc'],
        ],
    ], array_merge(fullGradingShape('all_correct'), ['max_attempts' => 3]));

    $make('image_labeling', [
        'image_url' => 'https://example.com/diagram.png',
        'image_alt' => 'Diagram',
        'long_description' => null,
        'instructions' => 'Label the diagram.',
        'hotspots' => [
            ['id' => 'hs-1', 'number' => 1, 'x_pct' => 25, 'y_pct' => 25, 'answer_id' => 'bank-torch', 'description' => 'The tool that carries current'],
        ],
        'bank' => [
            ['id' => 'bank-torch', 'label' => 'Torch'],
            ['id' => 'bank-plate', 'label' => 'Base plate'],
        ],
    ], array_merge(fullGradingShape('all_correct'), ['max_attempts' => 3]));

    $make('quiz', [
        'shuffle_questions' => false,
        'questions' => [[
            'id' => 'q-hot',
            'prompt' => 'How hot can a welding arc get?',
            'options' => [
                ['id' => 'q-a', 'text' => 'About 250 degrees'],
                ['id' => 'q-b', 'text' => 'Up to 6,500 degrees'],
            ],
            'answer_id' => 'q-b',
            'feedback' => null,
            'source_ref' => null,
        ]],
    ], array_merge(fullGradingShape('all_correct'), [
        'max_attempts' => 3,
        'reveal_policy' => 'never',
        'reveal_answers' => false,
    ]));

    $make('cer', [
        'scenario_html' => '<p>A bridge joint has failed.</p>',
        'fields' => [
            ['id' => 'claim', 'label' => 'Claim', 'placeholder' => null, 'min_length' => null],
            ['id' => 'evidence', 'label' => 'Evidence', 'placeholder' => null, 'min_length' => null],
            ['id' => 'reasoning', 'label' => 'Reasoning', 'placeholder' => null, 'min_length' => null],
        ],
    ]);

    $make('short_response', [
        'prompt_html' => '<p>What did you learn?</p>',
        'placeholder' => null,
        'min_length' => null,
        'rubric_html' => null,
    ]);

    app(LessonPublisher::class)->publish($lesson, $teacher);
    $lesson = $lesson->fresh();
    $version = $lesson->currentVersion();

    $class = SchoolClass::query()->create([
        'name' => 'Response Rendering Class',
        'teacher_id' => $teacher->id,
        'school_year' => '2026-2027',
        'active' => true,
    ]);

    foreach ([[$teacher, ClassRole::Teacher], [$student, ClassRole::Student]] as [$user, $role]) {
        ClassMembership::query()->create([
            'school_class_id' => $class->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    $assignment = LessonAssignment::query()->create([
        'school_class_id' => $class->id,
        'lesson_id' => $lesson->id,
        'lesson_version_id' => $version->id,
        'assigned_by_user_id' => $teacher->id,
        'available_at' => now()->subDay(),
        'due_at' => now()->addWeek(),
    ]);

    asStudent($student);
    $attempt = app(AttemptService::class)->resolveForAssignment($student, $assignment)['attempt'];

    $blocks = [];

    foreach ($attempt->lessonVersion->manifest['pages'] as $manifestPage) {
        foreach ($manifestPage['blocks'] ?? [] as $block) {
            $blocks[$block['type']] = $block;
        }
    }

    return compact('teacher', 'student', 'lesson', 'attempt', 'blocks');
}

function writeState(array $fx, string $type, array $state): void
{
    BlockState::query()->create([
        'lesson_attempt_id' => $fx['attempt']->id,
        'block_id' => $fx['blocks'][$type]['block_id'],
        'block_type' => $type,
        'state' => $state,
        'revision' => 1,
    ]);
}

function writeSubmission(array $fx, string $type, array $response, array $overrides = []): BlockSubmission
{
    return BlockSubmission::query()->create(array_merge([
        'lesson_attempt_id' => $fx['attempt']->id,
        'lesson_version_id' => $fx['attempt']->lesson_version_id,
        'block_id' => $fx['blocks'][$type]['block_id'],
        'block_type' => $type,
        'attempt_number' => 1,
        'response' => $response,
        'grading_result' => null,
        'score' => null,
        'max_score' => null,
        'percentage' => null,
        'passed' => null,
        'requires_manual_review' => false,
        'active_seconds_at_submission' => 0,
        'submitted_at' => now(),
    ], $overrides));
}

function staffAttemptHtml(array $fx): string
{
    return test()->actingAs($fx['teacher'])
        ->get(route('staff.attempts.show', $fx['attempt']))
        ->assertOk()
        ->getContent();
}

test('a content-only block renders no current work or submitted history section', function () {
    $fx = responseRenderingFixture();
    $html = staffAttemptHtml($fx);

    $richTextId = $fx['blocks']['rich_text']['block_id'];
    $start = strpos($html, 'id="block-'.$richTextId.'"');

    expect($start)->not->toBeFalse();

    // Everything from this block's heading up to the next block's section.
    $rest = substr($html, $start);
    $end = strpos($rest, '<section', 1);
    $section = $end === false ? $rest : substr($rest, 0, $end);

    expect($section)->not->toContain(__('staff.current_work'))
        ->and($section)->not->toContain(__('staff.submitted_history'))
        ->and($section)->not->toContain(__('staff.current_work_empty'))
        ->and($section)->not->toContain(__('staff.submitted_history_empty'));
});

test('a state-holding block still renders both sections', function () {
    $fx = responseRenderingFixture();
    $html = staffAttemptHtml($fx);

    // Present once per stateful block: matching, image labeling, quiz, cer,
    // short response — and for none of the content-only ones.
    expect(substr_count($html, __('staff.current_work')))->toBe(5)
        ->and(substr_count($html, __('staff.submitted_history')))->toBe(5);
});

test('matching placements resolve to slot description and bank label, not raw keys', function () {
    $fx = responseRenderingFixture();

    writeState($fx, 'matching', ['placements' => ['ms-1' => 'mi-weld', 'ms-2' => null]]);

    $html = staffAttemptHtml($fx);

    expect($html)->toContain('Joining metal permanently')
        ->and($html)->toContain('Welding')
        ->and($html)->toContain(__('staff.response_not_placed'))
        ->and($html)->not->toContain('"ms-1"')
        ->and($html)->not->toContain('mi-weld');
});

test('a matching submission stored under matches resolves the same way', function () {
    $fx = responseRenderingFixture();

    // MatchingBlock::grade() reads `matches`, not the `placements` its own
    // validateState() writes. A formatter built only against `placements`
    // passes every other type and silently fails right here.
    writeSubmission($fx, 'matching', ['matches' => ['ms-2' => 'mi-arc']]);

    $html = staffAttemptHtml($fx);

    expect($html)->toContain('The electrical discharge')
        ->and($html)->toContain('Arc')
        ->and($html)->not->toContain('mi-arc')
        ->and($html)->not->toContain(__('staff.response_unrecognized'));
});

test('image labeling resolves hotspot description to bank label', function () {
    $fx = responseRenderingFixture();

    writeSubmission($fx, 'image_labeling', ['placements' => ['hs-1' => 'bank-torch']]);

    $html = staffAttemptHtml($fx);

    expect($html)->toContain('The tool that carries current')
        ->and($html)->toContain('Torch')
        ->and($html)->not->toContain('bank-torch');
});

test('quiz answers resolve to question prompt and option text', function () {
    $fx = responseRenderingFixture();

    writeSubmission($fx, 'quiz', ['answers' => ['q-hot' => 'q-b']]);

    $html = staffAttemptHtml($fx);

    expect($html)->toContain('How hot can a welding arc get?')
        ->and($html)->toContain('Up to 6,500 degrees')
        ->and($html)->not->toContain('"q-b"');
});

test('a cer submission renders its configured field labels as text', function () {
    $fx = responseRenderingFixture();

    writeSubmission($fx, 'cer', ['values' => [
        'claim' => 'The joint was under-welded.',
        'evidence' => 'The bead is thin along the seam.',
        'reasoning' => 'Thin beads carry less load.',
    ]], ['requires_manual_review' => true]);

    $html = staffAttemptHtml($fx);

    expect($html)->toContain('Claim')
        ->and($html)->toContain('The joint was under-welded.')
        ->and($html)->toContain('Evidence')
        ->and($html)->toContain('The bead is thin along the seam.')
        ->and($html)->toContain('Reasoning')
        ->and($html)->toContain('Thin beads carry less load.')
        // Not a JSON object, and not back inside a <pre>.
        ->and($html)->not->toContain('"claim"');
});

test('short response renders the sentence, not a value wrapper', function () {
    $fx = responseRenderingFixture();

    writeState($fx, 'short_response', ['value' => 'I learned how arcs melt steel.']);

    $html = staffAttemptHtml($fx);

    expect($html)->toContain('I learned how arcs melt steel.')
        ->and($html)->not->toContain('"value"');
});

test('one stale key falls back alone while the rest of the response still resolves', function () {
    $fx = responseRenderingFixture();

    writeSubmission($fx, 'matching', ['placements' => [
        'ms-1' => 'mi-weld',
        'ms-legacy' => 'mi-old',
    ]]);

    $html = staffAttemptHtml($fx);

    expect($html)->toContain('Joining metal permanently')
        ->and($html)->toContain('Welding')
        ->and($html)->toContain(__('staff.unknown_slot', ['id' => 'ms-legacy']))
        ->and($html)->toContain(__('staff.unknown_bank_item', ['id' => 'mi-old']))
        ->and($html)->toContain(__('staff.response_partly_unresolved'))
        // Partial resolution is per item — the whole response must not
        // collapse into a raw dump because one key went stale.
        ->and($html)->not->toContain(__('staff.response_unrecognized'));
});

test('an unrecognized response shape falls back to labelled raw json', function () {
    $fx = responseRenderingFixture();

    writeSubmission($fx, 'quiz', ['totally' => 'unexpected']);

    $html = staffAttemptHtml($fx);

    expect($html)->toContain(__('staff.response_unrecognized'))
        ->and($html)->toContain('totally');
});

test('the review panel public and private treatment is unchanged', function () {
    $fx = responseRenderingFixture();

    writeSubmission($fx, 'cer', ['values' => [
        'claim' => 'c', 'evidence' => 'e', 'reasoning' => 'r',
    ]], ['requires_manual_review' => true]);

    $html = staffAttemptHtml($fx);

    // Copy and colour both come from the untouched manual-review partial:
    // emerald for what the student sees, rose for what they never do.
    expect($html)->toContain(__('staff.feedback_for_student'))
        ->and($html)->toContain(__('staff.private_note_label'))
        ->and($html)->toContain('text-emerald-900')
        ->and($html)->toContain('text-rose-900');
});

test('the formatter returns one shape for every block type', function () {
    $formatter = app(AttemptStateFormatter::class);
    $keys = ['mode', 'items', 'raw', 'has_unresolved_values'];

    $cases = [
        ['short_response', ['value' => 'x'], []],
        ['cer', ['values' => ['claim' => 'x']], ['fields' => [['id' => 'claim', 'label' => 'Claim']]]],
        ['matching', ['placements' => []], ['slots' => [], 'bank' => []]],
        ['image_labeling', ['placements' => []], ['hotspots' => [], 'bank' => []]],
        ['quiz', ['answers' => []], ['questions' => []]],
        ['rich_text', ['anything' => 1], []],
        ['quiz', null, []],
    ];

    foreach ($cases as [$type, $payload, $config]) {
        $result = $formatter->format($type, $payload, $config);

        expect(array_keys($result))->toEqualCanonicalizing($keys, "shape drifted for {$type}");
    }
});
