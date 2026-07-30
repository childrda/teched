<?php

use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\LessonPublisher;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL alone attaches ON UPDATE CURRENT_TIMESTAMP to the first TIMESTAMP
 * column that has no explicit default. This test is only meaningful there —
 * SQLite has no such behavior to reproduce.
 */
test('updating an attempt does not rewrite started_at', function () {
    if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('started_at ON UPDATE CURRENT_TIMESTAMP is a MySQL quirk.');
    }

    $user = asStudent();
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $attempt = app(AttemptService::class)
        ->resolveForPlayer($user, $lesson->fresh())['attempt'];

    $startedAt = $attempt->fresh()->started_at->toDateTimeString();

    sleep(1);

    LessonAttempt::query()
        ->whereKey($attempt->id)
        ->increment('active_seconds', 5, ['revision' => $attempt->revision + 1]);

    expect($attempt->fresh()->started_at->toDateTimeString())->toBe($startedAt);
});
