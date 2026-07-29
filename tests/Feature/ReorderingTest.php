<?php

use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use InvalidArgumentException;

function makeLessonWithPages(int $count): array
{
    $lesson = Lesson::factory()->create();

    $pages = collect(range(1, $count))->map(
        fn (int $position) => LessonPage::factory()->create([
            'lesson_id' => $lesson->id,
            'position' => $position,
            'title' => "Page {$position}",
        ])
    );

    return [$lesson, $pages];
}

test('reordering four pages succeeds and writes contiguous positions', function () {
    [$lesson, $pages] = makeLessonWithPages(4);

    // Reverse the order.
    $newOrder = $pages->reverse()->pluck('page_id')->values()->all();

    LessonPage::reorderWithin($lesson, $newOrder);

    $result = $lesson->pages()->get();

    expect($result->pluck('page_id')->all())->toBe($newOrder)
        ->and($result->pluck('position')->all())->toBe([1, 2, 3, 4]);
});

test('reordering blocks within a page succeeds and writes contiguous positions', function () {
    $page = LessonPage::factory()->create(['position' => 1]);

    $blocks = collect(range(1, 3))->map(
        fn (int $position) => LessonBlock::factory()->create([
            'lesson_page_id' => $page->id,
            'position' => $position,
        ])
    );

    // Move the last block first.
    $newOrder = [
        $blocks[2]->block_id,
        $blocks[0]->block_id,
        $blocks[1]->block_id,
    ];

    LessonBlock::reorderWithin($page, $newOrder);

    $result = $page->blocks()->get();

    expect($result->pluck('block_id')->all())->toBe($newOrder)
        ->and($result->pluck('position')->all())->toBe([1, 2, 3]);
});

test('reordering pages succeeds when existing positions are 0-based', function () {
    $lesson = Lesson::factory()->create();

    $pages = collect([0, 1, 2])->map(
        fn (int $position) => LessonPage::factory()->create([
            'lesson_id' => $lesson->id,
            'position' => $position,
            'title' => "Page {$position}",
        ])
    );

    $newOrder = $pages->reverse()->pluck('page_id')->values()->all();

    LessonPage::reorderWithin($lesson, $newOrder);

    $result = $lesson->pages()->get();

    // The helper always normalizes to contiguous 1-based positions.
    expect($result->pluck('page_id')->all())->toBe($newOrder)
        ->and($result->pluck('position')->all())->toBe([1, 2, 3]);
});

test('reordering blocks succeeds when existing positions are 0-based', function () {
    $page = LessonPage::factory()->create(['position' => 1]);

    $blocks = collect([0, 1, 2])->map(
        fn (int $position) => LessonBlock::factory()->create([
            'lesson_page_id' => $page->id,
            'position' => $position,
        ])
    );

    $newOrder = $blocks->reverse()->pluck('block_id')->values()->all();

    LessonBlock::reorderWithin($page, $newOrder);

    $result = $page->blocks()->get();

    expect($result->pluck('block_id')->all())->toBe($newOrder)
        ->and($result->pluck('position')->all())->toBe([1, 2, 3]);
});

test('page_id and block_id are unchanged after reordering via the helpers', function () {
    [$lesson, $pages] = makeLessonWithPages(3);

    $blocks = collect(range(1, 3))->map(
        fn (int $position) => LessonBlock::factory()->create([
            'lesson_page_id' => $pages[0]->id,
            'position' => $position,
        ])
    );

    $pageIdsBefore = $pages->pluck('page_id')->sort()->values()->all();
    $blockIdsBefore = $blocks->pluck('block_id')->sort()->values()->all();

    LessonPage::reorderWithin($lesson, $pages->reverse()->pluck('page_id')->values()->all());
    LessonBlock::reorderWithin($pages[0], $blocks->reverse()->pluck('block_id')->values()->all());

    expect($lesson->pages()->pluck('page_id')->sort()->values()->all())->toBe($pageIdsBefore)
        ->and($pages[0]->blocks()->pluck('block_id')->sort()->values()->all())->toBe($blockIdsBefore);
});

test('reordering flags the lesson as having unpublished changes', function () {
    [$lesson, $pages] = makeLessonWithPages(3);
    $lesson->forceFill(['has_unpublished_changes' => false])->save();

    LessonPage::reorderWithin($lesson, $pages->reverse()->pluck('page_id')->values()->all());

    expect($lesson->fresh()->has_unpublished_changes)->toBeTrue();
});

test('a failure mid-reorder rolls back completely, leaving original positions', function () {
    [$lesson, $pages] = makeLessonWithPages(3);

    // Correct count, but one id is unknown: the count pre-check passes, so
    // the failure happens in phase 2, AFTER phase 1 offset positions.
    $badOrder = [
        $pages[1]->page_id,
        'not-a-real-page-id-00000000',
        $pages[0]->page_id,
    ];

    expect(fn () => LessonPage::reorderWithin($lesson, $badOrder))
        ->toThrow(InvalidArgumentException::class);

    // Everything rolled back: original ids and original positions.
    $result = $lesson->pages()->get();

    expect($result->pluck('position')->all())->toBe([1, 2, 3])
        ->and($result->pluck('page_id')->all())->toBe($pages->pluck('page_id')->all());
});

test('a failed block reorder rolls back completely', function () {
    $page = LessonPage::factory()->create(['position' => 1]);

    $blocks = collect(range(1, 3))->map(
        fn (int $position) => LessonBlock::factory()->create([
            'lesson_page_id' => $page->id,
            'position' => $position,
        ])
    );

    $badOrder = [$blocks[0]->block_id, $blocks[1]->block_id, 'bogus-block-id'];

    expect(fn () => LessonBlock::reorderWithin($page, $badOrder))
        ->toThrow(InvalidArgumentException::class);

    expect($page->blocks()->pluck('position')->all())->toBe([1, 2, 3]);
});

test('an incomplete or duplicated id list is rejected up front', function () {
    [$lesson, $pages] = makeLessonWithPages(3);

    // Too few ids.
    expect(fn () => LessonPage::reorderWithin($lesson, [$pages[0]->page_id]))
        ->toThrow(InvalidArgumentException::class);

    // Duplicated id (correct count).
    expect(fn () => LessonPage::reorderWithin($lesson, [
        $pages[0]->page_id,
        $pages[0]->page_id,
        $pages[1]->page_id,
    ]))->toThrow(InvalidArgumentException::class);

    expect($lesson->pages()->pluck('position')->all())->toBe([1, 2, 3]);
});
