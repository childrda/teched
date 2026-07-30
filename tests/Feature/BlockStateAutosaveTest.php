<?php

use App\Enums\AttemptStatus;
use App\Http\Controllers\Player\SaveBlockStateController;
use App\Models\BlockState;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\LessonPublisher;

beforeEach(function () {
    $this->withoutVite();
});

function openAttempt(): array
{
    $user = asStudent();
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());
    $resolved = app(AttemptService::class)->resolveForPlayer($user, $lesson->fresh());

    return [$user, $lesson->fresh(), $resolved['attempt']];
}

test('a state write returns an incremented revision', function () {
    [, $lesson, $attempt] = openAttempt();
    $blockId = blockOfType($attempt->lessonVersion->manifest['pages'], 'short_response')['block_id'];

    $this->putJson("/player/attempts/{$attempt->id}/blocks/{$blockId}/state", [
        'state' => ['value' => '  hello  '],
        'revision' => 0,
    ])->assertOk()->assertJson(['revision' => 1]);

    expect(BlockState::query()->where('lesson_attempt_id', $attempt->id)->value('state'))
        ->toBe(['value' => '  hello  ']);
});

test('a stale revision returns 409 with the current state', function () {
    [, , $attempt] = openAttempt();
    $blockId = blockOfType($attempt->lessonVersion->manifest['pages'], 'short_response')['block_id'];

    $this->putJson("/player/attempts/{$attempt->id}/blocks/{$blockId}/state", [
        'state' => ['value' => 'one'],
        'revision' => 0,
    ])->assertOk();

    $this->putJson("/player/attempts/{$attempt->id}/blocks/{$blockId}/state", [
        'state' => ['value' => 'stale'],
        'revision' => 0,
    ])->assertStatus(409)
        ->assertJsonPath('revision', 1)
        ->assertJsonPath('state.value', 'one');
});

test('another users attempt is a 404', function () {
    [, , $attempt] = openAttempt();
    $blockId = blockOfType($attempt->lessonVersion->manifest['pages'], 'short_response')['block_id'];

    asStudent();

    $this->putJson("/player/attempts/{$attempt->id}/blocks/{$blockId}/state", [
        'state' => ['value' => 'nope'],
        'revision' => 0,
    ])->assertNotFound();
});

test('a completed attempt rejects state writes', function () {
    [, , $attempt] = openAttempt();
    $blockId = blockOfType($attempt->lessonVersion->manifest['pages'], 'short_response')['block_id'];
    $attempt->forceFill(['status' => AttemptStatus::Completed, 'completed_at' => now()])->save();

    $this->putJson("/player/attempts/{$attempt->id}/blocks/{$blockId}/state", [
        'state' => ['value' => 'nope'],
        'revision' => 0,
    ])->assertStatus(409);
});

test('stateful types reject unknown ids and over-cap text', function () {
    [, , $attempt] = openAttempt();
    $pages = $attempt->lessonVersion->manifest['pages'];

    $cerId = blockOfType($pages, 'cer')['block_id'];
    $this->putJson("/player/attempts/{$attempt->id}/blocks/{$cerId}/state", [
        'state' => ['values' => ['not-a-field' => 'x']],
        'revision' => 0,
    ])->assertStatus(422);

    $matchId = blockOfType($pages, 'matching')['block_id'];
    $this->putJson("/player/attempts/{$attempt->id}/blocks/{$matchId}/state", [
        'state' => ['placements' => ['unknown-slot' => null]],
        'revision' => 0,
    ])->assertStatus(422);

    $shortId = blockOfType($pages, 'short_response')['block_id'];
    $this->putJson("/player/attempts/{$attempt->id}/blocks/{$shortId}/state", [
        'state' => ['value' => str_repeat('a', 20001)],
        'revision' => 0,
    ])->assertStatus(422);

    $richId = blockOfType($pages, 'rich_text')['block_id'];
    $this->putJson("/player/attempts/{$attempt->id}/blocks/{$richId}/state", [
        'state' => ['value' => 'x'],
        'revision' => 0,
    ])->assertStatus(422);
});

test('short response whitespace round-trips while satisfaction uses trimmed length', function () {
    [, , $attempt] = openAttempt();
    $block = blockOfType($attempt->lessonVersion->manifest['pages'], 'short_response');
    $type = app(App\Blocks\BlockTypeRegistry::class)->get('short_response');

    $this->putJson("/player/attempts/{$attempt->id}/blocks/{$block['block_id']}/state", [
        'state' => ['value' => "  \nkeep me  "],
        'revision' => 0,
    ])->assertOk();

    $stored = BlockState::query()->where('block_id', $block['block_id'])->value('state');
    expect($stored['value'])->toBe("  \nkeep me  ")
        ->and($type->isStateSatisfied($stored, $block['config']))->toBeTrue()
        ->and($type->isStateSatisfied(['value' => '   '], $block['config']))->toBeFalse();
});

test('a concurrent first save returns 409 with the winner rather than throwing', function () {
    // A true HTTP race cannot be reproduced in-process: both requests would
    // share one PHP process and one transaction timeline. Calling the
    // create-or-conflict method twice against the same unique key exercises
    // the same recovery path the unique-index loser takes.
    [, , $attempt] = openAttempt();
    $blockId = blockOfType($attempt->lessonVersion->manifest['pages'], 'short_response')['block_id'];
    $controller = app(SaveBlockStateController::class);

    $first = $controller->createInitialState(
        $attempt,
        $blockId,
        'short_response',
        ['value' => 'winner']
    );

    expect($first->getStatusCode())->toBe(200)
        ->and($first->getData(true))->toBe(['revision' => 1]);

    $second = $controller->createInitialState(
        $attempt,
        $blockId,
        'short_response',
        ['value' => 'loser']
    );

    expect($second->getStatusCode())->toBe(409)
        ->and($second->getData(true)['revision'])->toBe(1)
        ->and($second->getData(true)['state'])->toBe(['value' => 'winner']);

    expect(BlockState::query()->where('lesson_attempt_id', $attempt->id)->where('block_id', $blockId)->count())
        ->toBe(1);
});
