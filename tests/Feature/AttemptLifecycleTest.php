<?php

use App\Enums\AttemptStatus;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\LessonBlock;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\GradingToken;
use App\Services\LessonPublisher;
use App\Services\StudentManifest;

beforeEach(function () {
    $this->withoutVite();
});

test('first visit creates an in_progress attempt and the second reuses it', function () {
    $user = asStudent();
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $this->get("/lessons/{$lesson->code}")->assertOk();

    expect(LessonAttempt::query()->where('user_id', $user->id)->count())->toBe(1);

    $attemptId = LessonAttempt::query()->where('user_id', $user->id)->value('id');

    $this->get("/lessons/{$lesson->code}")->assertOk();

    expect(LessonAttempt::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(LessonAttempt::query()->where('user_id', $user->id)->value('id'))->toBe($attemptId);
});

test('revisiting a completed lesson is read-only and does not create a second attempt', function () {
    $user = asStudent();
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $resolved = app(AttemptService::class)->resolveForPlayer($user, $lesson->fresh());
    $attempt = $resolved['attempt'];
    $attempt->forceFill([
        'status' => AttemptStatus::Completed,
        'completed_at' => now(),
    ])->save();

    $this->get("/lessons/{$lesson->code}")
        ->assertOk()
        ->assertViewHas('attempt', fn (array $payload) => $payload['read_only'] === true
            && $payload['id'] === $attempt->id);

    expect(LessonAttempt::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(LessonAttempt::query()->where('status', AttemptStatus::InProgress)->count())->toBe(0);
});

test('two simultaneous first visits end with exactly one in_progress attempt', function () {
    $user = asStudent();
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());
    $lesson = $lesson->fresh();
    $service = app(AttemptService::class);

    $first = $service->resolveForPlayer($user, $lesson);
    $second = $service->resolveForPlayer($user, $lesson);

    expect(LessonAttempt::query()->where('user_id', $user->id)->where('status', AttemptStatus::InProgress)->count())
        ->toBe(1)
        ->and($first['attempt']->id)->toBe($second['attempt']->id);
});

test('the player and manifest API stay on the pinned version after a republish', function () {
    $user = asStudent();
    $lesson = createLessonWithAllBlockTypes();
    $publisher = app(LessonPublisher::class);
    $publisher->publish($lesson, User::factory()->create());

    $this->get("/lessons/{$lesson->code}")->assertOk();

    $attempt = LessonAttempt::query()->where('user_id', $user->id)->firstOrFail();
    $v1Id = $attempt->lesson_version_id;
    $v1Token = app(StudentManifest::class)->forVersion($attempt->lessonVersion)['grading_token'];

    $block = LessonBlock::query()->whereHas('page', fn ($q) => $q->where('lesson_id', $lesson->id))->first();
    $block->update(['config' => array_merge($block->config, ['html' => '<p>Version two content</p>'])]);
    $publisher->publish($lesson->fresh(), User::factory()->create());

    $player = $this->get("/lessons/{$lesson->code}")->assertOk();
    expect($player->viewData('manifest')['version'])->toBe(1);

    $api = $this->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();
    expect($api['version'])->toBe(1)
        ->and($api['attempt']['id'])->toBe($attempt->id);

    // Token for v2 must not grade against the pinned v1 attempt.
    $v2 = $lesson->fresh()->currentVersion();
    $v2Token = app(GradingToken::class)->issue($v2);
    $quizId = blockOfType($v2->manifest['pages'], 'quiz')['block_id'];

    $this->postJson("/player/lessons/{$lesson->code}/blocks/{$quizId}/grade", [
        'version_token' => $v2Token,
        'response' => ['q' => 'a'],
    ])->assertStatus(422)->assertJsonPath('message', 'The grading session is invalid.');

    expect($v1Token)->not->toBe($v2Token)
        ->and($v1Id)->not->toBe($v2->id);
});

test('the manifest API returns a completed attempt pinned and read-only after a republish', function () {
    $user = asStudent();
    $lesson = createLessonWithAllBlockTypes();
    $publisher = app(LessonPublisher::class);
    $publisher->publish($lesson, User::factory()->create());

    $attempt = app(AttemptService::class)->resolveForPlayer($user, $lesson->fresh())['attempt'];
    $attempt->forceFill([
        'status' => AttemptStatus::Completed,
        'completed_at' => now(),
    ])->save();

    $block = LessonBlock::query()->whereHas('page', fn ($q) => $q->where('lesson_id', $lesson->id))->first();
    $block->update(['config' => array_merge($block->config, ['html' => '<p>Version two</p>'])]);
    $publisher->publish($lesson->fresh(), User::factory()->create());

    $api = $this->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();

    expect($api['version'])->toBe(1)
        ->and($api['attempt']['id'])->toBe($attempt->id)
        ->and($api['attempt']['read_only'])->toBeTrue()
        ->and($lesson->fresh()->current_version)->toBe(2);
});

test('a GET to the manifest API creates no attempt when the student has none', function () {
    $user = asStudent();
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    expect(LessonAttempt::query()->where('user_id', $user->id)->count())->toBe(0);

    $api = $this->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();

    expect($api)->not->toHaveKey('attempt')
        ->and(LessonAttempt::query()->where('user_id', $user->id)->count())->toBe(0);
});
