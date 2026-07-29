<?php

use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\User;
use App\Services\LessonPublisher;
use Database\Seeders\WeldingLessonSeeder;

beforeEach(fn () => asStudent());

test('published manifest with every block type validates against the JSON schema', function () {
    $lesson = createLessonWithAllBlockTypes();

    $version = app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    [$valid, $errors] = validateManifestAgainstSchema($version->manifest);

    expect($valid)->toBeTrue($errors);
});

test('the seeded welding lesson manifest validates against the JSON schema', function () {
    $this->seed(WeldingLessonSeeder::class);

    $version = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail()->currentVersion();

    expect($version)->not->toBeNull();

    [$valid, $errors] = validateManifestAgainstSchema($version->manifest);

    expect($valid)->toBeTrue($errors);
});

test('the API returns pages and blocks in position order', function () {
    $user = User::factory()->create();
    $lesson = Lesson::factory()->create();

    // Create pages deliberately out of order.
    foreach ([3, 1, 2] as $position) {
        $page = LessonPage::factory()->create([
            'lesson_id' => $lesson->id,
            'position' => $position,
            'title' => "Page {$position}",
        ]);

        foreach ([2, 1] as $blockPosition) {
            LessonBlock::factory()->create([
                'lesson_page_id' => $page->id,
                'position' => $blockPosition,
                'config' => ['html' => "<p>Block {$blockPosition}</p>"],
            ]);
        }
    }

    app(LessonPublisher::class)->publish($lesson, $user);

    $payload = $this->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();

    expect(array_column($payload['pages'], 'position'))->toBe([1, 2, 3]);

    foreach ($payload['pages'] as $page) {
        expect(count($page['blocks']))->toBe(2);
    }

    // Block order inside each page follows authoring position.
    $firstPage = $payload['pages'][0];
    expect($firstPage['blocks'][0]['config']['html'])->toContain('Block 1')
        ->and($firstPage['blocks'][1]['config']['html'])->toContain('Block 2');
});

test('the API payload contains no answer keys anywhere, checked recursively and case-insensitively', function () {
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $payload = $this->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();

    assertNoForbiddenKeys($payload);
});

test('redaction does not mutate the stored manifest or the model attribute', function () {
    $lesson = createLessonWithAllBlockTypes();
    $version = app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $before = $version->manifest;

    $this->getJson("/api/lessons/{$lesson->code}")->assertOk();

    // In-memory model attribute is untouched.
    expect($version->manifest)->toEqual($before);

    // Database row is untouched and still carries answers.
    $stored = $version->fresh()->manifest;
    expect($stored)->toEqual($before);

    $quizConfig = collect($stored['pages'][0]['blocks'])->firstWhere('type', 'quiz')['config'];
    expect($quizConfig['questions'][0])->toHaveKey('answer_id');
});

test('redactConfig operates on a copy and never mutates its input', function () {
    $registry = app(App\Blocks\BlockTypeRegistry::class);

    foreach ($registry->all() as $type) {
        $compiled = $type->compileConfig($type->validateConfig($type->defaultConfig()));
        $original = $compiled;

        $type->redactConfig($compiled);

        expect($compiled)->toEqual($original, "redactConfig for {$type->key()} mutated its input");
    }
});

test('a lesson with no published version returns 404', function () {
    $lesson = Lesson::factory()->create();

    $this->getJson("/api/lessons/{$lesson->code}")->assertNotFound();
});

test('a draft lesson returns 404 even if a version row exists', function () {
    $lesson = Lesson::factory()->create();
    App\Models\LessonVersion::factory()->create(['lesson_id' => $lesson->id]);
    $lesson->forceFill(['current_version' => 1])->save();

    $this->getJson("/api/lessons/{$lesson->code}")->assertNotFound();
});

test('an archived lesson returns 404 even though prior versions exist', function () {
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $this->getJson("/api/lessons/{$lesson->code}")->assertOk();

    $lesson->forceFill(['status' => App\Enums\LessonStatus::Archived])->save();

    $this->getJson("/api/lessons/{$lesson->code}")->assertNotFound();
});

test('a published lesson whose current_version row is missing returns 404', function () {
    $lesson = Lesson::factory()->published()->create(['current_version' => 7]);

    $this->getJson("/api/lessons/{$lesson->code}")->assertNotFound();
});

test('an unknown code returns 404', function () {
    $this->getJson('/api/lessons/NOPE-0.0.0')->assertNotFound();
});
