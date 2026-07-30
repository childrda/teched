<?php

use App\Enums\AttemptStatus;
use App\Enums\PageCompletionType;
use App\Models\BlockState;
use App\Models\BlockSubmission;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\LessonPublisher;

beforeEach(function () {
    $this->withoutVite();
    asStudent();
});

function publishSinglePageLesson(PageCompletionType $completion, string $blockType, array $config): Lesson
{
    $lesson = Lesson::factory()->create();
    $page = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 1,
        'completion_type' => $completion,
        'title' => 'Only page',
    ]);

    $type = app(App\Blocks\BlockTypeRegistry::class)->get($blockType);

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'type' => $blockType,
        'position' => 1,
        'config' => $config !== [] ? $config : $type->defaultConfig(),
        'grading' => $type->isAutoGradable() ? fullGradingShape() : null,
    ]);

    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    return $lesson->fresh();
}

test('continue snapshots changed response blocks, advances, and completes on the last page', function () {
    $lesson = publishSinglePageLesson(
        PageCompletionType::SubmitRequired,
        'short_response',
        app(App\Blocks\BlockTypeRegistry::class)->get('short_response')->defaultConfig()
    );

    $attempt = app(AttemptService::class)->resolveForPlayer(auth()->user(), $lesson)['attempt'];
    $pageId = $attempt->lessonVersion->manifest['pages'][0]['page_id'];
    $blockId = $attempt->lessonVersion->manifest['pages'][0]['blocks'][0]['block_id'];

    $this->putJson("/player/attempts/{$attempt->id}/blocks/{$blockId}/state", [
        'state' => ['value' => 'My answer'],
        'revision' => 0,
    ])->assertOk();

    $this->postJson("/player/attempts/{$attempt->id}/pages/{$pageId}/continue", [
        'revision' => 0,
    ])->assertOk()
        ->assertJsonPath('status', AttemptStatus::Completed->value);

    expect(BlockSubmission::query()->where('block_id', $blockId)->count())->toBe(1)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Completed);

    // Unchanged content creates nothing on a second snapshot path — already completed.
    $this->postJson("/player/attempts/{$attempt->id}/pages/{$pageId}/continue", [
        'revision' => 1,
    ])->assertStatus(409);
});

test('continue returns 422 when the page rule is unsatisfied', function () {
    $lesson = publishSinglePageLesson(
        PageCompletionType::SubmitRequired,
        'short_response',
        app(App\Blocks\BlockTypeRegistry::class)->get('short_response')->defaultConfig()
    );

    $attempt = app(AttemptService::class)->resolveForPlayer(auth()->user(), $lesson)['attempt'];
    $pageId = $attempt->lessonVersion->manifest['pages'][0]['page_id'];

    $this->postJson("/player/attempts/{$attempt->id}/pages/{$pageId}/continue", [
        'revision' => 0,
    ])->assertStatus(422);
});

test('continue assigns sequential attempt numbers for changed content', function () {
    $lesson = publishSinglePageLesson(
        PageCompletionType::SubmitRequired,
        'short_response',
        app(App\Blocks\BlockTypeRegistry::class)->get('short_response')->defaultConfig()
    );

    // Two-page lesson so the first continue does not complete.
    $page2 = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 2,
        'completion_type' => PageCompletionType::View,
        'title' => 'Done',
    ]);
    LessonBlock::factory()->create([
        'lesson_page_id' => $page2->id,
        'type' => 'rich_text',
        'position' => 1,
        'config' => app(App\Blocks\BlockTypeRegistry::class)->get('rich_text')->defaultConfig(),
    ]);
    app(LessonPublisher::class)->publish($lesson->fresh(), User::factory()->create());

    $attempt = app(AttemptService::class)->resolveForPlayer(auth()->user(), $lesson->fresh())['attempt'];
    $pageId = $attempt->lessonVersion->manifest['pages'][0]['page_id'];
    $blockId = blockOfType($attempt->lessonVersion->manifest['pages'], 'short_response')['block_id'];

    $this->putJson("/player/attempts/{$attempt->id}/blocks/{$blockId}/state", [
        'state' => ['value' => 'first'],
        'revision' => 0,
    ])->assertOk();

    $this->postJson("/player/attempts/{$attempt->id}/pages/{$pageId}/continue", [
        'revision' => 0,
    ])->assertOk();

    // Walk back by rewriting current_page and status for a second snapshot.
    $attempt->refresh();
    $attempt->forceFill([
        'current_page_id' => $pageId,
        'status' => AttemptStatus::InProgress,
        'completed_at' => null,
    ])->save();

    $this->putJson("/player/attempts/{$attempt->id}/blocks/{$blockId}/state", [
        'state' => ['value' => 'second'],
        'revision' => 1,
    ])->assertOk();

    $this->postJson("/player/attempts/{$attempt->id}/pages/{$pageId}/continue", [
        'revision' => $attempt->fresh()->revision,
    ])->assertOk();

    $numbers = BlockSubmission::query()
        ->where('block_id', $blockId)
        ->orderBy('attempt_number')
        ->pluck('attempt_number')
        ->all();

    expect($numbers)->toBe([1, 2]);
});

