<?php

use App\Enums\AttemptStatus;
use App\Enums\ClassRole;
use App\Enums\LessonStatus;
use App\Enums\PageCompletionType;
use App\Enums\UserRole;
use App\Filament\Resources\SchoolClasses\Resources\ClassAssignments\ClassAssignmentResource;
use App\Filament\Resources\SchoolClasses\Resources\ClassMemberships\ClassMembershipResource;
use App\Filament\Resources\SchoolClasses\SchoolClassResource;
use App\Models\ClassMembership;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\LessonAssignmentStatusChange;
use App\Models\LessonAssignmentVersionChange;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\ClassMembershipService;
use App\Services\LessonAssignmentService;
use App\Services\LessonPublisher;
use App\Services\SchoolClassService;
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
 *     class: SchoolClass,
 *     lesson: Lesson,
 *     assignment: LessonAssignment
 * }
 */
function phase5cFixture(bool $twoVersions = false): array
{
    $teacher = asTeacher();
    $otherTeacher = User::factory()->create();
    $otherTeacher->forceFill(['role' => UserRole::Teacher])->save();
    $student = User::factory()->create();
    $student->refresh();

    $lesson = Lesson::factory()->create(['created_by_user_id' => $otherTeacher->id]);
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
    app(LessonPublisher::class)->publish($lesson, $otherTeacher);
    $lesson = $lesson->fresh();

    if ($twoVersions) {
        $block = $page->blocks()->first();
        $block->update(['config' => array_merge($block->config, ['html' => '<p>v2</p>'])]);
        app(LessonPublisher::class)->publish($lesson->fresh(), $otherTeacher);
        $lesson = $lesson->fresh();
    }

    $class = app(SchoolClassService::class)->create($teacher, [
        'name' => 'Period 5C',
        'school_year' => '2026-2027',
    ]);

    app(ClassMembershipService::class)->addOrReactivateStudent($class, $student, $teacher);

    $assignment = app(LessonAssignmentService::class)->create($class, $teacher, [
        'lesson_id' => $lesson->id,
        'available_at' => now()->subHour(),
        'due_at' => now()->addWeek(),
    ]);

    return compact('teacher', 'otherTeacher', 'student', 'class', 'lesson', 'assignment');
}

test('creating a class writes teacher_id and teacher membership and is immediately visible', function () {
    $teacher = asTeacher();
    $class = app(SchoolClassService::class)->create($teacher, [
        'name' => 'New Class',
        'school_year' => '2026-2027',
    ]);

    expect($class->teacher_id)->toBe($teacher->id)
        ->and($class->external_provider)->toBeNull()
        ->and($class->external_id)->toBeNull()
        ->and(ClassMembership::query()
            ->where('school_class_id', $class->id)
            ->where('user_id', $teacher->id)
            ->where('role', ClassRole::Teacher->value)
            ->whereNull('withdrawn_at')
            ->exists())->toBeTrue()
        ->and(SchoolClass::query()->visibleTo($teacher)->whereKey($class->id)->exists())->toBeTrue();
});

test('manual classes leave external columns null and several coexist', function () {
    $teacher = asTeacher();
    $service = app(SchoolClassService::class);
    $a = $service->create($teacher, ['name' => 'A', 'school_year' => '2026-2027']);
    $b = $service->create($teacher, ['name' => 'B', 'school_year' => '2026-2027']);

    expect($a->external_provider)->toBeNull()
        ->and($b->external_id)->toBeNull()
        ->and(SchoolClass::query()->visibleTo($teacher)->count())->toBeGreaterThanOrEqual(2);
});

test('teacher lists are SQL-scoped; admin sees all; inactive stays recoverable', function () {
    $fx = phase5cFixture();
    $outsider = asTeacher();
    $admin = asAdmin();

    expect(SchoolClass::query()->visibleTo($fx['teacher'])->pluck('id')->all())
        ->toContain($fx['class']->id)
        ->and(SchoolClass::query()->visibleTo($outsider)->pluck('id')->all())
        ->not->toContain($fx['class']->id)
        ->and(SchoolClass::query()->visibleTo($admin)->pluck('id')->all())
        ->toContain($fx['class']->id);

    app(SchoolClassService::class)->update($fx['class'], ['active' => false]);

    expect(SchoolClass::query()->visibleTo($fx['teacher'])->whereKey($fx['class']->id)->exists())->toBeTrue();

    app(SchoolClassService::class)->update($fx['class']->fresh(), ['active' => true]);
    expect($fx['class']->fresh()->active)->toBeTrue();
});

