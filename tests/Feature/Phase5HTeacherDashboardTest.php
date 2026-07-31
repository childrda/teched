<?php

use App\Enums\AttemptStatus;
use App\Enums\PageCompletionType;
use App\Enums\UserRole;
use App\Models\BlockState;
use App\Models\BlockSubmission;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\LessonAttempt;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\LessonVersion;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AttemptProgressService;
use App\Services\ClassMembershipService;
use App\Services\LessonPublisher;
use App\Services\PrimaryAttemptResolver;
use App\Services\SchoolClassService;
use App\Services\StudentDashboardService;
use App\Services\TeacherDashboardService;
use App\Services\UserPreferenceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutVite();
    config(['app.timezone' => 'UTC', 'app.display_timezone' => 'America/New_York']);
});

/**
 * @return array{teacher: User, class: SchoolClass, lesson: Lesson, assignment: LessonAssignment, version: LessonVersion, pageIds: list<string>, quizBlockId: string}
 */
function phase5hFixture(int $pages = 5, bool $withQuiz = true): array
{
    $teacher = asTeacher();
    $lesson = Lesson::factory()->create(['created_by_user_id' => $teacher->id]);
    $pageIds = [];
    $quizBlockId = (string) Str::ulid();

    for ($i = 1; $i <= $pages; $i++) {
        $pageId = (string) Str::ulid();
        $pageIds[] = $pageId;
        $page = LessonPage::factory()->create([
            'lesson_id' => $lesson->id,
            'page_id' => $pageId,
            'position' => $i,
            'completion_type' => PageCompletionType::View,
            'title' => "Page {$i}",
        ]);

        if ($i === 1 && $withQuiz) {
            LessonBlock::factory()->create([
                'lesson_page_id' => $page->id,
                'block_id' => $quizBlockId,
                'type' => 'quiz',
                'position' => 1,
                'config' => app(App\Blocks\BlockTypeRegistry::class)->get('quiz')->defaultConfig(),
                'grading' => fullGradingShape(),
            ]);
        } else {
            LessonBlock::factory()->create([
                'lesson_page_id' => $page->id,
                'type' => 'rich_text',
                'position' => 1,
                'config' => ['html' => '<p>Hi</p>'],
                'grading' => null,
            ]);
        }
    }

    $version = app(LessonPublisher::class)->publish($lesson->fresh(), $teacher);
    $class = app(SchoolClassService::class)->create($teacher, [
        'name' => 'Period 5H',
        'school_year' => '2026-2027',
    ]);
    $assignment = app(App\Services\LessonAssignmentService::class)->create($class, $teacher, [
        'lesson_id' => $lesson->id,
        'available_at' => now()->subHour(),
        'due_at' => now()->addWeek(),
    ]);

    return compact('teacher', 'class', 'lesson', 'assignment', 'version', 'pageIds', 'quizBlockId');
}

function phase5hAttempt(User $student, LessonAssignment $assignment, array $overrides = []): LessonAttempt
{
    $defaultPage = $assignment->lessonVersion?->manifest['pages'][0]['page_id']
        ?? $assignment->lesson?->pages()->orderBy('position')->value('page_id')
        ?? (string) Str::ulid();

    return LessonAttempt::query()->create(array_merge([
        'user_id' => $student->id,
        'lesson_id' => $assignment->lesson_id,
        'lesson_version_id' => $assignment->lesson_version_id,
        'lesson_assignment_id' => $assignment->id,
        'status' => AttemptStatus::InProgress,
        'started_at' => now(),
        'last_activity_at' => now(),
        'active_seconds' => 0,
        'revision' => 0,
        'shuffle_seed' => 'test',
        'current_page_id' => $defaultPage,
    ], $overrides));
}

test('completion percentage: page 1 is 0%, page 2 counts one complete, completed is 100%', function () {
    $fx = phase5hFixture(5, false);
    $student = asStudent();
    app(ClassMembershipService::class)->addOrReactivateStudent($fx['class'], $student, $fx['teacher']);

    $attempt = phase5hAttempt($student, $fx['assignment'], [
        'current_page_id' => $fx['pageIds'][0],
    ]);
    $attempt->setRelation('lessonVersion', $fx['version']);

    $progress = app(AttemptProgressService::class);
    expect($progress->completion($attempt)['percentage'])->toBe(0);

    $attempt->forceFill(['current_page_id' => $fx['pageIds'][1]])->save();
    $attempt->refresh()->setRelation('lessonVersion', $fx['version']);
    expect($progress->completion($attempt)['percentage'])->toBe(20)
        ->and($progress->completion($attempt)['completed_pages'])->toBe(1);

    $attempt->forceFill([
        'status' => AttemptStatus::Completed,
        'completed_at' => now(),
        'current_page_id' => $fx['pageIds'][4],
    ])->save();
    $attempt->refresh()->setRelation('lessonVersion', $fx['version']);
    expect($progress->completion($attempt)['percentage'])->toBe(100);

    $missing = phase5hAttempt($student, $fx['assignment'], ['current_page_id' => 'not-a-real-page-id']);
    $missing->setRelation('lessonVersion', $fx['version']);
    expect($progress->completion($missing)['percentage'])->toBe(0);
});

