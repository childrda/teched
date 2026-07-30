<?php

use App\Enums\AttemptStatus;
use App\Enums\ClassRole;
use App\Enums\PageCompletionType;
use App\Enums\UserRole;
use App\Models\ClassMembership;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\LessonAttempt;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\BlockedAttemptFinder;
use App\Services\LessonPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * @return array{
 *     teacher: User,
 *     otherTeacher: User,
 *     student: User,
 *     otherStudent: User,
 *     classA: SchoolClass,
 *     classB: SchoolClass,
 *     lesson: Lesson,
 *     assignmentA: LessonAssignment,
 *     assignmentB: LessonAssignment
 * }
 */
function rosterFixture(bool $twoVersions = false): array
{
    $teacher = asTeacher();
    $otherTeacher = User::factory()->create();
    $otherTeacher->forceFill(['role' => UserRole::Teacher])->save();
    $student = User::factory()->create();
    $student->refresh();
    $otherStudent = User::factory()->create();
    $otherStudent->refresh();

    $lesson = Lesson::factory()->create();
    $page = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 1,
        'completion_type' => PageCompletionType::View,
    ]);
    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'type' => 'rich_text',
        'position' => 1,
        'config' => app(App\Blocks\BlockTypeRegistry::class)->get('rich_text')->defaultConfig(),
    ]);
    app(LessonPublisher::class)->publish($lesson, $teacher);
    $lesson = $lesson->fresh();
    $v1 = $lesson->currentVersion();

    if ($twoVersions) {
        $block = $page->blocks()->first();
        $block->update(['config' => array_merge($block->config, ['html' => '<p>v2</p>'])]);
        app(LessonPublisher::class)->publish($lesson->fresh(), $teacher);
        $lesson = $lesson->fresh();
    }

    $vCurrent = $lesson->currentVersion();

    $classA = SchoolClass::query()->create([
        'name' => 'Class A',
        'teacher_id' => $teacher->id,
        'school_year' => '2026-2027',
        'active' => true,
    ]);
    $classB = SchoolClass::query()->create([
        'name' => 'Class B',
        'teacher_id' => $otherTeacher->id,
        'school_year' => '2026-2027',
        'active' => true,
    ]);

    foreach ([[$classA, $teacher, ClassRole::Teacher], [$classA, $student, ClassRole::Student], [$classB, $otherTeacher, ClassRole::Teacher], [$classB, $student, ClassRole::Student], [$classB, $otherStudent, ClassRole::Student]] as [$class, $user, $role]) {
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
        'lesson_version_id' => $v1->id,
        'assigned_by_user_id' => $teacher->id,
        'available_at' => now()->subHour(),
        'due_at' => now()->addWeek(),
    ]);

    $assignmentB = LessonAssignment::query()->create([
        'school_class_id' => $classB->id,
        'lesson_id' => $lesson->id,
        'lesson_version_id' => $vCurrent->id,
        'assigned_by_user_id' => $otherTeacher->id,
        'available_at' => now()->subHour(),
        'due_at' => now()->addWeek(),
    ]);

    return compact('teacher', 'otherTeacher', 'student', 'otherStudent', 'classA', 'classB', 'lesson', 'assignmentA', 'assignmentB');
}

test('a global student cannot hold a class teacher membership', function () {
    $teacher = asTeacher();
    $student = User::factory()->create();
    $student->refresh();
    $class = SchoolClass::query()->create([
        'name' => 'X',
        'teacher_id' => $teacher->id,
        'school_year' => '2026-2027',
        'active' => true,
    ]);

    expect(fn () => ClassMembership::query()->create([
        'school_class_id' => $class->id,
        'user_id' => $student->id,
        'role' => ClassRole::Teacher,
        'joined_at' => now(),
    ]))->toThrow(ValidationException::class);
});

