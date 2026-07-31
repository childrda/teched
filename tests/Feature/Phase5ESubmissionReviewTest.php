<?php

use App\Enums\AttemptStatus;
use App\Enums\ClassRole;
use App\Enums\PageCompletionType;
use App\Enums\UserRole;
use App\Exceptions\ImmutableBlockSubmissionReviewException;
use App\Models\BlockSubmission;
use App\Models\BlockSubmissionReview;
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
use App\Services\LessonPublisher;
use App\Services\SubmissionReviewService;
use App\Support\ManualReviewScore;
use App\Support\StudentManualReview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * @return array{
 *     teacher: User,
 *     coTeacher: User,
 *     otherTeacher: User,
 *     student: User,
 *     otherStudent: User,
 *     class: SchoolClass,
 *     otherClass: SchoolClass,
 *     lesson: Lesson,
 *     assignment: LessonAssignment,
 *     attempt: LessonAttempt,
 *     cerSubmission: BlockSubmission,
 *     shortSubmission: BlockSubmission,
 *     quizSubmission: BlockSubmission,
 *     cerBlockId: string,
 *     shortBlockId: string,
 *     quizBlockId: string,
 *     cer3Fields: int,
 *     pageId: string
 * }
 */
function phase5eFixture(): array
{
    $teacher = asTeacher();
    $coTeacher = User::factory()->create();
    $coTeacher->forceFill(['role' => UserRole::Teacher])->save();
    $otherTeacher = User::factory()->create();
    $otherTeacher->forceFill(['role' => UserRole::Teacher])->save();
    $student = User::factory()->create();
    $student->refresh();
    $otherStudent = User::factory()->create();
    $otherStudent->refresh();

    $cerBlockId = (string) Str::ulid();
    $shortBlockId = (string) Str::ulid();
    $quizBlockId = (string) Str::ulid();
    $pageId = (string) Str::ulid();

    $lesson = Lesson::factory()->create(['created_by_user_id' => $teacher->id]);
    $page = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'page_id' => $pageId,
        'position' => 1,
        'completion_type' => PageCompletionType::SubmitRequired,
        'title' => 'Writing',
    ]);

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'block_id' => $cerBlockId,
        'type' => 'cer',
        'position' => 1,
        'config' => [
            'scenario_html' => '<p>Scenario</p>',
            'fields' => [
                ['id' => 'f1', 'label' => 'Claim', 'placeholder' => null, 'min_length' => 1],
                ['id' => 'f2', 'label' => 'Evidence', 'placeholder' => null, 'min_length' => 1],
                ['id' => 'f3', 'label' => 'Reasoning', 'placeholder' => null, 'min_length' => 1],
            ],
        ],
        'grading' => null,
    ]);

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'block_id' => $shortBlockId,
        'type' => 'short_response',
        'position' => 2,
        'config' => [
            'prompt_html' => '<p>Prompt</p>',
            'placeholder' => null,
            'min_length' => 1,
            'rubric_html' => '<p>secret rubric</p>',
        ],
        'grading' => null,
    ]);

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'block_id' => $quizBlockId,
        'type' => 'quiz',
        'position' => 3,
        'config' => [
            'shuffle_questions' => false,
            'questions' => [[
                'id' => 'q1',
                'prompt' => 'Pick',
                'options' => [
                    ['id' => 'a', 'text' => 'A'],
                    ['id' => 'b', 'text' => 'B'],
                ],
                'answer_id' => 'a',
                'feedback' => null,
                'source_ref' => null,
            ]],
        ],
        'grading' => fullGradingShape(),
    ]);

    app(LessonPublisher::class)->publish($lesson, $teacher);
    $lesson = $lesson->fresh();
    $version = $lesson->currentVersion();

    $class = SchoolClass::query()->create([
        'name' => 'Review Class',
        'teacher_id' => $teacher->id,
        'school_year' => '2026-2027',
        'active' => true,
    ]);
    $otherClass = SchoolClass::query()->create([
        'name' => 'Other Class',
        'teacher_id' => $otherTeacher->id,
        'school_year' => '2026-2027',
        'active' => true,
    ]);

    foreach ([
        [$class, $teacher, ClassRole::Teacher],
        [$class, $coTeacher, ClassRole::Teacher],
        [$class, $student, ClassRole::Student],
        [$otherClass, $otherTeacher, ClassRole::Teacher],
        [$otherClass, $student, ClassRole::Student],
    ] as [$c, $u, $role]) {
        ClassMembership::query()->create([
            'school_class_id' => $c->id,
            'user_id' => $u->id,
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

    $attempt = LessonAttempt::query()->create([
        'user_id' => $student->id,
        'lesson_id' => $lesson->id,
        'lesson_version_id' => $version->id,
        'lesson_assignment_id' => $assignment->id,
        'status' => AttemptStatus::InProgress,
        'current_page_id' => $pageId,
        'shuffle_seed' => 'seed',
        'active_seconds' => 10,
        'revision' => 1,
        'started_at' => now(),
    ]);

    $cerSubmission = BlockSubmission::query()->create([
        'lesson_attempt_id' => $attempt->id,
        'lesson_version_id' => $version->id,
        'block_id' => $cerBlockId,
        'block_type' => 'cer',
        'attempt_number' => 1,
        'response' => ['fields' => ['f1' => 'c', 'f2' => 'e', 'f3' => 'r']],
        'grading_result' => null,
        'requires_manual_review' => true,
        'active_seconds_at_submission' => 10,
        'submitted_at' => now(),
    ]);

    $shortSubmission = BlockSubmission::query()->create([
        'lesson_attempt_id' => $attempt->id,
        'lesson_version_id' => $version->id,
        'block_id' => $shortBlockId,
        'block_type' => 'short_response',
        'attempt_number' => 1,
        'response' => ['value' => 'hello'],
        'grading_result' => null,
        'requires_manual_review' => true,
        'active_seconds_at_submission' => 10,
        'submitted_at' => now(),
    ]);

    $quizSubmission = BlockSubmission::query()->create([
        'lesson_attempt_id' => $attempt->id,
        'lesson_version_id' => $version->id,
        'block_id' => $quizBlockId,
        'block_type' => 'quiz',
        'attempt_number' => 1,
        'response' => ['answers' => ['q1' => 'a']],
        'grading_result' => ['score' => 1, 'max_score' => 1, 'percentage' => 100, 'passed' => true, 'requires_manual_review' => false, 'details' => []],
        'score' => 1,
        'max_score' => 1,
        'percentage' => 100,
        'passed' => true,
        'requires_manual_review' => false,
        'active_seconds_at_submission' => 10,
        'submitted_at' => now(),
    ]);

    return [
        'teacher' => $teacher,
        'coTeacher' => $coTeacher->fresh(),
        'otherTeacher' => $otherTeacher->fresh(),
        'student' => $student,
        'otherStudent' => $otherStudent,
        'class' => $class,
        'otherClass' => $otherClass,
        'lesson' => $lesson,
        'assignment' => $assignment,
        'attempt' => $attempt,
        'cerSubmission' => $cerSubmission,
        'shortSubmission' => $shortSubmission,
        'quizSubmission' => $quizSubmission,
        'cerBlockId' => $cerBlockId,
        'shortBlockId' => $shortBlockId,
        'quizBlockId' => $quizBlockId,
        'cer3Fields' => 3,
        'pageId' => $pageId,
    ];
}

test('quiz and non-manual-review submissions cannot be reviewed', function () {
    $fx = phase5eFixture();
    $service = app(SubmissionReviewService::class);

    expect(fn () => $service->review(
        $fx['attempt'],
        $fx['quizSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_REVIEW_ONLY,
        null,
        null,
        null,
    ))->toThrow(ValidationException::class);

    $fx['cerSubmission']->forceFill(['requires_manual_review' => false]);
    // Cannot update immutable row — create a twin instead.
    $nonManual = BlockSubmission::query()->create([
        'lesson_attempt_id' => $fx['attempt']->id,
        'lesson_version_id' => $fx['attempt']->lesson_version_id,
        'block_id' => $fx['cerBlockId'],
        'block_type' => 'cer',
        'attempt_number' => 2,
        'response' => ['fields' => ['f1' => 'x', 'f2' => 'y', 'f3' => 'z']],
        'grading_result' => null,
        'requires_manual_review' => false,
        'active_seconds_at_submission' => 10,
        'submitted_at' => now(),
    ]);

    expect(fn () => $service->review(
        $fx['attempt'],
        $nonManual,
        $fx['teacher'],
        SubmissionReviewService::MODE_REVIEW_ONLY,
        null,
        null,
        null,
    ))->toThrow(ValidationException::class);
});

test('a submission from another attempt cannot be reviewed', function () {
    $fx = phase5eFixture();
    $otherAttempt = LessonAttempt::query()->create([
        'user_id' => $fx['otherStudent']->id,
        'lesson_id' => $fx['lesson']->id,
        'lesson_version_id' => $fx['attempt']->lesson_version_id,
        'lesson_assignment_id' => $fx['assignment']->id,
        'status' => AttemptStatus::InProgress,
        'current_page_id' => $fx['pageId'],
        'shuffle_seed' => 'other',
        'active_seconds' => 1,
        'revision' => 1,
        'started_at' => now(),
    ]);

    expect(fn () => app(SubmissionReviewService::class)->review(
        $otherAttempt,
        $fx['cerSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_REVIEW_ONLY,
        null,
        null,
        null,
    ))->toThrow(ValidationException::class);
});

test('modes derive points from the pinned field count and ignore client points_possible', function () {
    $fx = phase5eFixture();
    $service = app(SubmissionReviewService::class);

    expect(fn () => $service->review(
        $fx['attempt'],
        $fx['cerSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_REVIEW_ONLY,
        2,
        null,
        null,
    ))->toThrow(ValidationException::class);

    expect(fn () => $service->review(
        $fx['attempt'],
        $fx['cerSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_SCORED,
        null,
        null,
        null,
    ))->toThrow(ValidationException::class);

    expect(fn () => $service->review(
        $fx['attempt'],
        $fx['cerSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_SCORED,
        4,
        null,
        null,
    ))->toThrow(ValidationException::class);

    $payload = $service->review(
        $fx['attempt'],
        $fx['cerSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_SCORED,
        3,
        'Strong explanation of the evidence.',
        'PRIVATE_NOTE_TOKEN_XYZ',
    );

    expect($payload['score'])->toBe(['awarded' => 3, 'possible' => 3, 'percentage' => 100])
        ->and($payload['comment'])->toBe('Strong explanation of the evidence.')
        ->and($payload['private_note'])->toBe('PRIVATE_NOTE_TOKEN_XYZ');

    $short = $service->review(
        $fx['attempt'],
        $fx['shortSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_SCORED,
        1,
        null,
        null,
    );
    expect($short['score']['possible'])->toBe(1);

    $this->actingAs($fx['teacher'])
        ->post(route('staff.attempts.submissions.review', [$fx['attempt'], $fx['cerSubmission']]), [
            'mode' => 'scored',
            'points_awarded' => 2,
            'points_possible' => 100,
        ])
        ->assertSessionHasErrors('points_possible');
});

test('maximum tracks CER field count on the pinned version', function () {
    $fx = phase5eFixture();
    $service = app(SubmissionReviewService::class);

    // Add a fourth field and republish — existing attempt stays on 3.
    $page = $fx['lesson']->pages()->first();
    $cer = $page->blocks()->where('block_id', $fx['cerBlockId'])->first();
    $config = $cer->config;
    $config['fields'][] = ['id' => 'f4', 'label' => 'Extra', 'placeholder' => null, 'min_length' => 1];
    $cer->update(['config' => $config]);
    app(LessonPublisher::class)->publish($fx['lesson']->fresh(), $fx['teacher']);

    $service->review(
        $fx['attempt'],
        $fx['cerSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_SCORED,
        3,
        null,
        null,
    );

    $review = $fx['cerSubmission']->fresh()->latestReview;
    expect($review->points_possible)->toBe(3)
        ->and($review->points_awarded)->toBe(3);

    // A four-field CER on a new publish accepts 4 for new attempts.
    $newVersion = $fx['lesson']->fresh()->currentVersion();
    $newAttempt = LessonAttempt::query()->create([
        'user_id' => $fx['otherStudent']->id,
        'lesson_id' => $fx['lesson']->id,
        'lesson_version_id' => $newVersion->id,
        'lesson_assignment_id' => $fx['assignment']->id,
        'status' => AttemptStatus::InProgress,
        'current_page_id' => $fx['pageId'],
        'shuffle_seed' => 'n',
        'active_seconds' => 1,
        'revision' => 1,
        'started_at' => now(),
    ]);
    $fourFieldSub = BlockSubmission::query()->create([
        'lesson_attempt_id' => $newAttempt->id,
        'lesson_version_id' => $newVersion->id,
        'block_id' => $fx['cerBlockId'],
        'block_type' => 'cer',
        'attempt_number' => 1,
        'response' => ['fields' => ['f1' => 'a', 'f2' => 'b', 'f3' => 'c', 'f4' => 'd']],
        'grading_result' => null,
        'requires_manual_review' => true,
        'active_seconds_at_submission' => 1,
        'submitted_at' => now(),
    ]);

    $ok = $service->review(
        $newAttempt,
        $fourFieldSub,
        $fx['teacher'],
        SubmissionReviewService::MODE_SCORED,
        4,
        null,
        null,
    );
    expect($ok['score']['possible'])->toBe(4);

    expect(fn () => $service->review(
        $newAttempt,
        $fourFieldSub,
        $fx['teacher'],
        SubmissionReviewService::MODE_SCORED,
        5,
        null,
        null,
    ))->toThrow(ValidationException::class);
});

test('zero-field CER can be review_only but not scored and has no percentage', function () {
    $fx = phase5eFixture();
    $zeroBlockId = (string) Str::ulid();

    // Inject a malformed zero-field CER into the pinned manifest.
    $version = $fx['attempt']->lessonVersion;
    $manifest = $version->manifest;
    $manifest['pages'][0]['blocks'][] = [
        'block_id' => $zeroBlockId,
        'type' => 'cer',
        'config' => ['scenario_html' => '<p>x</p>', 'fields' => []],
        'grading' => null,
        'speech' => [],
    ];
    // Bypass immutability via query builder for the fixture only.
    \Illuminate\Support\Facades\DB::table('lesson_versions')
        ->where('id', $version->id)
        ->update(['manifest' => json_encode($manifest)]);

    $sub = BlockSubmission::query()->create([
        'lesson_attempt_id' => $fx['attempt']->id,
        'lesson_version_id' => $version->id,
        'block_id' => $zeroBlockId,
        'block_type' => 'cer',
        'attempt_number' => 1,
        'response' => ['fields' => []],
        'grading_result' => null,
        'requires_manual_review' => true,
        'active_seconds_at_submission' => 1,
        'submitted_at' => now(),
    ]);

    $service = app(SubmissionReviewService::class);

    expect(fn () => $service->review(
        $fx['attempt']->fresh(),
        $sub,
        $fx['teacher'],
        SubmissionReviewService::MODE_SCORED,
        0,
        null,
        null,
    ))->toThrow(ValidationException::class);

    $payload = $service->review(
        $fx['attempt']->fresh(),
        $sub,
        $fx['teacher'],
        SubmissionReviewService::MODE_REVIEW_ONLY,
        null,
        null,
        null,
    );

    expect($payload['score'])->toBeNull()
        ->and(ManualReviewScore::fromAwardedAndPossible(0, 0)->toArray())->toBeNull();
});

test('latest review is deterministic by created_at then id; newer submission stays awaiting', function () {
    $fx = phase5eFixture();
    $service = app(SubmissionReviewService::class);
    $ts = Carbon::parse('2026-07-30 12:00:00');

    Carbon::setTestNow($ts);
    $service->review(
        $fx['attempt'],
        $fx['cerSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_REVIEW_ONLY,
        null,
        'first',
        null,
    );

    Carbon::setTestNow($ts);
    $service->review(
        $fx['attempt'],
        $fx['cerSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_SCORED,
        2,
        'second',
        null,
    );
    Carbon::setTestNow();

    $latest = $fx['cerSubmission']->fresh()->latestReview;
    expect($latest->comment)->toBe('second')
        ->and($latest->points_awarded)->toBe(2);

    $newer = BlockSubmission::query()->create([
        'lesson_attempt_id' => $fx['attempt']->id,
        'lesson_version_id' => $fx['attempt']->lesson_version_id,
        'block_id' => $fx['cerBlockId'],
        'block_type' => 'cer',
        'attempt_number' => 2,
        'response' => ['fields' => ['f1' => 'new', 'f2' => 'n', 'f3' => 'n']],
        'grading_result' => null,
        'requires_manual_review' => true,
        'active_seconds_at_submission' => 20,
        'submitted_at' => now(),
    ]);

    $safe = $service->studentSafeForBlock($fx['attempt']->fresh(), $fx['cerBlockId']);
    expect($safe)->toBe(StudentManualReview::map(false, null, null))
        ->and($service->submissionNeedsReview($newer))->toBeTrue()
        ->and($service->submissionNeedsReview($fx['cerSubmission']->fresh()))->toBeFalse();
});

test('needs-review queue counts unreviewed submissions; reviewing one leaves the other', function () {
    $fx = phase5eFixture();

    BlockSubmission::query()->create([
        'lesson_attempt_id' => $fx['attempt']->id,
        'lesson_version_id' => $fx['attempt']->lesson_version_id,
        'block_id' => $fx['cerBlockId'],
        'block_type' => 'cer',
        'attempt_number' => 2,
        'response' => ['fields' => ['f1' => 'a', 'f2' => 'b', 'f3' => 'c']],
        'grading_result' => null,
        'requires_manual_review' => true,
        'active_seconds_at_submission' => 11,
        'submitted_at' => now(),
    ]);

    $progress = app(AssignmentProgressService::class)->forAssignment($fx['assignment']);
    // cer#1 + cer#2 + short#1 = 3
    expect($progress['needs_review_count'])->toBe(3);

    app(SubmissionReviewService::class)->review(
        $fx['attempt'],
        $fx['cerSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_REVIEW_ONLY,
        null,
        null,
        null,
    );

    $progress = app(AssignmentProgressService::class)->forAssignment($fx['assignment']->fresh());
    expect($progress['needs_review_count'])->toBe(2);

    $html = $this->actingAs($fx['teacher'])
        ->get(route('staff.assignments.show', $fx['assignment']))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('PRIVATE')
        ->and($html)->not->toContain('private_note');
});

test('reviews are immutable and a second review writes a second row', function () {
    $fx = phase5eFixture();
    $service = app(SubmissionReviewService::class);

    $service->review(
        $fx['attempt'],
        $fx['shortSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_REVIEW_ONLY,
        null,
        null,
        null,
    );

    $review = $fx['shortSubmission']->fresh()->latestReview;
    expect(fn () => $review->update(['comment' => 'nope']))
        ->toThrow(ImmutableBlockSubmissionReviewException::class)
        ->and(fn () => $review->delete())
        ->toThrow(ImmutableBlockSubmissionReviewException::class);

    $service->review(
        $fx['attempt'],
        $fx['shortSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_SCORED,
        1,
        'ok',
        null,
    );

    expect(BlockSubmissionReview::query()->where('block_submission_id', $fx['shortSubmission']->id)->count())->toBe(2);
});

test('student restore payload exposes safe review keys only and never private_note', function () {
    $fx = phase5eFixture();
    $token = 'PRIVATE_NOTE_NEVER_STUDENT_'.Str::random(8);

    app(SubmissionReviewService::class)->review(
        $fx['attempt'],
        $fx['cerSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_SCORED,
        3,
        'Strong explanation of the evidence.',
        $token,
    );

    $payload = app(AttemptService::class)->restorePayload($fx['attempt']->fresh(), false);
    $review = $payload['reviews'][$fx['cerBlockId']];

    expect(array_keys($review))->toEqualCanonicalizing(StudentManualReview::allowedKeys())
        ->and($review['reviewed'])->toBeTrue()
        ->and($review['score'])->toBe(['awarded' => 3, 'possible' => 3, 'percentage' => 100])
        ->and($review['comment'])->toBe('Strong explanation of the evidence.')
        ->and(json_encode($payload))->not->toContain($token)
        ->and(json_encode($payload))->not->toContain('private_note')
        ->and(json_encode($payload))->not->toContain('reviewed_by');

    $html = $this->actingAs($fx['student'])
        ->get(route('player.assignments.show', $fx['assignment']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Strong explanation of the evidence.')
        ->and($html)->not->toContain($token);

    $this->actingAs($fx['otherStudent'])
        ->get(route('player.assignments.show', $fx['assignment']))
        ->assertForbidden();
});

test('co-teacher can read private notes; other-class teacher cannot review', function () {
    $fx = phase5eFixture();
    $token = 'CO_TEACHER_PRIVATE_'.Str::random(6);

    app(SubmissionReviewService::class)->review(
        $fx['attempt'],
        $fx['shortSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_REVIEW_ONLY,
        null,
        'public comment',
        $token,
    );

    $detail = app(AttemptDetailPresenter::class)->present($fx['attempt']->fresh());
    $shortBlock = collect($detail['blocks'])->firstWhere('block_id', $fx['shortBlockId']);
    expect($shortBlock['submitted_history'][0]['latest_review']['private_note'])->toBe($token);

    $this->actingAs($fx['coTeacher'])
        ->get(route('staff.attempts.show', $fx['attempt']))
        ->assertOk()
        ->assertSee($token, false);

    $this->actingAs($fx['otherTeacher'])
        ->post(route('staff.attempts.submissions.review', [$fx['attempt'], $fx['cerSubmission']]), [
            'mode' => 'review_only',
        ])
        ->assertForbidden();
});

test('reviewing does not change attempt status or page completion ability', function () {
    $fx = phase5eFixture();
    $before = $fx['attempt']->fresh();

    app(SubmissionReviewService::class)->review(
        $fx['attempt'],
        $fx['cerSubmission'],
        $fx['teacher'],
        SubmissionReviewService::MODE_REVIEW_ONLY,
        null,
        null,
        null,
    );

    $after = $fx['attempt']->fresh();
    expect($after->status)->toBe($before->status)
        ->and($after->current_page_id)->toBe($before->current_page_id)
        ->and($after->revision)->toBe($before->revision)
        ->and($after->completed_at)->toBeNull()
        ->and($fx['student']->can('work', $after))->toBeTrue();
});

test('shared percentage formatter rounds consistently', function () {
    expect(ManualReviewScore::fromAwardedAndPossible(2, 3)->toArray())
        ->toBe(['awarded' => 2, 'possible' => 3, 'percentage' => 67]);
});
