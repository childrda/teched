<?php

use App\Blocks\BlockTypeRegistry;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\User;
use App\Services\LessonPublisher;
use Database\Seeders\WeldingLessonSeeder;

beforeEach(function () {
    $this->withoutVite();
    asStudent();
});

test('short_response, cer, and quiz each render on the player page', function () {
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $html = $this->get("/lessons/{$lesson->code}")->assertOk()->getContent();

    foreach (['short_response', 'cer', 'quiz'] as $type) {
        expect(view()->exists("lesson-player.blocks.{$type}"))->toBeTrue()
            ->and($html)->toContain('data-block-type="'.$type.'"');
    }

    expect($html)->not->toContain('This content is currently unavailable');
});

test('rubric_html never appears in the short_response page source', function () {
    $lesson = Lesson::factory()->create();
    $page = LessonPage::factory()->create(['lesson_id' => $lesson->id, 'position' => 1]);

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'type' => 'short_response',
        'position' => 1,
        'config' => [
            'prompt_html' => '<p>Describe a weld.</p>',
            'placeholder' => 'Type here',
            'min_length' => 10,
            'rubric_html' => '<p>RUBRICSENTINEL full credit names two hazards</p>',
        ],
    ]);

    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $this->get("/lessons/{$lesson->fresh()->code}")
        ->assertOk()
        ->assertSee('Describe a weld', false)
        ->assertDontSee('RUBRICSENTINEL')
        ->assertDontSee('rubric_html');
});

test('no seeded quiz feedback string appears in the player page source', function () {
    $this->seed(WeldingLessonSeeder::class);

    $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
    $quiz = blockOfType($lesson->currentVersion()->manifest['pages'], 'quiz');
    $html = $this->get('/lessons/WEL-6.1.1')->assertOk()->getContent();

    foreach ($quiz['config']['questions'] as $question) {
        expect($html)->not->toContain($question['feedback']);
    }
});

test('the quiz block has exactly one live region for announcements', function () {
    $this->seed(WeldingLessonSeeder::class);

    $html = $this->get('/lessons/WEL-6.1.1')->assertOk()->getContent();

    // Isolate the quiz block markup; the player page has other live regions.
    expect(preg_match(
        '/data-block-type="quiz"(.*?)data-block-type="/s',
        $html.'data-block-type="',
        $matches
    ))->toBe(1);

    expect(substr_count($matches[1], 'role="status"'))->toBe(1);
});

test('every speakableText id for the three response blocks has a matching data-speech-id', function () {
    $registry = app(BlockTypeRegistry::class);
    $lesson = Lesson::factory()->create();
    $page = LessonPage::factory()->create(['lesson_id' => $lesson->id, 'position' => 1]);
    $position = 1;

    foreach (['short_response', 'cer', 'quiz'] as $typeKey) {
        $type = $registry->get($typeKey);

        LessonBlock::factory()->create([
            'lesson_page_id' => $page->id,
            'type' => $typeKey,
            'position' => $position++,
            'config' => $type->defaultConfig(),
            'grading' => $type->isAutoGradable() ? fullGradingShape() : null,
        ]);
    }

    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $html = $this->get("/lessons/{$lesson->fresh()->code}")->assertOk()->getContent();
    $payload = $this->getJson("/api/lessons/{$lesson->fresh()->code}")->assertOk()->json();

    foreach ($payload['pages'][0]['blocks'] as $block) {
        foreach ($block['speech'] as $segment) {
            expect($html)->toContain('data-speech-id="'.$segment['id'].'"');
        }
    }
});
