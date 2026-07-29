<?php

use App\Exceptions\ImmutableLessonVersionException;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\LessonVersion;
use App\Models\User;
use App\Services\LessonPublisher;

test('editing authoring rows after publish does not alter the existing version manifest', function () {
    $lesson = createLessonWithAllBlockTypes();
    $user = User::factory()->create();

    $version = app(LessonPublisher::class)->publish($lesson, $user);
    $manifestBefore = $version->manifest;

    $page = $lesson->pages()->first();
    $page->update(['title' => 'Renamed After Publish']);
    $page->blocks()->first()->update(['config' => ['html' => '<p>Changed after publish</p>']]);

    expect($version->fresh()->manifest)->toEqual($manifestBefore);

    // Editing flags unpublished changes without reverting status to draft.
    $fresh = $lesson->fresh();
    expect($fresh->has_unpublished_changes)->toBeTrue()
        ->and($fresh->status)->toBe(App\Enums\LessonStatus::Published);
});

test('two sequential publishes create versions 1 and 2 with prior versions intact', function () {
    $lesson = createLessonWithAllBlockTypes();
    $user = User::factory()->create();
    $publisher = app(LessonPublisher::class);

    $v1 = $publisher->publish($lesson, $user);
    expect($v1->version)->toBe(1)
        ->and($lesson->fresh()->current_version)->toBe(1);

    $v1Manifest = $v1->manifest;

    $lesson->pages()->first()->update(['title' => 'Changed Between Publishes']);

    $v2 = $publisher->publish($lesson->fresh(), $user);
    expect($v2->version)->toBe(2)
        ->and($lesson->fresh()->current_version)->toBe(2)
        ->and($lesson->fresh()->has_unpublished_changes)->toBeFalse();

    // Prior version is intact and unchanged.
    expect($v1->fresh()->manifest)->toEqual($v1Manifest)
        ->and($lesson->versions()->count())->toBe(2);

    // v2 reflects the edit; v1 does not.
    expect($v2->manifest['pages'][0]['title'])->toBe('Changed Between Publishes')
        ->and($v1->fresh()->manifest['pages'][0]['title'])->not->toBe('Changed Between Publishes');
});

test('updating an existing LessonVersion throws', function () {
    $version = LessonVersion::factory()->create();

    expect(fn () => $version->update(['version' => 99]))
        ->toThrow(ImmutableLessonVersionException::class);
});

test('saving changes to an existing LessonVersion throws', function () {
    $version = LessonVersion::factory()->create();
    $version->schema_version = 42;

    expect(fn () => $version->save())
        ->toThrow(ImmutableLessonVersionException::class);
});

test('deleting an existing LessonVersion throws', function () {
    $version = LessonVersion::factory()->create();

    expect(fn () => $version->delete())
        ->toThrow(ImmutableLessonVersionException::class);
});

test('page_id and block_id are unchanged after reordering', function () {
    $lesson = Lesson::factory()->create();
    $pageA = LessonPage::factory()->create(['lesson_id' => $lesson->id, 'position' => 1]);
    $pageB = LessonPage::factory()->create(['lesson_id' => $lesson->id, 'position' => 2]);

    $blockA = LessonBlock::factory()->create(['lesson_page_id' => $pageA->id, 'position' => 1]);
    $blockB = LessonBlock::factory()->create(['lesson_page_id' => $pageA->id, 'position' => 2]);

    $pageIds = [$pageA->page_id, $pageB->page_id];
    $blockIds = [$blockA->block_id, $blockB->block_id];

    // Swap page positions (two-step to satisfy the unique constraint).
    $pageA->update(['position' => 999]);
    $pageB->update(['position' => 1]);
    $pageA->update(['position' => 2]);

    // Swap block positions.
    $blockA->update(['position' => 999]);
    $blockB->update(['position' => 1]);
    $blockA->update(['position' => 2]);

    expect([$pageA->fresh()->page_id, $pageB->fresh()->page_id])->toBe($pageIds)
        ->and([$blockA->fresh()->block_id, $blockB->fresh()->block_id])->toBe($blockIds);

    // Reordered manifest keeps the same stable IDs, just in new order.
    app(LessonPublisher::class)->publish($lesson->fresh(), User::factory()->create());
    $manifest = $lesson->fresh()->currentVersion()->manifest;

    expect(array_column($manifest['pages'], 'page_id'))
        ->toBe([$pageB->page_id, $pageA->page_id]);
});
