<?php

use App\Enums\AttemptStatus;
use App\Enums\PageCompletionType;
use App\Exceptions\ImmutableAttemptRetryGrantException;
use App\Models\AttemptRetryGrant;
use App\Models\BlockSubmission;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\LessonPublisher;
use App\Services\StudentManifest;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * @param  array<string, mixed>  $gradingOverrides
 * @return array{0: Lesson, 1: LessonAttempt, 2: array<string, mixed>, 3: string}
 */
function publishGradableQuiz(array $gradingOverrides = []): array
{
    $user = asStudent();
    $lesson = Lesson::factory()->create();
    $page = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 1,
        'completion_type' => PageCompletionType::PassActivity,
        'title' => 'Quiz page',
    ]);

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'type' => 'quiz',
        'position' => 1,
        'config' => [
            'shuffle_questions' => false,
            'questions' => [
                [
                    'id' => 'q1',
                    'prompt' => 'Pick one',
                    'options' => [
                        ['id' => 'a', 'text' => 'Alpha'],
                        ['id' => 'b', 'text' => 'Beta'],
                    ],
                    'answer_id' => 'a',
                    'feedback' => 'Alpha is right.',
                    'source_ref' => null,
                ],
            ],
        ],
        'grading' => array_merge(fullGradingShape('all_correct'), $gradingOverrides),
    ]);

    app(LessonPublisher::class)->publish($lesson, User::factory()->create());
    $lesson = $lesson->fresh();
    $attempt = app(AttemptService::class)->resolveForPlayer($user, $lesson)['attempt'];
    $quiz = blockOfType($attempt->lessonVersion->manifest['pages'], 'quiz');
    $token = app(StudentManifest::class)->forVersion($attempt->lessonVersion)['grading_token'];

    return [$lesson, $attempt, $quiz, $token];
}

function gradeQuiz(Lesson $lesson, string $blockId, string $token, string $optionId = 'a')
{
    return test()->postJson("/player/lessons/{$lesson->code}/blocks/{$blockId}/grade", [
        'version_token' => $token,
        'response' => ['q1' => $optionId],
    ]);
}

test('a submission at the limit is refused with 422 and records nothing', function () {
    [$lesson, $attempt, $quiz, $token] = publishGradableQuiz([
        'max_attempts' => 1,
        'allow_retry' => true,
    ]);

    gradeQuiz($lesson, $quiz['block_id'], $token, 'b')->assertOk();

    gradeQuiz($lesson, $quiz['block_id'], $token, 'a')
        ->assertStatus(422)
        ->assertJsonPath('message', __('quiz.no_attempts_remaining'));

    expect(BlockSubmission::query()->where('lesson_attempt_id', $attempt->id)->count())->toBe(1);
});

test('a grant of one attempt makes exactly one more submission possible', function () {
    [$lesson, $attempt, $quiz, $token] = publishGradableQuiz(['max_attempts' => 1]);

    gradeQuiz($lesson, $quiz['block_id'], $token, 'b')->assertOk();

    $teacher = User::factory()->create();
    $teacher->forceFill(['role' => App\Enums\UserRole::Teacher])->save();

    app(AttemptService::class)->grantRetries($attempt, $quiz['block_id'], 1, $teacher);

    gradeQuiz($lesson, $quiz['block_id'], $token, 'a')
        ->assertOk()
        ->assertJsonPath('attempts.used', 2)
        ->assertJsonPath('attempts.remaining', 0);

    gradeQuiz($lesson, $quiz['block_id'], $token, 'a')->assertStatus(422);

    expect(BlockSubmission::query()->where('block_id', $quiz['block_id'])->count())->toBe(2);
});

test('allow_retry false permits exactly one submission even when max_attempts is 5', function () {
    [$lesson, $attempt, $quiz, $token] = publishGradableQuiz([
        'allow_retry' => false,
        'max_attempts' => 5,
    ]);

    gradeQuiz($lesson, $quiz['block_id'], $token, 'b')->assertOk();
    gradeQuiz($lesson, $quiz['block_id'], $token, 'a')->assertStatus(422);

    expect(BlockSubmission::query()->where('block_id', $quiz['block_id'])->count())->toBe(1);
});

