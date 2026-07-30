<?php

use App\Enums\AttemptStatus;
use App\Enums\ClassRole;
use App\Enums\PageCompletionType;
use App\Enums\UserRole;
use App\Exceptions\ImmutableAttemptReopenException;
use App\Models\AttemptReopen;
use App\Models\BlockState;
use App\Models\BlockSubmission;
use App\Models\ClassMembership;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\LessonAttempt;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AssignmentProgressService;
use App\Services\AttemptDetailPresenter;
use App\Services\AttemptService;
use App\Services\BlockedAttemptService;
use App\Services\LessonPublisher;
use App\Services\StudentManifest;
use App\Support\StudentGradingResult;
use App\Support\TeacherGradingResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * @return array{
 *     teacher: User,
 *     otherTeacher: User,
 *     student: User,
 *     classA: SchoolClass,
 *     classB: SchoolClass,
 *     lesson: Lesson,
 *     assignmentA: LessonAssignment,
 *     assignmentB: LessonAssignment,
 *     quiz: array<string, mixed>,
 *     attempt: LessonAttempt,
 *     token: string
 * }
 */
function progressFixture(): array
{
    $teacher = asTeacher();
    $otherTeacher = User::factory()->create();
    $otherTeacher->forceFill(['role' => UserRole::Teacher])->save();
    $student = User::factory()->create();
    $student->refresh();

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
            'questions' => [[
                'id' => 'q1',
                'prompt' => 'Pick one',
                'options' => [
                    ['id' => 'a', 'text' => 'Alpha'],
                    ['id' => 'b', 'text' => 'Beta'],
                ],
                'answer_id' => 'a',
                'feedback' => 'Alpha is right.',
                'source_ref' => null,
            ]],
        ],
        'grading' => array_merge(fullGradingShape('all_correct'), [
            'max_attempts' => 1,
            'reveal_policy' => 'never',
            'reveal_answers' => false,
        ]),
    ]);
    app(LessonPublisher::class)->publish($lesson, $teacher);
    $lesson = $lesson->fresh();
    $version = $lesson->currentVersion();

    $classA = SchoolClass::query()->create([
        'name' => 'Progress Class A',
        'teacher_id' => $teacher->id,
        'school_year' => '2026-2027',
        'active' => true,
    ]);
    $classB = SchoolClass::query()->create([
        'name' => 'Progress Class B',
        'teacher_id' => $otherTeacher->id,
        'school_year' => '2026-2027',
        'active' => true,
    ]);

    foreach ([
        [$classA, $teacher, ClassRole::Teacher],
        [$classA, $student, ClassRole::Student],
        [$classB, $otherTeacher, ClassRole::Teacher],
        [$classB, $student, ClassRole::Student],
    ] as [$class, $user, $role]) {
        ClassMembership::query()->create([
            'school_class_id' => $class->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    $assignmentA = LessonAssignment::query()->create([
        'school_class_id' => $classA->id,
        'lesson_id' => $lesson->id,
        'lesson_version_id' => $version->id,
        'assigned_by_user_id' => $teacher->id,
        'available_at' => now()->subDay(),
        'due_at' => now()->addWeek(),
    ]);
    $assignmentB = LessonAssignment::query()->create([
        'school_class_id' => $classB->id,
        'lesson_id' => $lesson->id,
        'lesson_version_id' => $version->id,
        'assigned_by_user_id' => $otherTeacher->id,
        'available_at' => now()->subDay(),
        'due_at' => now()->addWeek(),
    ]);

    asStudent($student);
    $attempt = app(AttemptService::class)->resolveForAssignment($student, $assignmentA)['attempt'];
    $quiz = blockOfType($attempt->lessonVersion->manifest['pages'], 'quiz');
    $token = app(StudentManifest::class)->forVersion($attempt->lessonVersion)['grading_token'];

    return compact(
        'teacher',
        'otherTeacher',
        'student',
        'classA',
        'classB',
        'lesson',
        'assignmentA',
        'assignmentB',
        'quiz',
        'attempt',
        'token'
    );
}

test('deleting an assignment with attempts is refused', function () {
    $fx = progressFixture();

    expect(fn () => $fx['assignmentA']->delete())
        ->toThrow(QueryException::class);

    expect(LessonAssignment::query()->whereKey($fx['assignmentA']->id)->exists())->toBeTrue()
        ->and($fx['attempt']->fresh()->lesson_assignment_id)->toBe($fx['assignmentA']->id);
});

test('teachers reach only their classes assignments and attempts', function () {
    $fx = progressFixture();

    asStudent($fx['student']);
    $this->get(route('staff.classes.index'))->assertForbidden();
    $this->get(route('staff.assignments.show', $fx['assignmentA']))->assertForbidden();
    $this->get(route('staff.attempts.show', $fx['attempt']))->assertForbidden();

    asTeacher($fx['teacher']);
    expect(SchoolClass::query()->visibleTo($fx['teacher'])->pluck('id')->all())
        ->toContain($fx['classA']->id)
        ->not->toContain($fx['classB']->id);

    expect(LessonAssignment::query()->visibleTo($fx['teacher'])->pluck('id')->all())
        ->toContain($fx['assignmentA']->id)
        ->not->toContain($fx['assignmentB']->id);

    $this->get(route('staff.classes.index'))->assertOk()->assertSeeText($fx['classA']->name);
    $this->get(route('staff.assignments.show', $fx['assignmentA']))->assertOk()->assertSeeText($fx['student']->name);
    $this->get(route('staff.attempts.show', $fx['attempt']))->assertOk();

    $this->get(route('staff.assignments.show', $fx['assignmentB']))->assertForbidden();
    $bAttempt = app(AttemptService::class)->resolveForAssignment($fx['student'], $fx['assignmentB'])['attempt'];
    asTeacher($fx['teacher']);
    $this->get(route('staff.attempts.show', $bAttempt))->assertForbidden();

    asTeacher($fx['otherTeacher']);
    $this->get(route('staff.attempts.show', $fx['attempt']))->assertForbidden();
    $this->get(route('staff.assignments.show', $fx['assignmentA']))->assertForbidden();

    asAdmin();
    $this->get(route('staff.assignments.show', $fx['assignmentA']))->assertOk();
    $this->get(route('staff.assignments.show', $fx['assignmentB']))->assertOk();
});

test('student grading endpoint response stays the six-key envelope', function () {
    $fx = progressFixture();
    asStudent($fx['student']);

    $before = $this->postJson("/player/lessons/{$fx['lesson']->code}/blocks/{$fx['quiz']['block_id']}/grade", [
        'version_token' => $fx['token'],
        'response' => ['q1' => 'b'],
    ])->assertOk()->json();

    expect(array_keys($before))->toEqualCanonicalizing(['result', 'attempts'])
        ->and(array_keys($before['result']))->toEqualCanonicalizing([
            'score', 'max_score', 'percentage', 'passed', 'requires_manual_review', 'reveal',
        ])
        ->and($before['result']['reveal'])->toBeNull();

    // Teacher view must not widen the student mapper.
    $submission = BlockSubmission::query()->where('lesson_attempt_id', $fx['attempt']->id)->firstOrFail();
    $type = app(App\Blocks\BlockTypeRegistry::class)->get('quiz');
    $teacher = app(TeacherGradingResult::class)->map(
        $submission,
        $type,
        $fx['quiz']['config'],
        $fx['quiz']['grading']
    );

    expect($teacher['details'])->not->toBeEmpty()
        ->and($teacher['details'][0]['correct_answer'])->toBe('a')
        ->and($before['result']['reveal'])->toBeNull();

    $studentMapped = app(StudentGradingResult::class)->mapResult(
        $submission->grading_result,
        app(StudentGradingResult::class)->revealFromSubmission(
            $submission,
            $type,
            $fx['quiz']['config'],
            $fx['quiz']['grading']
        )
    );
    expect($studentMapped['reveal'])->toBeNull();
});

test('current work and submitted history stay distinct including matching quiz drafts', function () {
    $fx = progressFixture();
    asStudent($fx['student']);

    $this->postJson("/player/lessons/{$fx['lesson']->code}/blocks/{$fx['quiz']['block_id']}/grade", [
        'version_token' => $fx['token'],
        'response' => ['q1' => 'b'],
    ])->assertOk();

    $submission = BlockSubmission::query()->where('lesson_attempt_id', $fx['attempt']->id)->firstOrFail();

    BlockState::query()->updateOrCreate(
        ['lesson_attempt_id' => $fx['attempt']->id, 'block_id' => $fx['quiz']['block_id']],
        [
            'block_type' => 'quiz',
            'state' => $submission->response,
            'revision' => 1,
        ]
    );

    asTeacher($fx['teacher']);
    $detail = app(AttemptDetailPresenter::class)->present($fx['attempt']->fresh());
    $block = collect($detail['blocks'])->firstWhere('block_id', $fx['quiz']['block_id']);

    expect($block['current_work']['has_state'])->toBeTrue()
        ->and($block['current_work']['differs_from_latest_submission'])->toBeFalse()
        ->and($block['submitted_history'])->toHaveCount(1);

    $state = BlockState::query()->where('lesson_attempt_id', $fx['attempt']->id)->firstOrFail();
    $state->forceFill(['state' => ['answers' => ['q1' => 'a']]])->save();

    $detail2 = app(AttemptDetailPresenter::class)->present($fx['attempt']->fresh());
    $block2 = collect($detail2['blocks'])->firstWhere('block_id', $fx['quiz']['block_id']);
    expect($block2['current_work']['differs_from_latest_submission'])->toBeTrue();
});

test('primary attempt prefers in_progress then newest completed then newest superseded', function () {
    $fx = progressFixture();
    $service = app(AssignmentProgressService::class);

    $olderCompleted = $fx['attempt'];
    $olderCompleted->forceFill([
        'status' => AttemptStatus::Completed,
        'completed_at' => now()->subDay(),
    ])->save();

    $newerCompleted = LessonAttempt::query()->create([
        'user_id' => $fx['student']->id,
        'lesson_id' => $fx['lesson']->id,
        'lesson_version_id' => $fx['attempt']->lesson_version_id,
        'lesson_assignment_id' => $fx['assignmentA']->id,
        'current_page_id' => $fx['attempt']->current_page_id,
        'status' => AttemptStatus::Completed,
        'started_at' => now()->subHours(2),
        'completed_at' => now()->subHour(),
        'last_activity_at' => now()->subHour(),
        'active_seconds' => 10,
        'shuffle_seed' => 'seed-2',
        'revision' => 0,
    ]);

    $rows = $service->rowsForUsers($fx['assignmentA'], [$fx['student']->id]);
    expect($rows[0]['primary_attempt_id'])->toBe($newerCompleted->id)
        ->and($rows[0]['attempt_count'])->toBe(2);

    $inProgress = LessonAttempt::query()->create([
        'user_id' => $fx['student']->id,
        'lesson_id' => $fx['lesson']->id,
        'lesson_version_id' => $fx['attempt']->lesson_version_id,
        'lesson_assignment_id' => $fx['assignmentA']->id,
        'current_page_id' => $fx['attempt']->current_page_id,
        'status' => AttemptStatus::InProgress,
        'started_at' => now(),
        'completed_at' => null,
        'last_activity_at' => now(),
        'active_seconds' => 0,
        'shuffle_seed' => 'seed-3',
        'revision' => 0,
    ]);

    $rows2 = $service->rowsForUsers($fx['assignmentA'], [$fx['student']->id]);
    expect($rows2[0]['primary_attempt_id'])->toBe($inProgress->id)
        ->and($rows2[0]['attempt_count'])->toBe(3);

    $inProgress->forceFill([
        'status' => AttemptStatus::Superseded,
        'superseded_at' => now(),
    ])->save();
    $newerCompleted->forceFill([
        'status' => AttemptStatus::Superseded,
        'superseded_at' => now()->subMinutes(5),
        'completed_at' => null,
    ])->save();
    $olderCompleted->forceFill([
        'status' => AttemptStatus::Superseded,
        'superseded_at' => now()->subDay(),
        'completed_at' => null,
    ])->save();

    $rows3 = $service->rowsForUsers($fx['assignmentA'], [$fx['student']->id]);
    expect($rows3[0]['primary_attempt_id'])->toBe($inProgress->id);
});

test('blocked state uses the shared service definition', function () {
    $fx = progressFixture();
    $blocked = app(BlockedAttemptService::class);

    asStudent($fx['student']);
    $this->postJson("/player/lessons/{$fx['lesson']->code}/blocks/{$fx['quiz']['block_id']}/grade", [
        'version_token' => $fx['token'],
        'response' => ['q1' => 'b'],
    ])->assertOk();

    expect($blocked->isBlocked($fx['attempt']->fresh()))->toBeTrue();

    // Retries remaining → not blocked.
    [$lesson2, $attempt2, $quiz2, $token2] = (function () use ($fx) {
        $page = LessonPage::factory()->create([
            'lesson_id' => Lesson::factory()->create()->id,
            'position' => 1,
            'completion_type' => PageCompletionType::PassActivity,
        ]);
        // Use publishGradableQuiz pattern via new lesson with max 2.
        return publishGradableQuiz(['max_attempts' => 2]);
    })();

    gradeQuiz($lesson2, $quiz2['block_id'], $token2, 'b')->assertOk();
    expect($blocked->isBlocked($attempt2->fresh()))->toBeFalse();

    app(AttemptService::class)->grantRetries($fx['attempt'], $fx['quiz']['block_id'], 1, $fx['teacher']);
    expect($blocked->isBlocked($fx['attempt']->fresh()))->toBeFalse();
});

test('assignment progress query count does not grow per student', function () {
    $fx = progressFixture();
    $service = app(AssignmentProgressService::class);

    $two = [$fx['student']->id];
    $extra = User::factory()->count(18)->create();
    foreach ($extra as $user) {
        ClassMembership::query()->create([
            'school_class_id' => $fx['classA']->id,
            'user_id' => $user->id,
            'role' => ClassRole::Student,
            'joined_at' => now(),
        ]);
        $two[] = $user->id; // keep first list separate
    }

    $idsTwo = [$fx['student']->id, $extra[0]->id];
    $idsTwenty = collect([$fx['student']->id])->merge($extra->pluck('id'))->all();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $service->rowsForUsers($fx['assignmentA'], $idsTwo);
    $countTwo = count(DB::getQueryLog());

    DB::flushQueryLog();
    $service->rowsForUsers($fx['assignmentA'], $idsTwenty);
    $countTwenty = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($countTwenty)->toBeLessThanOrEqual($countTwo + 2);
});

test('assignment list response never includes answer keys or grading_result', function () {
    $fx = progressFixture();
    asStudent($fx['student']);
    $this->postJson("/player/lessons/{$fx['lesson']->code}/blocks/{$fx['quiz']['block_id']}/grade", [
        'version_token' => $fx['token'],
        'response' => ['q1' => 'b'],
    ])->assertOk();

    asTeacher($fx['teacher']);
    $html = $this->get(route('staff.assignments.show', $fx['assignmentA']))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('grading_result')
        ->not->toContain('"answer_id"')
        ->not->toContain('Alpha is right.');

    // Detail page may show teacher grading; list must not have loaded it.
    $data = app(AssignmentProgressService::class)->forAssignment($fx['assignmentA']);
    foreach ($data['active'] as $row) {
        expect($row)->not->toHaveKey('grading_result');
    }
});

test('withdrawn students appear only in the historical section', function () {
    $fx = progressFixture();

    ClassMembership::query()
        ->where('school_class_id', $fx['classA']->id)
        ->where('user_id', $fx['student']->id)
        ->update(['withdrawn_at' => now()]);

    $data = app(AssignmentProgressService::class)->forAssignment($fx['assignmentA']);

    expect($data['active_total'])->toBe(0)
        ->and($data['withdrawn'])->toHaveCount(1)
        ->and($data['withdrawn'][0]['name'])->toBe($fx['student']->name);
});

test('reopen restores in_progress preserves page and refuses conflicts', function () {
    $fx = progressFixture();
    $pageId = $fx['attempt']->current_page_id;

    $fx['attempt']->forceFill([
        'status' => AttemptStatus::Completed,
        'completed_at' => now()->subMinute(),
        'current_page_id' => $pageId,
    ])->save();

    asTeacher($fx['teacher']);
    $this->get(route('staff.attempts.reopen.confirm', $fx['attempt']))->assertOk();
    $this->post(route('staff.attempts.reopen', $fx['attempt']), ['reason' => 'oops'])
        ->assertRedirect(route('staff.attempts.show', $fx['attempt']));

    $fresh = $fx['attempt']->fresh();
    expect($fresh->status)->toBe(AttemptStatus::InProgress)
        ->and($fresh->completed_at)->toBeNull()
        ->and($fresh->current_page_id)->toBe($pageId);

    $reopen = AttemptReopen::query()->where('lesson_attempt_id', $fresh->id)->firstOrFail();
    expect($reopen->previous_completed_at)->not->toBeNull()
        ->and($reopen->reason)->toBe('oops')
        ->and(fn () => $reopen->update(['reason' => 'x']))->toThrow(ImmutableAttemptReopenException::class)
        ->and(fn () => $reopen->delete())->toThrow(ImmutableAttemptReopenException::class);

    $fresh->forceFill([
        'status' => AttemptStatus::Completed,
        'completed_at' => now(),
    ])->save();

    $fresh = app(AttemptService::class)->reopenForStaff($fresh->fresh(), $fx['teacher'], 'again');
    expect(AttemptReopen::query()->where('lesson_attempt_id', $fresh->id)->count())->toBe(2);

    // A second completed attempt in the same scope, with a live in_progress sibling,
    // must refuse reopen (uniqueness would reject promoting it anyway).
    $siblingCompleted = LessonAttempt::query()->create([
        'user_id' => $fx['student']->id,
        'lesson_id' => $fx['lesson']->id,
        'lesson_version_id' => $fx['attempt']->lesson_version_id,
        'lesson_assignment_id' => $fx['assignmentA']->id,
        'current_page_id' => $pageId,
        'status' => AttemptStatus::Completed,
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(30),
        'last_activity_at' => now()->subMinutes(30),
        'active_seconds' => 0,
        'shuffle_seed' => 'seed-x',
        'revision' => 0,
    ]);

    expect(fn () => app(AttemptService::class)->reopenForStaff($siblingCompleted, $fx['teacher']))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    $superseded = LessonAttempt::query()->create([
        'user_id' => $fx['student']->id,
        'lesson_id' => $fx['lesson']->id,
        'lesson_version_id' => $fx['attempt']->lesson_version_id,
        'lesson_assignment_id' => $fx['assignmentA']->id,
        'current_page_id' => $pageId,
        'status' => AttemptStatus::Superseded,
        'started_at' => now()->subDay(),
        'superseded_at' => now(),
        'last_activity_at' => now(),
        'active_seconds' => 0,
        'shuffle_seed' => 'seed-y',
        'revision' => 0,
    ]);

    expect(fn () => app(AttemptService::class)->reopenForStaff($superseded, $fx['teacher']))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('attempt detail shows teacher grading while student reveal stays null', function () {
    $fx = progressFixture();
    asStudent($fx['student']);
    $this->postJson("/player/lessons/{$fx['lesson']->code}/blocks/{$fx['quiz']['block_id']}/grade", [
        'version_token' => $fx['token'],
        'response' => ['q1' => 'b'],
    ])->assertOk();

    asTeacher($fx['teacher']);
    $this->get(route('staff.attempts.show', $fx['attempt']))
        ->assertOk()
        ->assertSee(__('staff.correct_answer'), false)
        ->assertSee('a', false)
        ->assertSee(__('staff.current_work'), false)
        ->assertSee(__('staff.submitted_history'), false);
});