test('final active teacher cannot withdraw or be demoted; second teacher can', function () {
    $fx = phase5cFixture();
    $memberships = app(ClassMembershipService::class);
    $teacherMembership = ClassMembership::query()
        ->where('school_class_id', $fx['class']->id)
        ->where('user_id', $fx['teacher']->id)
        ->firstOrFail();

    expect(fn () => $memberships->withdraw($teacherMembership, $fx['teacher']))
        ->toThrow(ValidationException::class);

    expect(fn () => $memberships->changeRole($teacherMembership, ClassRole::Student, $fx['teacher']))
        ->toThrow(ValidationException::class);

    $memberships->addOrReactivateTeacher($fx['class'], $fx['otherTeacher'], $fx['teacher']);
    $memberships->withdraw($teacherMembership->fresh(), $fx['teacher']);

    expect($teacherMembership->fresh()->isActive())->toBeFalse();
});

test('global student cannot receive teacher membership; reactivation restores access', function () {
    $fx = phase5cFixture();
    $memberships = app(ClassMembershipService::class);

    expect(fn () => $memberships->addOrReactivateTeacher($fx['class'], $fx['student'], $fx['teacher']))
        ->toThrow(ValidationException::class);

    $studentMembership = ClassMembership::query()
        ->where('school_class_id', $fx['class']->id)
        ->where('user_id', $fx['student']->id)
        ->firstOrFail();

    $memberships->withdraw($studentMembership, $fx['teacher']);
    expect($studentMembership->fresh()->withdrawn_at)->not->toBeNull()
        ->and(ClassMembership::query()->whereKey($studentMembership->id)->exists())->toBeTrue();

    $memberships->addOrReactivateStudent($fx['class'], $fx['student'], $fx['teacher']);
    expect($studentMembership->fresh()->isActive())->toBeTrue();
});

test('access triad: inactive class and archived assignment refuse start but allow resume', function () {
    $fx = phase5cFixture();
    $access = app(LessonAssignmentService::class);
    $attempts = app(AttemptService::class);

    $started = $attempts->resolveForAssignment($fx['student'], $fx['assignment'])['attempt'];
    expect($started->status)->toBe(AttemptStatus::InProgress);

    app(SchoolClassService::class)->update($fx['class'], ['active' => false]);
    $assignment = $fx['assignment']->fresh(['schoolClass']);

    expect($access->mayStartAttempt($fx['student'], $assignment))->toBeFalse()
        ->and($access->mayResumeAttempt($fx['student'], $assignment))->toBeTrue()
        ->and($access->mayViewAssignment($fx['student'], $assignment))->toBeTrue();

    app(SchoolClassService::class)->update($fx['class']->fresh(), ['active' => true]);
    $access->archive($fx['assignment']->fresh(), $fx['teacher']);
    $archived = $fx['assignment']->fresh(['schoolClass']);

    expect($access->mayStartAttempt($fx['student'], $archived))->toBeFalse()
        ->and($access->mayResumeAttempt($fx['student'], $archived))->toBeTrue();
});

test('withdrawn membership refuses start and resume even when class is inactive', function () {
    $fx = phase5cFixture();
    $access = app(LessonAssignmentService::class);
    app(AttemptService::class)->resolveForAssignment($fx['student'], $fx['assignment']);

    $studentMembership = ClassMembership::query()
        ->where('school_class_id', $fx['class']->id)
        ->where('user_id', $fx['student']->id)
        ->firstOrFail();
    app(ClassMembershipService::class)->withdraw($studentMembership, $fx['teacher']);
    app(SchoolClassService::class)->update($fx['class'], ['active' => false]);

    $assignment = $fx['assignment']->fresh(['schoolClass']);

    expect($access->mayStartAttempt($fx['student'], $assignment))->toBeFalse()
        ->and($access->mayResumeAttempt($fx['student'], $assignment))->toBeFalse()
        ->and($access->mayViewAssignment($fx['student'], $assignment))->toBeTrue();
});

test('future available_at permits view and refuses start', function () {
    $fx = phase5cFixture();
    $access = app(LessonAssignmentService::class);
    $access->update($fx['assignment'], ['available_at' => now()->addDay()]);
    $assignment = $fx['assignment']->fresh(['schoolClass']);

    expect($access->mayViewAssignment($fx['student'], $assignment))->toBeTrue()
        ->and($access->mayStartAttempt($fx['student'], $assignment))->toBeFalse();

    asStudent($fx['student']);
    $this->get(route('player.assignments.show', $assignment))
        ->assertForbidden()
        ->assertSee(__('player.assignment_unavailable_title'), false);
});