test('a grant on an allow_retry false block permits exactly one more submission', function () {
    [$lesson, $attempt, $quiz, $token] = publishGradableQuiz([
        'allow_retry' => false,
        'max_attempts' => 5,
    ]);

    gradeQuiz($lesson, $quiz['block_id'], $token, 'b')->assertOk();
    gradeQuiz($lesson, $quiz['block_id'], $token, 'a')->assertStatus(422);

    $teacher = User::factory()->create();
    $teacher->forceFill(['role' => App\Enums\UserRole::Teacher])->save();
    app(AttemptService::class)->grantRetries($attempt, $quiz['block_id'], 1, $teacher);

    gradeQuiz($lesson, $quiz['block_id'], $token, 'a')
        ->assertOk()
        ->assertJsonPath('attempts.used', 2)
        ->assertJsonPath('attempts.allowed', 2);

    gradeQuiz($lesson, $quiz['block_id'], $token, 'a')->assertStatus(422);
});

test('null max_attempts permits many submissions', function () {
    [$lesson, , $quiz, $token] = publishGradableQuiz([
        'max_attempts' => null,
        'allow_retry' => true,
    ]);

    for ($i = 0; $i < 4; $i++) {
        gradeQuiz($lesson, $quiz['block_id'], $token, 'b')->assertOk();
    }

    expect(BlockSubmission::query()->where('block_id', $quiz['block_id'])->count())->toBe(4);
});

test('two simultaneous submissions with one slot left record exactly one', function () {
    [$lesson, $attempt, $quiz, $token] = publishGradableQuiz(['max_attempts' => 1]);

    $ok = 0;
    $denied = 0;

    // Nested HTTP calls under one outer transaction share the attempt lock
    // path the controller uses — the second must see used >= allowed.
    DB::transaction(function () use ($lesson, $quiz, $token, &$ok, &$denied) {
        $first = gradeQuiz($lesson, $quiz['block_id'], $token, 'b');
        $second = gradeQuiz($lesson, $quiz['block_id'], $token, 'a');

        if ($first->status() === 200) {
            $ok++;
        }
        if ($second->status() === 422) {
            $denied++;
        }
        if ($second->status() === 200) {
            $ok++;
        }
        if ($first->status() === 422) {
            $denied++;
        }
    });

    expect($ok)->toBe(1)
        ->and($denied)->toBe(1)
        ->and(BlockSubmission::query()->where('lesson_attempt_id', $attempt->id)->count())->toBe(1);
});

test('reveal never never reveals; on_pass reveals only when passed', function () {
    [$lesson, , $quiz, $token] = publishGradableQuiz([
        'reveal_policy' => 'never',
        'reveal_answers' => true,
    ]);

    $fail = gradeQuiz($lesson, $quiz['block_id'], $token, 'b')->assertOk()->json();
    expect($fail['result']['reveal'])->toBeNull();

    [$lesson2, , $quiz2, $token2] = publishGradableQuiz([
        'reveal_policy' => 'on_pass',
        'reveal_answers' => true,
        'max_attempts' => null,
    ]);

    $failed = gradeQuiz($lesson2, $quiz2['block_id'], $token2, 'b')->assertOk()->json();
    expect($failed['result']['reveal'])->toBeNull();

    $passed = gradeQuiz($lesson2, $quiz2['block_id'], $token2, 'a')->assertOk()->json();
    expect($passed['result']['reveal']['trigger'])->toBe('passed')
        ->and($passed['result']['reveal']['items'][0]['correct_option_id'])->toBe('a')
        ->and($passed['result']['reveal']['items'][0]['feedback'])->toBe('Alpha is right.');

    assertNoForbiddenKeys($passed);
});

test('on_final_attempt does not fire in this phase', function () {
    [$lesson, , $quiz, $token] = publishGradableQuiz([
        'reveal_policy' => 'on_final_attempt',
        'max_attempts' => 1,
        'reveal_answers' => true,
    ]);

    $body = gradeQuiz($lesson, $quiz['block_id'], $token, 'b')->assertOk()->json();
    expect($body['result']['reveal'])->toBeNull()
        ->and($body['attempts']['remaining'])->toBe(0);
});

test('reveal_answers and show_feedback independently control reveal item fields', function () {
    [$lesson, , $quiz, $token] = publishGradableQuiz([
        'reveal_policy' => 'on_pass',
        'reveal_answers' => false,
        'show_feedback' => true,
    ]);

    $body = gradeQuiz($lesson, $quiz['block_id'], $token, 'a')->assertOk()->json();
    $item = $body['result']['reveal']['items'][0];

    expect($item['correct_option_id'])->toBeNull()
        ->and($item['feedback'])->toBe('Alpha is right.')
        ->and($item['correct'])->toBeTrue();

    [$lesson2, , $quiz2, $token2] = publishGradableQuiz([
        'reveal_policy' => 'on_pass',
        'reveal_answers' => true,
        'show_feedback' => false,
    ]);

    $body2 = gradeQuiz($lesson2, $quiz2['block_id'], $token2, 'a')->assertOk()->json();
    $item2 = $body2['result']['reveal']['items'][0];

    expect($item2['correct_option_id'])->toBe('a')
        ->and($item2['feedback'])->toBeNull()
        ->and($item2['correct'])->toBeTrue();
});