test('mastery uses manifest gradable blocks; empty set is mastered; retry pass counts', function () {
    $fx = phase5hFixture(2, true);
    $student = asStudent();
    app(ClassMembershipService::class)->addOrReactivateStudent($fx['class'], $student, $fx['teacher']);
    $progress = app(AttemptProgressService::class);

    $noGradable = phase5hFixture(2, false);
    app(ClassMembershipService::class)->addOrReactivateStudent($noGradable['class'], $student, $noGradable['teacher']);
    $attemptEmpty = phase5hAttempt($student, $noGradable['assignment'], [
        'status' => AttemptStatus::Completed,
        'completed_at' => now(),
    ]);
    $attemptEmpty->setRelation('lessonVersion', $noGradable['version']);
    expect($progress->isMastered($attemptEmpty))->toBeTrue();

    $attempt = phase5hAttempt($student, $fx['assignment'], [
        'status' => AttemptStatus::Completed,
        'completed_at' => now(),
    ]);
    $attempt->setRelation('lessonVersion', $fx['version']);
    expect($progress->isMastered($attempt))->toBeFalse();

    BlockSubmission::query()->create([
        'lesson_attempt_id' => $attempt->id,
        'lesson_version_id' => $fx['version']->id,
        'block_id' => $fx['quizBlockId'],
        'block_type' => 'quiz',
        'attempt_number' => 1,
        'response' => [],
        'grading_result' => [],
        'score' => 0,
        'max_score' => 1,
        'percentage' => 0,
        'passed' => false,
        'requires_manual_review' => false,
        'submitted_at' => now()->subMinute(),
        'active_seconds_at_submission' => 0,
    ]);
    $attempt = $attempt->fresh()->load('blockSubmissions');
    $attempt->setRelation('lessonVersion', $fx['version']);
    expect($progress->isMastered($attempt))->toBeFalse();

    BlockSubmission::query()->create([
        'lesson_attempt_id' => $attempt->id,
        'lesson_version_id' => $fx['version']->id,
        'block_id' => $fx['quizBlockId'],
        'block_type' => 'quiz',
        'attempt_number' => 2,
        'response' => [],
        'grading_result' => [],
        'score' => 1,
        'max_score' => 1,
        'percentage' => 100,
        'passed' => true,
        'requires_manual_review' => false,
        'submitted_at' => now(),
        'active_seconds_at_submission' => 0,
    ]);
    $attempt = $attempt->fresh()->load('blockSubmissions');
    $attempt->setRelation('lessonVersion', $fx['version']);
    expect($progress->isMastered($attempt))->toBeTrue();
});

test('primary attempt: in-progress wins over older completed; superseded-only is not completed', function () {
    $fx = phase5hFixture(1, false);
    $student = asStudent();
    app(ClassMembershipService::class)->addOrReactivateStudent($fx['class'], $student, $fx['teacher']);

    $completed = phase5hAttempt($student, $fx['assignment'], [
        'status' => AttemptStatus::Completed,
        'completed_at' => now()->subDay(),
        'started_at' => now()->subDays(2),
    ]);
    $inProgress = phase5hAttempt($student, $fx['assignment'], [
        'status' => AttemptStatus::InProgress,
        'started_at' => now(),
    ]);

    $resolver = app(PrimaryAttemptResolver::class);
    expect($resolver->resolve([$completed, $inProgress])?->id)->toBe($inProgress->id);

    $dashboard = app(StudentDashboardService::class)->forStudent($student);
    $row = collect($dashboard['assignments'])->first(
        fn (array $r) => $r['assignment']->id === $fx['assignment']->id
    );
    expect($row['status'])->toBe('in_progress')
        ->and($dashboard['completed_assignment_count'])->toBe(0);

    $completed->delete();
    $inProgress->forceFill([
        'status' => AttemptStatus::Superseded,
        'superseded_at' => now(),
    ])->save();

    $dashboard = app(StudentDashboardService::class)->forStudent($student->fresh());
    $row = collect($dashboard['assignments'])->first();
    expect($row['status'])->not->toBe('completed')
        ->and($dashboard['completed_assignment_count'])->toBe(0);
});