test('assignment create pins version at transaction time; only published lessons assignable', function () {
    $fx = phase5cFixture(twoVersions: true);
    $access = app(LessonAssignmentService::class);
    $access->archive($fx['assignment'], $fx['teacher']);

    $vCurrent = $fx['lesson']->fresh()->currentVersion();
    $created = $access->create($fx['class'], $fx['teacher'], [
        'lesson_id' => $fx['lesson']->id,
        'available_at' => now()->subHour(),
    ]);

    expect($created->lesson_version_id)->toBe($vCurrent->id);

    $draft = Lesson::factory()->create(['status' => LessonStatus::Draft, 'current_version' => 0]);
    expect(fn () => $access->create($fx['class'], $fx['teacher'], ['lesson_id' => $draft->id]))
        ->toThrow(ValidationException::class);
});

test('teacher can assign another teachers published lesson to their class', function () {
    $fx = phase5cFixture();
    expect($fx['assignment']->lesson->created_by_user_id)->toBe($fx['otherTeacher']->id)
        ->and($fx['assignment']->school_class_id)->toBe($fx['class']->id);
});

test('archiving allows reassign; unarchive refused while active twin exists; audit rows accumulate', function () {
    $fx = phase5cFixture();
    $access = app(LessonAssignmentService::class);

    $access->archive($fx['assignment'], $fx['teacher']);
    expect($fx['assignment']->fresh()->isArchived())->toBeTrue()
        ->and(LessonAssignmentStatusChange::query()->where('lesson_assignment_id', $fx['assignment']->id)->where('action', 'archived')->count())->toBe(1);

    $second = $access->create($fx['class'], $fx['teacher'], [
        'lesson_id' => $fx['lesson']->id,
        'available_at' => now()->subHour(),
    ]);

    expect(fn () => $access->create($fx['class'], $fx['teacher'], ['lesson_id' => $fx['lesson']->id]))
        ->toThrow(ValidationException::class);

    expect(fn () => $access->unarchive($fx['assignment']->fresh(), $fx['teacher']))
        ->toThrow(ValidationException::class);

    $access->archive($second, $fx['teacher']);
    $access->unarchive($fx['assignment']->fresh(), $fx['teacher']);

    expect($fx['assignment']->fresh()->isArchived())->toBeFalse()
        ->and(LessonAssignmentStatusChange::query()->where('lesson_assignment_id', $fx['assignment']->id)->count())->toBe(2);
});