test('earned reveal stays on reload after a teacher grants more retries', function () {
    [$lesson, $attempt, $quiz, $token] = publishGradableQuiz([
        'reveal_policy' => 'on_pass',
        'reveal_answers' => true,
        'max_attempts' => 1,
    ]);

    $passed = gradeQuiz($lesson, $quiz['block_id'], $token, 'a')->assertOk()->json();
    expect($passed['result']['reveal']['trigger'])->toBe('passed');

    $teacher = User::factory()->create();
    $teacher->forceFill(['role' => App\Enums\UserRole::Teacher])->save();
    app(AttemptService::class)->grantRetries($attempt, $quiz['block_id'], 1, $teacher);

    $api = test()->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();
    $restored = $api['attempt']['submissions'][$quiz['block_id']];

    expect($restored)->toHaveKeys(['first_result', 'latest_result'])
        ->and($restored['latest_result']['result']['reveal']['trigger'])->toBe('passed')
        ->and($restored['latest_result']['attempts']['allowed'])->toBe(2);

    assertNoForbiddenKeys($restored);
});

test('resume payload uses the envelope and respects record_first_attempt', function () {
    [$lesson, , $quiz, $token] = publishGradableQuiz([
        'record_first_attempt' => false,
        'max_attempts' => null,
    ]);

    gradeQuiz($lesson, $quiz['block_id'], $token, 'b')->assertOk();
    gradeQuiz($lesson, $quiz['block_id'], $token, 'a')->assertOk();

    $api = test()->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();
    $entry = $api['attempt']['submissions'][$quiz['block_id']];

    expect($entry['first_result'])->toBeNull()
        ->and($entry['latest_result'])->toHaveKeys(['result', 'attempts'])
        ->and(array_keys($entry['latest_result']['result']))->toEqualCanonicalizing([
            'score', 'max_score', 'percentage', 'passed', 'requires_manual_review', 'reveal',
        ]);

    [$lesson2, , $quiz2, $token2] = publishGradableQuiz([
        'record_first_attempt' => true,
        'max_attempts' => null,
    ]);

    gradeQuiz($lesson2, $quiz2['block_id'], $token2, 'b')->assertOk();
    gradeQuiz($lesson2, $quiz2['block_id'], $token2, 'a')->assertOk();

    $api2 = test()->getJson("/api/lessons/{$lesson2->code}")->assertOk()->json();
    $entry2 = $api2['attempt']['submissions'][$quiz2['block_id']];

    expect($entry2['first_result'])->not->toBeNull()
        ->and($entry2['first_result']['result']['passed'])->toBeFalse()
        ->and($entry2['latest_result']['result']['passed'])->toBeTrue();
});

test('students get 403 on staff routes while rostered teachers can grant and restart', function () {
    [$lesson, $attempt, $quiz] = publishGradableQuiz(['max_attempts' => 1]);
    gradeQuiz($lesson, $quiz['block_id'], app(StudentManifest::class)->forVersion($attempt->lessonVersion)['grading_token'], 'b')
        ->assertOk();

    $student = $attempt->user;
    $teacher = asTeacher();

    $class = App\Models\SchoolClass::query()->create([
        'name' => 'Staff test class',
        'teacher_id' => $teacher->id,
        'school_year' => '2026-2027',
        'active' => true,
    ]);
    App\Models\ClassMembership::query()->create([
        'school_class_id' => $class->id,
        'user_id' => $teacher->id,
        'role' => App\Enums\ClassRole::Teacher,
        'joined_at' => now(),
    ]);
    App\Models\ClassMembership::query()->create([
        'school_class_id' => $class->id,
        'user_id' => $student->id,
        'role' => App\Enums\ClassRole::Student,
        'joined_at' => now(),
    ]);
    $assignment = App\Models\LessonAssignment::query()->create([
        'school_class_id' => $class->id,
        'lesson_id' => $lesson->id,
        'lesson_version_id' => $attempt->lesson_version_id,
        'assigned_by_user_id' => $teacher->id,
        'available_at' => now()->subHour(),
        'due_at' => now()->addWeek(),
    ]);
    $attempt->forceFill(['lesson_assignment_id' => $assignment->id])->save();

    asStudent();
    test()->get(route('staff.blocked-attempts'))->assertForbidden();
    test()->post(route('staff.attempts.grant-retries', $attempt), [
        'block_id' => $quiz['block_id'],
        'additional_attempts' => 1,
    ])->assertForbidden();
    test()->post(route('staff.attempts.restart', $attempt))->assertForbidden();

    asTeacher($teacher);
    test()->get(route('staff.blocked-attempts'))->assertOk()->assertSee($student->name, false);

    test()->post(route('staff.attempts.grant-retries', $attempt), [
        'block_id' => $quiz['block_id'],
        'additional_attempts' => 1,
        'reason' => 'stuck',
    ])->assertRedirect(route('staff.attempts.show', $attempt));

    expect(AttemptRetryGrant::query()->where('lesson_attempt_id', $attempt->id)->count())->toBe(1);

    $oldId = $attempt->id;
    $statesBefore = $attempt->blockStates()->count();
    $subsBefore = $attempt->blockSubmissions()->count();

    test()->post(route('staff.attempts.restart', $attempt))
        ->assertRedirect();

    $old = LessonAttempt::query()->findOrFail($oldId);
    expect($old->status)->toBe(AttemptStatus::Superseded)
        ->and($old->completed_at)->toBeNull()
        ->and($old->superseded_at)->not->toBeNull()
        ->and($old->superseded_by_user_id)->not->toBeNull()
        ->and($old->blockStates()->count())->toBe($statesBefore)
        ->and($old->blockSubmissions()->count())->toBe($subsBefore);

    $fresh = LessonAttempt::query()
        ->where('user_id', $old->user_id)
        ->where('lesson_assignment_id', $assignment->id)
        ->where('status', AttemptStatus::InProgress)
        ->get();

    expect($fresh)->toHaveCount(1)
        ->and($fresh->first()->id)->not->toBe($oldId);
});