test('continue skips a new snapshot when state differs only by key order', function () {
    // Two pages so Continue can snapshot without completing the attempt first.
    $lesson = publishSinglePageLesson(
        PageCompletionType::View,
        'cer',
        app(App\Blocks\BlockTypeRegistry::class)->get('cer')->defaultConfig()
    );
    $page2 = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 2,
        'completion_type' => PageCompletionType::View,
        'title' => 'Done',
    ]);
    LessonBlock::factory()->create([
        'lesson_page_id' => $page2->id,
        'type' => 'rich_text',
        'position' => 1,
        'config' => app(App\Blocks\BlockTypeRegistry::class)->get('rich_text')->defaultConfig(),
    ]);
    app(LessonPublisher::class)->publish($lesson->fresh(), User::factory()->create());

    $attempt = app(AttemptService::class)->resolveForPlayer(auth()->user(), $lesson->fresh())['attempt'];
    $pageId = $attempt->lessonVersion->manifest['pages'][0]['page_id'];
    $blockId = blockOfType($attempt->lessonVersion->manifest['pages'], 'cer')['block_id'];

    $state = ['reasoning' => 'r', 'claim' => 'c', 'evidence' => 'e'];
    $reordered = ['claim' => 'c', 'evidence' => 'e', 'reasoning' => 'r'];

    BlockState::query()->create([
        'lesson_attempt_id' => $attempt->id,
        'block_id' => $blockId,
        'block_type' => 'cer',
        'state' => $state,
        'revision' => 1,
    ]);

    BlockSubmission::query()->create([
        'lesson_attempt_id' => $attempt->id,
        'lesson_version_id' => $attempt->lesson_version_id,
        'block_id' => $blockId,
        'block_type' => 'cer',
        'attempt_number' => 1,
        'response' => $reordered,
        'grading_result' => null,
        'score' => null,
        'max_score' => null,
        'percentage' => null,
        'passed' => null,
        'requires_manual_review' => true,
        'active_seconds_at_submission' => 0,
        'submitted_at' => now(),
    ]);

    $this->postJson("/player/attempts/{$attempt->id}/pages/{$pageId}/continue", [
        'revision' => 0,
    ])->assertOk();

    expect(BlockSubmission::query()->where('block_id', $blockId)->count())->toBe(1);
});

test('continue writes a new snapshot when empty string becomes null', function () {
    $lesson = publishSinglePageLesson(
        PageCompletionType::View,
        'short_response',
        app(App\Blocks\BlockTypeRegistry::class)->get('short_response')->defaultConfig()
    );
    $page2 = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 2,
        'completion_type' => PageCompletionType::View,
        'title' => 'Done',
    ]);
    LessonBlock::factory()->create([
        'lesson_page_id' => $page2->id,
        'type' => 'rich_text',
        'position' => 1,
        'config' => app(App\Blocks\BlockTypeRegistry::class)->get('rich_text')->defaultConfig(),
    ]);
    app(LessonPublisher::class)->publish($lesson->fresh(), User::factory()->create());

    $attempt = app(AttemptService::class)->resolveForPlayer(auth()->user(), $lesson->fresh())['attempt'];
    $pageId = $attempt->lessonVersion->manifest['pages'][0]['page_id'];
    $blockId = blockOfType($attempt->lessonVersion->manifest['pages'], 'short_response')['block_id'];

    // Bypass validateState: force "" vs null, which loose == would treat as equal.
    BlockState::query()->create([
        'lesson_attempt_id' => $attempt->id,
        'block_id' => $blockId,
        'block_type' => 'short_response',
        'state' => ['value' => null],
        'revision' => 1,
    ]);

    BlockSubmission::query()->create([
        'lesson_attempt_id' => $attempt->id,
        'lesson_version_id' => $attempt->lesson_version_id,
        'block_id' => $blockId,
        'block_type' => 'short_response',
        'attempt_number' => 1,
        'response' => ['value' => ''],
        'grading_result' => null,
        'score' => null,
        'max_score' => null,
        'percentage' => null,
        'passed' => null,
        'requires_manual_review' => true,
        'active_seconds_at_submission' => 0,
        'submitted_at' => now(),
    ]);

    $this->postJson("/player/attempts/{$attempt->id}/pages/{$pageId}/continue", [
        'revision' => 0,
    ])->assertOk();

    $rows = BlockSubmission::query()->where('block_id', $blockId)->orderBy('attempt_number')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[1]->response)->toBe(['value' => null])
        ->and($rows[1]->lesson_version_id)->toBe($attempt->lesson_version_id);
});