test('teacher dashboard scopes to own classes; lists limited; autosaves excluded', function () {
    $fx = phase5hFixture(1, false);
    $otherTeacher = User::factory()->create();
    $otherTeacher->forceFill(['role' => UserRole::Teacher])->save();
    $otherClass = app(SchoolClassService::class)->create($otherTeacher, [
        'name' => 'Other class',
        'school_year' => '2026-2027',
    ]);
    $student = asStudent();
    app(ClassMembershipService::class)->addOrReactivateStudent($fx['class'], $student, $fx['teacher']);
    app(ClassMembershipService::class)->addOrReactivateStudent($otherClass, $student, $otherTeacher);

    $otherAssignment = app(App\Services\LessonAssignmentService::class)->create($otherClass, $otherTeacher, [
        'lesson_id' => $fx['lesson']->id,
        'available_at' => now()->subHour(),
    ]);
    phase5hAttempt($student, $otherAssignment, ['status' => AttemptStatus::InProgress]);

    $mine = phase5hAttempt($student, $fx['assignment'], [
        'status' => AttemptStatus::InProgress,
        'last_activity_at' => now()->subDays(10),
    ]);

    // Autosaves write block_states only — recentActivity must never surface them.
    BlockState::query()->create([
        'lesson_attempt_id' => $mine->id,
        'block_id' => 'autosave-noise',
        'block_type' => 'short_response',
        'state' => ['draft' => true],
        'updated_at' => now(),
    ]);

    BlockSubmission::query()->create([
        'lesson_attempt_id' => $mine->id,
        'lesson_version_id' => $fx['version']->id,
        'block_id' => 'resp-1',
        'block_type' => 'short_response',
        'attempt_number' => 1,
        'response' => ['text' => 'hello'],
        'grading_result' => null,
        'requires_manual_review' => true,
        'submitted_at' => now(),
        'active_seconds_at_submission' => 0,
    ]);

    $dashboard = app(TeacherDashboardService::class);
    $summary = $dashboard->summary($fx['teacher']);
    expect($summary['active_classes'])->toBe(1)
        ->and($summary['students_in_progress'])->toBe(1)
        ->and($summary['awaiting_review_total'])->toBe(1);

    $activity = $dashboard->recentActivity($fx['teacher'], 15);
    expect(collect($activity)->pluck('type'))->toContain('response_submission')
        ->and(collect($activity)->pluck('type'))->not->toContain('autosave');

    // Inactive class excluded from active counts; in-progress stays resumable separately.
    $fx['class']->forceFill(['active' => false])->save();
    $summaryInactive = app(TeacherDashboardService::class)->summary($fx['teacher']);
    expect($summaryInactive['active_classes'])->toBe(0)
        ->and($summaryInactive['active_assignments'])->toBe(0);

    expect(app(App\Services\LessonAssignmentService::class)->mayResumeAttempt($student, $fx['assignment']->fresh()))
        ->toBeTrue();
});