test('attempt retry grants are immutable on update and delete', function () {
    [$lesson, $attempt, $quiz] = publishGradableQuiz(['max_attempts' => 1]);
    $teacher = asTeacher();

    $grant = app(AttemptService::class)->grantRetries($attempt, $quiz['block_id'], 2, $teacher, 'help');

    expect(fn () => $grant->update(['additional_attempts' => 9]))
        ->toThrow(ImmutableAttemptRetryGrantException::class)
        ->and(fn () => $grant->delete())
        ->toThrow(ImmutableAttemptRetryGrantException::class);

    $fresh = AttemptRetryGrant::query()->findOrFail($grant->id);
    expect(fn () => $fresh->forceFill(['reason' => 'x'])->save())
        ->toThrow(ImmutableAttemptRetryGrantException::class);
});

test('a superseded attempt is never the student read-only view while a newer in_progress exists', function () {
    [$lesson, $attempt, $quiz, $token] = publishGradableQuiz(['max_attempts' => 1]);
    gradeQuiz($lesson, $quiz['block_id'], $token, 'b')->assertOk();

    $teacher = asTeacher();
    $new = app(AttemptService::class)->restartForStaff($attempt, $teacher);

    asStudent(User::query()->findOrFail($attempt->user_id));
    $resolved = app(AttemptService::class)->existingAttempt(auth()->user(), $lesson->fresh());

    expect($resolved['attempt']->id)->toBe($new->id)
        ->and($resolved['read_only'])->toBeFalse()
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Superseded);
});

test('the home page lists class assignments for a student', function () {
    $fx = (function () {
        // Local roster: one assignment the student can start.
        $teacher = asTeacher();
        $student = User::factory()->create();
        $lesson = createLessonWithAllBlockTypes();
        app(LessonPublisher::class)->publish($lesson, $teacher);
        $lesson = $lesson->fresh();
        $class = App\Models\SchoolClass::query()->create([
            'name' => 'Home class',
            'teacher_id' => $teacher->id,
            'school_year' => '2026-2027',
            'active' => true,
        ]);
        App\Models\ClassMembership::query()->create([
            'school_class_id' => $class->id,
            'user_id' => $teacher->id,
            'role' => App\Enums\ClassRole::Teacher,
            'joined_at' => now(),
        ]);
        App\Models\ClassMembership::query()->create([
            'school_class_id' => $class->id,
            'user_id' => $student->id,
            'role' => App\Enums\ClassRole::Student,
            'joined_at' => now(),
        ]);
        $assignment = App\Models\LessonAssignment::query()->create([
            'school_class_id' => $class->id,
            'lesson_id' => $lesson->id,
            'lesson_version_id' => $lesson->currentVersion()->id,
            'assigned_by_user_id' => $teacher->id,
            'available_at' => now()->subHour(),
            'due_at' => now()->addWeek(),
        ]);

        return compact('student', 'lesson', 'assignment');
    })();

    asStudent($fx['student']);
    test()->get(route('home'))
        ->assertOk()
        ->assertSee($fx['lesson']->title, false)
        ->assertSee(__('home.action_start'), false);
});
