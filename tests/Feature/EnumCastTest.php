<?php

use App\Enums\BlockType;
use App\Enums\LessonStatus;
use App\Enums\PageCompletionType;
use App\Enums\UserRole;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\User;

test('LessonStatus casts and round-trips correctly', function (LessonStatus $status) {
    $lesson = Lesson::factory()->create(['status' => $status]);

    $fresh = Lesson::query()->findOrFail($lesson->id);

    expect($fresh->status)->toBeInstanceOf(LessonStatus::class)
        ->and($fresh->status)->toBe($status)
        ->and($fresh->getRawOriginal('status'))->toBe($status->value);
})->with(LessonStatus::cases());

test('PageCompletionType casts and round-trips correctly', function (PageCompletionType $type) {
    $page = LessonPage::factory()->create(['completion_type' => $type]);

    $fresh = LessonPage::query()->findOrFail($page->id);

    expect($fresh->completion_type)->toBeInstanceOf(PageCompletionType::class)
        ->and($fresh->completion_type)->toBe($type)
        ->and($fresh->getRawOriginal('completion_type'))->toBe($type->value);
})->with(PageCompletionType::cases());

test('BlockType casts and round-trips correctly', function (BlockType $type) {
    $registry = app(App\Blocks\BlockTypeRegistry::class);

    $block = LessonBlock::factory()->create([
        'type' => $type,
        'config' => $registry->get($type->value)->defaultConfig(),
    ]);

    $fresh = LessonBlock::query()->findOrFail($block->id);

    expect($fresh->type)->toBeInstanceOf(BlockType::class)
        ->and($fresh->type)->toBe($type)
        ->and($fresh->getRawOriginal('type'))->toBe($type->value);
})->with(BlockType::cases());

test('every BlockType enum case has a registered block type class', function () {
    $registry = app(App\Blocks\BlockTypeRegistry::class);

    foreach (BlockType::cases() as $case) {
        expect($registry->has($case->value))->toBeTrue("No registered class for enum case {$case->value}");
    }
});

test('UserRole casts and round-trips correctly', function (UserRole $role) {
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    $fresh = User::query()->findOrFail($user->id);

    expect($fresh->role)->toBeInstanceOf(UserRole::class)
        ->and($fresh->role)->toBe($role)
        ->and($fresh->getRawOriginal('role'))->toBe($role->value);
})->with(UserRole::cases());