test('duplicate memberships and external ids are rejected while null pairs coexist', function () {
    $teacher = asTeacher();
    $class = SchoolClass::query()->create([
        'name' => 'One',
        'teacher_id' => $teacher->id,
        'school_year' => '2026-2027',
        'active' => true,
        'external_provider' => 'google_classroom',
        'external_id' => 'course-1',
    ]);

    ClassMembership::query()->create([
        'school_class_id' => $class->id,
        'user_id' => $teacher->id,
        'role' => ClassRole::Teacher,
        'joined_at' => now(),
    ]);

    expect(fn () => ClassMembership::query()->create([
        'school_class_id' => $class->id,
        'user_id' => $teacher->id,
        'role' => ClassRole::Teacher,
        'joined_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);

    expect(fn () => SchoolClass::query()->create([
        'name' => 'Dup',
        'teacher_id' => $teacher->id,
        'school_year' => '2026-2027',
        'active' => true,
        'external_provider' => 'google_classroom',
        'external_id' => 'course-1',
    ]))->toThrow(\Illuminate\Database\QueryException::class);

    SchoolClass::query()->create([
        'name' => 'Manual A',
        'teacher_id' => $teacher->id,
        'school_year' => '2026-2027',
        'active' => true,
        'external_provider' => null,
        'external_id' => null,
    ]);
    SchoolClass::query()->create([
        'name' => 'Manual B',
        'teacher_id' => $teacher->id,
        'school_year' => '2026-2027',
        'active' => true,
        'external_provider' => null,
        'external_id' => null,
    ]);

    expect(SchoolClass::query()->whereNull('external_provider')->count())->toBe(2);
});

test('assignment play pins the assignment version not the latest publish', function () {
    $fx = rosterFixture(twoVersions: true);
    asStudent($fx['student']);

    expect($fx['lesson']->fresh()->current_version)->toBe(2)
        ->and($fx['assignmentA']->lesson_version_id)->toBe($fx['lesson']->versions()->where('version', 1)->value('id'));

    $this->get(route('player.assignments.show', $fx['assignmentA']))->assertOk();

    $attempt = LessonAttempt::query()
        ->where('user_id', $fx['student']->id)
        ->where('lesson_assignment_id', $fx['assignmentA']->id)
        ->firstOrFail();

    expect($attempt->lesson_version_id)->toBe($fx['assignmentA']->lesson_version_id);
});

test('two class assignments of the same lesson yield two concurrent in_progress attempts', function () {
    $fx = rosterFixture(twoVersions: true);
    asStudent($fx['student']);

    $this->get(route('player.assignments.show', $fx['assignmentA']))->assertOk();
    $this->get(route('player.assignments.show', $fx['assignmentB']))->assertOk();

    $attempts = LessonAttempt::query()
        ->where('user_id', $fx['student']->id)
        ->where('status', AttemptStatus::InProgress)
        ->get();

    expect($attempts)->toHaveCount(2)
        ->and($attempts->pluck('lesson_assignment_id')->sort()->values()->all())
        ->toBe(collect([$fx['assignmentA']->id, $fx['assignmentB']->id])->sort()->values()->all());
});

test('direct lesson access still creates an unassigned attempt', function () {
    $fx = rosterFixture();
    asStudent($fx['student']);

    $this->get("/lessons/{$fx['lesson']->code}")->assertOk();

    $attempt = LessonAttempt::query()->where('user_id', $fx['student']->id)->firstOrFail();
    expect($attempt->lesson_assignment_id)->toBeNull()
        ->and($attempt->status)->toBe(AttemptStatus::InProgress);

    expect(fn () => app(AttemptService::class)->resolveForPlayer($fx['student'], $fx['lesson']->fresh()))
        ->not->toThrow(\Throwable::class);

    expect(LessonAttempt::query()->where('user_id', $fx['student']->id)->whereNull('lesson_assignment_id')->where('status', AttemptStatus::InProgress)->count())
        ->toBe(1);
});

test('before available_at no attempt is created and the unavailable state is shown', function () {
    $fx = rosterFixture();
    $fx['assignmentA']->forceFill(['available_at' => now()->addDay()])->save();
    asStudent($fx['student']);

    $this->get(route('player.assignments.show', $fx['assignmentA']))
        ->assertForbidden()
        ->assertSee(__('player.assignment_unavailable_title'), false);

    expect(LessonAttempt::query()->where('lesson_assignment_id', $fx['assignmentA']->id)->count())->toBe(0);
});

test('authorization follows the exact assignment class', function () {
    $fx = rosterFixture();
    asStudent($fx['student']);
    $this->get(route('player.assignments.show', $fx['assignmentA']))->assertOk();
    $attempt = LessonAttempt::query()->where('lesson_assignment_id', $fx['assignmentA']->id)->firstOrFail();

    asTeacher($fx['teacher']);
    expect($fx['teacher']->can('view', $attempt))->toBeTrue()
        ->and($fx['teacher']->can('intervene', $attempt))->toBeTrue();

    // Same student, different class — Teacher B must not see welding attempt A.
    asTeacher($fx['otherTeacher']);
    expect($fx['otherTeacher']->can('view', $attempt))->toBeFalse()
        ->and($fx['otherTeacher']->can('intervene', $attempt))->toBeFalse();

    $this->post(route('staff.attempts.grant-retries', $attempt), [
        'block_id' => '01PLACEHOLDERPLACEHOLDER',
        'additional_attempts' => 1,
    ])->assertForbidden();

    $this->post(route('staff.attempts.restart', $attempt))->assertForbidden();

    asStudent($fx['otherStudent']);
    expect($fx['otherStudent']->can('view', $attempt))->toBeFalse();

    asAdmin();
    expect(auth()->user()->can('view', $attempt))->toBeTrue()
        ->and(auth()->user()->can('intervene', $attempt))->toBeTrue();
});

test('withdrawn students cannot resume but teachers still see history', function () {
    $fx = rosterFixture();
    asStudent($fx['student']);
    $this->get(route('player.assignments.show', $fx['assignmentA']))->assertOk();
    $attempt = LessonAttempt::query()->where('lesson_assignment_id', $fx['assignmentA']->id)->firstOrFail();
    $attempt->forceFill([
        'status' => AttemptStatus::Completed,
        'completed_at' => now(),
    ])->save();

    ClassMembership::query()
        ->where('school_class_id', $fx['classA']->id)
        ->where('user_id', $fx['student']->id)
        ->update(['withdrawn_at' => now()]);

    asTeacher($fx['teacher']);
    expect($fx['teacher']->can('view', $attempt->fresh()))->toBeTrue();

    asStudent($fx['student']);
    $attempt->forceFill([
        'status' => AttemptStatus::InProgress,
        'completed_at' => null,
    ])->save();
    expect($fx['student']->can('work', $attempt->fresh()))->toBeFalse();

    $this->get(route('player.assignments.show', $fx['assignmentA']))
        ->assertForbidden()
        ->assertSee(__('player.assignment_withdrawn_title'), false);

    $attempt->forceFill([
        'status' => AttemptStatus::Completed,
        'completed_at' => now(),
    ])->save();

    $this->get(route('player.assignments.show', $fx['assignmentA']))->assertOk();
});

test('unassigned attempts are student and admin only for teachers', function () {
    $fx = rosterFixture();
    asStudent($fx['student']);
    $resolved = app(AttemptService::class)->resolveForPlayer($fx['student'], $fx['lesson']);
    $attempt = $resolved['attempt'];

    asTeacher($fx['teacher']);
    expect($fx['teacher']->can('view', $attempt))->toBeFalse()
        ->and($fx['teacher']->can('intervene', $attempt))->toBeFalse();

    asAdmin();
    expect(auth()->user()->can('view', $attempt))->toBeTrue()
        ->and(auth()->user()->can('intervene', $attempt))->toBeTrue();
});

test('visibleTo agrees with the attempt policy on the same fixtures', function () {
    $fx = rosterFixture();
    asStudent($fx['student']);
    $this->get(route('player.assignments.show', $fx['assignmentA']))->assertOk();
    $this->get(route('player.assignments.show', $fx['assignmentB']))->assertOk();
    $unassigned = app(AttemptService::class)->resolveForPlayer($fx['student'], $fx['lesson'])['attempt'];

    // Teacher's own practice attempt — policy allows view; scope must too.
    asTeacher($fx['teacher']);
    $teacherOwn = app(AttemptService::class)->resolveForPlayer($fx['teacher'], $fx['lesson'])['attempt'];

    $all = LessonAttempt::query()
        ->whereIn('user_id', [$fx['student']->id, $fx['teacher']->id])
        ->get();

    $visible = LessonAttempt::query()->visibleTo($fx['teacher'])->pluck('id')->all();

    foreach ($all as $attempt) {
        $allowed = $fx['teacher']->can('view', $attempt);
        expect(in_array($attempt->id, $visible, true))
            ->toBe($allowed, "visibleTo/policy disagree on attempt {$attempt->id}");
    }

    expect($visible)->not->toContain($unassigned->id)
        ->and($visible)->toContain($teacherOwn->id);

    $finderIds = collect(app(BlockedAttemptFinder::class)->forUser($fx['teacher']))
        ->pluck('attempt.id')
        ->all();

    foreach ($finderIds as $id) {
        expect(in_array($id, $visible, true))->toBeTrue();
    }
});

test('restarting twice under one assignment accumulates superseded history', function () {
    $fx = rosterFixture();
    asStudent($fx['student']);
    $this->get(route('player.assignments.show', $fx['assignmentA']))->assertOk();
    $first = LessonAttempt::query()->where('lesson_assignment_id', $fx['assignmentA']->id)->firstOrFail();

    asTeacher($fx['teacher']);
    $second = app(AttemptService::class)->restartForStaff($first, $fx['teacher']);
    $third = app(AttemptService::class)->restartForStaff($second, $fx['teacher']);

    expect(LessonAttempt::query()->where('lesson_assignment_id', $fx['assignmentA']->id)->count())->toBe(3)
        ->and($first->fresh()->status)->toBe(AttemptStatus::Superseded)
        ->and($second->fresh()->status)->toBe(AttemptStatus::Superseded)
        ->and($third->fresh()->status)->toBe(AttemptStatus::InProgress)
        ->and($third->lesson_assignment_id)->toBe($fx['assignmentA']->id)
        ->and($third->lesson_version_id)->toBe($fx['assignmentA']->lesson_version_id);
});

test('the in_progress_scope generated column exists on mysql', function () {
    if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Generated column is MySQL/MariaDB only.');
    }

    expect(Schema::hasColumn('lesson_attempts', 'in_progress_scope'))->toBeTrue()
        ->and(Schema::hasColumn('lesson_attempts', 'in_progress_guard'))->toBeFalse();

    $indexes = collect(DB::select('SHOW INDEX FROM lesson_attempts'))
        ->where('Key_name', 'lesson_attempts_one_in_progress');

    expect($indexes)->not->toBeEmpty()
        ->and($indexes->pluck('Column_name')->all())->toContain('in_progress_scope');
});