test('archiving does not touch attempts; archived cannot be edited or repinned', function () {
    $fx = phase5cFixture();
    $access = app(LessonAssignmentService::class);
    $attempt = app(AttemptService::class)->resolveForAssignment($fx['student'], $fx['assignment'])['attempt'];
    $access->archive($fx['assignment'], $fx['teacher']);

    expect($attempt->fresh()->status)->toBe(AttemptStatus::InProgress)
        ->and(fn () => $access->update($fx['assignment']->fresh(), ['due_at' => now()->addMonth()]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $access->repinToCurrentVersion($fx['assignment']->fresh(), $fx['teacher']))
        ->toThrow(ValidationException::class);
});

test('repin writes audit, leaves attempts, no-ops when current, refuses archived lesson', function () {
    $fx = phase5cFixture(twoVersions: true);
    $access = app(LessonAssignmentService::class);

    // Pin was created after both publishes — already on latest. Force older pin.
    $versions = $fx['lesson']->versions()->orderBy('version')->get();
    $fx['assignment']->forceFill(['lesson_version_id' => $versions->first()->id])->save();
    $attempt = app(AttemptService::class)->resolveForAssignment($fx['student'], $fx['assignment']->fresh())['attempt'];
    expect($attempt->lesson_version_id)->toBe($versions->first()->id);

    $repinned = $access->repinToCurrentVersion($fx['assignment']->fresh(), $fx['teacher']);
    expect($repinned->lesson_version_id)->toBe($versions->last()->id)
        ->and($attempt->fresh()->lesson_version_id)->toBe($versions->first()->id)
        ->and(LessonAssignmentVersionChange::query()->where('lesson_assignment_id', $fx['assignment']->id)->count())->toBe(1);

    $again = $access->repinToCurrentVersion($repinned, $fx['teacher']);
    expect($again->lesson_version_id)->toBe($versions->last()->id)
        ->and(LessonAssignmentVersionChange::query()->where('lesson_assignment_id', $fx['assignment']->id)->count())->toBe(1);

    $fx['lesson']->forceFill(['status' => LessonStatus::Archived])->save();
    expect(fn () => $access->repinToCurrentVersion($fx['assignment']->fresh(), $fx['teacher']))
        ->toThrow(ValidationException::class);
});

test('delete with attempts refuses clearly; delete without attempts succeeds; audit history blocks delete', function () {
    $fx = phase5cFixture();
    $access = app(LessonAssignmentService::class);
    app(AttemptService::class)->resolveForAssignment($fx['student'], $fx['assignment']);

    expect(fn () => $access->delete($fx['assignment']))
        ->toThrow(ValidationException::class);

    $empty = $access->create(
        app(SchoolClassService::class)->create($fx['teacher'], ['name' => 'Empty', 'school_year' => '2026-2027']),
        $fx['teacher'],
        ['lesson_id' => $fx['lesson']->id, 'available_at' => now()->subHour()]
    );
    $emptyId = $empty->id;
    $access->delete($empty);
    expect(LessonAssignment::query()->whereKey($emptyId)->exists())->toBeFalse();

    $access->archive($fx['assignment']->fresh(), $fx['teacher']);
    // Clear attempts so only audit history remains as the blocker after archive path…
    // Attempts still exist — delete still refused for attempts first.
    expect(fn () => $access->delete($fx['assignment']->fresh()))
        ->toThrow(ValidationException::class);
});

test('deleting a repinned assignment without attempts is refused because of audit FK policy', function () {
    $fx = phase5cFixture(twoVersions: true);
    $access = app(LessonAssignmentService::class);
    $versions = $fx['lesson']->versions()->orderBy('version')->get();
    $fx['assignment']->forceFill(['lesson_version_id' => $versions->first()->id])->save();
    $access->repinToCurrentVersion($fx['assignment']->fresh(), $fx['teacher']);

    expect(fn () => $access->delete($fx['assignment']->fresh()))
        ->toThrow(ValidationException::class)
        ->and(LessonAssignment::query()->whereKey($fx['assignment']->id)->exists())->toBeTrue();
});

test('active_scope generated column exists on mysql', function () {
    if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Generated column is MySQL/MariaDB only.');
    }

    expect(Schema::hasColumn('lesson_assignments', 'active_scope'))->toBeTrue()
        ->and(Schema::hasColumn('lesson_assignments', 'archived_at'))->toBeTrue();

    $indexes = collect(DB::select('SHOW INDEX FROM lesson_assignments'))
        ->where('Key_name', 'lesson_assignments_one_active');

    expect($indexes)->not->toBeEmpty()
        ->and($indexes->pluck('Column_name')->all())->toContain('active_scope');
});