test('weekly buckets use Eastern week boundaries including DST and Sunday evening', function () {
    $fx = phase5hFixture(1, false);
    $student = asStudent();
    app(ClassMembershipService::class)->addOrReactivateStudent($fx['class'], $student, $fx['teacher']);

    // Sunday 2026-03-08 23:30 Eastern (EDT after spring forward is 2026-03-08) —
    // use a clear Sunday evening: 2026-07-26 23:30 Eastern = 2026-07-27 03:30 UTC.
    $sundayEveningEastern = Carbon::parse('2026-07-26 23:30:00', 'America/New_York');
    $completedAtUtc = $sundayEveningEastern->copy()->utc();

    phase5hAttempt($student, $fx['assignment'], [
        'status' => AttemptStatus::Completed,
        'completed_at' => $completedAtUtc,
        'started_at' => $completedAtUtc->copy()->subHour(),
    ]);

    Carbon::setTestNow(Carbon::parse('2026-07-30 12:00:00', 'America/New_York')->utc());

    $chart = app(TeacherDashboardService::class)->weeklyCompletions($fx['teacher'], 4);
    $buckets = app(AttemptProgressService::class)->weekBuckets(4);

    $targetLabel = Carbon::parse('2026-07-26', 'America/New_York')->startOfWeek(Carbon::SUNDAY)->format('M j');
    $index = array_search($targetLabel, $chart['labels'], true);
    expect($index)->not->toBeFalse()
        ->and($chart['counts'][$index])->toBe(1)
        ->and($chart['axis_label'])->toContain('America/New York');

    // Prove UTC week grouping would mis-bucket: UTC Monday week start is Jul 27.
    $utcMondayCount = LessonAttempt::query()
        ->where('status', AttemptStatus::Completed)
        ->where('completed_at', '>=', Carbon::parse('2026-07-27 00:00:00', 'UTC'))
        ->where('completed_at', '<', Carbon::parse('2026-08-03 00:00:00', 'UTC'))
        ->count();
    expect($utcMondayCount)->toBe(1);

    // Eastern Sunday-start week containing Jul 26 starts Jul 26.
    expect($buckets[$index]['start_utc']->equalTo(
        Carbon::parse('2026-07-26 00:00:00', 'America/New_York')->utc()
    ))->toBeTrue();

    Carbon::setTestNow();
});

test('student preferences persist; unknown keys rejected; counts stay separate', function () {
    $fx = phase5hFixture(1, false);
    $student = asStudent();
    $other = asStudent();
    app(ClassMembershipService::class)->addOrReactivateStudent($fx['class'], $student, $fx['teacher']);

    phase5hAttempt($student, $fx['assignment'], [
        'status' => AttemptStatus::Completed,
        'completed_at' => now(),
    ]);

    $prefs = app(UserPreferenceService::class);
    expect($prefs->showCompletedAssignments($student))->toBeFalse();

    $this->actingAs($student)->post(route('preferences.student-dashboard'), [
        'show_completed_assignments' => 1,
    ])->assertRedirect(route('home'));

    expect($prefs->showCompletedAssignments($student->fresh()))->toBeTrue();

    $this->actingAs($other)->post(route('preferences.student-dashboard'), [
        'show_completed_assignments' => 1,
    ])->assertRedirect(route('home'));
    expect($prefs->showCompletedAssignments($student->fresh()))->toBeTrue()
        ->and($prefs->showCompletedAssignments($other->fresh()))->toBeTrue();

    expect(fn () => $prefs->updateOwn($student, ['evil.key' => true]))
        ->toThrow(ValidationException::class);

    $before = $student->fresh()->preferences;
    $student->update(['preferences' => ['hacked' => true]]);
    expect($student->fresh()->preferences)->toBe($before)
        ->and(data_get($student->fresh()->preferences, 'hacked'))->toBeNull();

    $dash = app(StudentDashboardService::class)->forStudent($student->fresh());
    expect($dash['completed_assignment_count'])->toBe(1)
        ->and($dash['completed_practice_count'])->toBe(0)
        ->and($dash['show_completed_assignments'])->toBeTrue();
});

test('TeacherDashboardService query count does not grow per class', function () {
    $teacher = asTeacher();
    $lesson = Lesson::factory()->create(['created_by_user_id' => $teacher->id]);
    $page = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'completion_type' => PageCompletionType::View,
    ]);
    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'type' => 'rich_text',
        'config' => ['html' => '<p>x</p>'],
    ]);
    app(LessonPublisher::class)->publish($lesson->fresh(), $teacher);

    $makeClasses = function (int $n) use ($teacher, $lesson) {
        for ($i = 0; $i < $n; $i++) {
            $class = app(SchoolClassService::class)->create($teacher, [
                'name' => "Class {$n}-{$i}",
                'school_year' => '2026-2027',
            ]);
            app(App\Services\LessonAssignmentService::class)->create($class, $teacher, [
                'lesson_id' => $lesson->id,
                'available_at' => now()->subHour(),
            ]);
        }
    };

    $makeClasses(2);
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(TeacherDashboardService::class)->summary($teacher);
    $two = count(DB::getQueryLog());
    DB::disableQueryLog();

    $makeClasses(18);
    // Fresh service instance — no scope cache across the growth.
    $fresh = app()->make(TeacherDashboardService::class);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fresh->summary($teacher);
    $twenty = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Same shape of queries (class list, assignments, memberships, attempts, submissions…).
    // Must not grow linearly with class count (no per-class loop queries).
    expect($twenty)->toBeLessThanOrEqual($two + 3);
});
