<?php

use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\LessonPublisher;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => asStudent());

test('activity deltas accumulate and reject invalid values', function () {
    $user = auth()->user();
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());
    $attempt = app(AttemptService::class)->resolveForPlayer($user, $lesson->fresh())['attempt'];

    $this->postJson("/player/attempts/{$attempt->id}/activity", [
        'active_seconds_delta' => 40,
    ])->assertOk()->assertJsonPath('active_seconds', 40);

    $this->postJson("/player/attempts/{$attempt->id}/activity", [
        'active_seconds_delta' => 15,
    ])->assertOk()->assertJsonPath('active_seconds', 55);

    $this->postJson("/player/attempts/{$attempt->id}/activity", [
        'active_seconds_delta' => -1,
    ])->assertStatus(422);

    $this->postJson("/player/attempts/{$attempt->id}/activity", [
        'active_seconds_delta' => 301,
    ])->assertStatus(422);
});

test('two concurrent activity deltas both land', function () {
    $user = auth()->user();
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());
    $attempt = app(AttemptService::class)->resolveForPlayer($user, $lesson->fresh())['attempt'];

    // Atomic increment: two overlapping updates must sum, not overwrite.
    DB::transaction(function () use ($attempt) {
        LessonAttempt::query()->whereKey($attempt->id)->increment('active_seconds', 10, [
            'last_activity_at' => now(),
        ]);
        LessonAttempt::query()->whereKey($attempt->id)->increment('active_seconds', 7, [
            'last_activity_at' => now(),
        ]);
    });

    expect($attempt->fresh()->active_seconds)->toBe(17);
});