test('migration fails clearly on pre-existing duplicate active assignments', function () {
    if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('active_scope migration is MySQL/MariaDB only.');
    }

    $fx = phase5cFixture();

    // Simulate a pre-migration schema: drop the generated column + archived_at,
    // then insert a second active pair the index would reject.
    Schema::table('lesson_assignments', function ($table) {
        $table->dropUnique('lesson_assignments_one_active');
        $table->dropColumn(['active_scope', 'archived_at']);
    });

    DB::table('lesson_assignments')->insert([
        'school_class_id' => $fx['class']->id,
        'lesson_id' => $fx['lesson']->id,
        'lesson_version_id' => $fx['assignment']->lesson_version_id,
        'assigned_by_user_id' => $fx['teacher']->id,
        'available_at' => now()->subHour(),
        'due_at' => null,
        'settings' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ids = DB::table('lesson_assignments')
        ->where('school_class_id', $fx['class']->id)
        ->where('lesson_id', $fx['lesson']->id)
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect(count($ids))->toBe(2);

    $migration = require database_path('migrations/2026_07_30_000020_add_active_scope_to_lesson_assignments_table.php');

    try {
        $migration->up();
        $this->fail('Expected migration to refuse duplicate active assignments.');
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toContain('duplicate active assignments')
            ->and($e->getMessage())->toContain((string) $ids[0])
            ->and($e->getMessage())->toContain((string) $ids[1]);
    }

    // Restore a valid schema for the rest of the suite / tearDown.
    DB::table('lesson_assignments')->where('id', $ids[1])->delete();
    $migration->up();
});

test('immutable assignment audit models refuse update and delete', function () {
    $fx = phase5cFixture();
    $access = app(LessonAssignmentService::class);
    $access->archive($fx['assignment'], $fx['teacher']);
    $row = LessonAssignmentStatusChange::query()->firstOrFail();

    expect(fn () => $row->update(['action' => 'unarchived']))
        ->toThrow(App\Exceptions\ImmutableLessonAssignmentAuditException::class)
        ->and(fn () => $row->delete())
        ->toThrow(App\Exceptions\ImmutableLessonAssignmentAuditException::class);
});

test('filament class pages: teacher 403 on foreign class; membership mismatch 404; student blocked', function () {
    $fx = phase5cFixture();
    $otherClass = app(SchoolClassService::class)->create($fx['otherTeacher'], [
        'name' => 'Other',
        'school_year' => '2026-2027',
    ]);
    $otherMembership = ClassMembership::query()
        ->where('school_class_id', $otherClass->id)
        ->where('user_id', $fx['otherTeacher']->id)
        ->firstOrFail();
    $myMembership = ClassMembership::query()
        ->where('school_class_id', $fx['class']->id)
        ->where('user_id', $fx['teacher']->id)
        ->firstOrFail();

    // SQL-scoped resource query: a foreign class is absent → 404, not a
    // disclosure that the id exists. Direct policy still refuses manage.
    $this->actingAs($fx['teacher'])
        ->get(SchoolClassResource::getUrl('edit', ['record' => $otherClass]))
        ->assertNotFound();
    expect($fx['teacher']->can('manage', $otherClass))->toBeFalse();

    $this->actingAs($fx['teacher'])
        ->get(ClassMembershipResource::getUrl('edit', [
            'school_class' => $fx['class'],
            'record' => $otherMembership,
        ]))
        ->assertNotFound();

    // Parent class is foreign → authorizeParentRecordAccess 403 before
    // the assignment belonging check.
    $this->actingAs($fx['teacher'])
        ->get(ClassAssignmentResource::getUrl('edit', [
            'school_class' => $otherClass,
            'record' => $fx['assignment'],
        ]))
        ->assertForbidden();

    $this->actingAs($fx['student'])
        ->get(SchoolClassResource::getUrl('index'))
        ->assertForbidden();

    $this->actingAs($fx['teacher'])
        ->get(ClassMembershipResource::getUrl('edit', [
            'school_class' => $fx['class'],
            'record' => $myMembership,
        ]))
        ->assertOk();
});

test('seeded welding class is reachable in filament for the teacher', function () {
    $this->seed(Database\Seeders\DatabaseSeeder::class);
    $teacher = User::query()->where('email', Database\Seeders\UserSeeder::TEACHER_EMAIL)->firstOrFail();
    $class = SchoolClass::query()->where('name', Database\Seeders\ClassRosterSeeder::CLASS_NAME)->firstOrFail();
    $assignment = LessonAssignment::query()->where('school_class_id', $class->id)->firstOrFail();

    $this->actingAs($teacher)
        ->get(SchoolClassResource::getUrl('edit', ['record' => $class]))
        ->assertOk();

    $this->actingAs($teacher)
        ->get(ClassAssignmentResource::getUrl('edit', [
            'school_class' => $class,
            'record' => $assignment,
        ]))
        ->assertOk();
});

test('player resumes on inactive class and archived assignment; withdrawn blocks resume', function () {
    $fx = phase5cFixture();
    asStudent($fx['student']);
    $this->get(route('player.assignments.show', $fx['assignment']))->assertOk();

    app(SchoolClassService::class)->update($fx['class'], ['active' => false]);
    $this->get(route('player.assignments.show', $fx['assignment']->fresh()))->assertOk();

    app(SchoolClassService::class)->update($fx['class']->fresh(), ['active' => true]);
    app(LessonAssignmentService::class)->archive($fx['assignment']->fresh(), $fx['teacher']);
    $this->get(route('player.assignments.show', $fx['assignment']->fresh()))->assertOk();

    $membership = ClassMembership::query()
        ->where('school_class_id', $fx['class']->id)
        ->where('user_id', $fx['student']->id)
        ->firstOrFail();
    app(ClassMembershipService::class)->withdraw($membership, $fx['teacher']);
    app(LessonAssignmentService::class)->unarchive($fx['assignment']->fresh(), $fx['teacher']);

    $this->get(route('player.assignments.show', $fx['assignment']->fresh()))
        ->assertForbidden()
        ->assertSee(__('player.assignment_withdrawn_title'), false);
});
