<?php

use App\Enums\LessonStatus;
use App\Exceptions\UnknownBlockTypeException;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\User;
use App\Services\LessonPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

test('a publish that fails validation creates no version row and leaves the lesson untouched', function () {
    $lesson = Lesson::factory()->create();
    $page = LessonPage::factory()->create(['lesson_id' => $lesson->id, 'position' => 1]);

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'position' => 1,
        'config' => ['html' => '<p>Valid block</p>'],
    ]);

    // Invalid: quiz with no questions.
    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'position' => 2,
        'type' => 'quiz',
        'config' => ['questions' => [], 'shuffle_questions' => false],
    ]);

    expect(fn () => app(LessonPublisher::class)->publish($lesson, User::factory()->create()))
        ->toThrow(ValidationException::class);

    $fresh = $lesson->fresh();

    expect($fresh->versions()->count())->toBe(0)
        ->and($fresh->status)->toBe(LessonStatus::Draft)
        ->and($fresh->current_version)->toBe(0);
});

test('a failed republish leaves the previous version and lesson state intact', function () {
    $lesson = createLessonWithAllBlockTypes();
    $user = User::factory()->create();
    $publisher = app(LessonPublisher::class);

    $v1 = $publisher->publish($lesson, $user);

    // Corrupt one block so the next publish fails.
    $lesson->pages()->first()->blocks()->first()->update(['config' => []]);

    expect(fn () => $publisher->publish($lesson->fresh(), $user))
        ->toThrow(ValidationException::class);

    $fresh = $lesson->fresh();

    expect($fresh->versions()->count())->toBe(1)
        ->and($fresh->current_version)->toBe(1)
        ->and($fresh->status)->toBe(LessonStatus::Published)
        ->and($v1->fresh()->manifest)->toEqual($v1->manifest);
});

test('publishing a lesson containing an unregistered block type throws a descriptive exception', function () {
    $lesson = Lesson::factory()->create();
    $page = LessonPage::factory()->create(['lesson_id' => $lesson->id, 'position' => 1]);

    // Insert directly so the enum cast cannot reject the bogus type first.
    DB::table('lesson_blocks')->insert([
        'block_id' => (string) Str::ulid(),
        'lesson_page_id' => $page->id,
        'type' => 'hologram',
        'position' => 1,
        'config' => json_encode(['anything' => true]),
        'grading' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(LessonPublisher::class)->publish($lesson, User::factory()->create()))
        ->toThrow(UnknownBlockTypeException::class, 'hologram');

    expect($lesson->fresh()->versions()->count())->toBe(0);
});
