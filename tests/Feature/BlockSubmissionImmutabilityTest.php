<?php

use App\Exceptions\ImmutableBlockSubmissionException;
use App\Models\BlockSubmission;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\LessonPublisher;

test('block submissions reject update and delete', function () {
    $user = asStudent();
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());
    $attempt = app(AttemptService::class)->resolveForPlayer($user, $lesson->fresh())['attempt'];

    $submission = BlockSubmission::query()->create([
        'lesson_attempt_id' => $attempt->id,
        'lesson_version_id' => $attempt->lesson_version_id,
        'block_id' => '01JTESTBLOCKID000000000001',
        'block_type' => 'short_response',
        'attempt_number' => 1,
        'response' => ['value' => 'x'],
        'grading_result' => null,
        'requires_manual_review' => true,
        'submitted_at' => now(),
    ]);

    expect(fn () => $submission->update(['response' => ['value' => 'y']]))
        ->toThrow(ImmutableBlockSubmissionException::class);

    expect(fn () => $submission->delete())
        ->toThrow(ImmutableBlockSubmissionException::class);
});
