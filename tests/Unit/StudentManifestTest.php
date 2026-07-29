<?php

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\LessonVersion;
use App\Models\User;
use App\Services\LessonPublisher;
use App\Services\StudentManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;

// StudentManifest reads published rows, so this needs the database.
uses(RefreshDatabase::class);

function studentManifestFor(Lesson $lesson): ?array
{
    return app(StudentManifest::class)->forLesson($lesson->fresh());
}

test('a draft lesson is not servable even when a version row exists', function () {
    $lesson = Lesson::factory()->create();
    LessonVersion::factory()->create(['lesson_id' => $lesson->id, 'version' => 1]);
    $lesson->forceFill(['current_version' => 1])->save();

    expect(studentManifestFor($lesson))->toBeNull();
});

test('an archived lesson is not servable even though it was published', function () {
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    expect(studentManifestFor($lesson))->not->toBeNull();

    $lesson->forceFill(['status' => LessonStatus::Archived])->save();

    expect(studentManifestFor($lesson))->toBeNull();
});

test('a published lesson that was never published has nothing to serve', function () {
    $lesson = Lesson::factory()->published()->create(['current_version' => 0]);

    expect(studentManifestFor($lesson))->toBeNull();
});

test('a published lesson whose current version row is missing has nothing to serve', function () {
    $lesson = Lesson::factory()->published()->create(['current_version' => 7]);

    expect(studentManifestFor($lesson))->toBeNull();
});

test('a published lesson is served with redacted configs and speech', function () {
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $manifest = studentManifestFor($lesson);

    expect($manifest)->toBeArray()
        ->and($manifest['code'])->toBe($lesson->code);

    $blocks = $manifest['pages'][0]['blocks'];

    foreach ($blocks as $block) {
        expect($block)->toHaveKey('speech')
            ->and(array_is_list($block['speech']))->toBeTrue();
    }

    // Redaction happened: the quiz keeps its questions but loses its answers.
    $quiz = collect($blocks)->firstWhere('type', 'quiz')['config'];

    expect($quiz['questions'][0])->not->toHaveKey('answer_id');

    assertNoForbiddenKeys($manifest);
});

test('speech is empty for a page whose author turned read-aloud off', function () {
    $lesson = Lesson::factory()->create();

    foreach ([true, false] as $index => $allowed) {
        $page = LessonPage::factory()->create([
            'lesson_id' => $lesson->id,
            'position' => $index + 1,
            'settings' => ['allow_read_aloud' => $allowed],
        ]);

        LessonBlock::factory()->create([
            'lesson_page_id' => $page->id,
            'type' => 'rich_text',
            'position' => 1,
            'config' => ['html' => '<p>Something worth hearing.</p>'],
        ]);
    }

    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $pages = studentManifestFor($lesson)['pages'];

    expect($pages[0]['blocks'][0]['speech'])->not->toBe([])
        ->and($pages[1]['blocks'][0]['speech'])->toBe([]);
});

test('serving a lesson never mutates the stored manifest', function () {
    $lesson = createLessonWithAllBlockTypes();
    $version = app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $before = $version->manifest;

    studentManifestFor($lesson);

    expect($version->fresh()->manifest)->toEqual($before);
});
