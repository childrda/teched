<?php

use App\Blocks\BlockTypeRegistry;
use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\LessonVersion;
use App\Models\User;
use App\Services\LessonPublisher;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** The seven content block types Phase 2A renders. */
const CONTENT_BLOCK_TYPES = [
    'rich_text',
    'image',
    'video',
    'file_link',
    'callout',
    'static_table',
    'vocabulary_cards',
];

// The player view loads built assets; the tests are about its markup, not
// about whether someone has run the front-end build.
beforeEach(fn () => $this->withoutVite());

function publish(Lesson $lesson): Lesson
{
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    return $lesson->fresh();
}

/** The player and the API must be servable, or not, together. */
function assertSameAvailability(TestCase $test, string $code, int $expected): void
{
    expect($test->get("/lessons/{$code}")->status())
        ->toBe($expected, "player status for {$code}");

    expect($test->getJson("/api/lessons/{$code}")->status())
        ->toBe($expected, "API status for {$code}");
}

test('the player renders a published lesson', function () {
    $lesson = publish(createLessonWithAllBlockTypes());

    $this->get("/lessons/{$lesson->code}")
        ->assertOk()
        ->assertViewIs('lesson-player.show')
        ->assertSee($lesson->title)
        ->assertSee('Everything Page')
        ->assertSee('Lesson navigation', false);
});

test('the player mirrors the API availability rules exactly', function () {
    assertSameAvailability($this, 'NOPE-0.0.0', 404);

    $draft = Lesson::factory()->create();
    LessonVersion::factory()->create(['lesson_id' => $draft->id, 'version' => 1]);
    $draft->forceFill(['current_version' => 1])->save();
    assertSameAvailability($this, $draft->code, 404);

    $missingRow = Lesson::factory()->published()->create(['current_version' => 7]);
    assertSameAvailability($this, $missingRow->code, 404);

    $neverPublished = Lesson::factory()->published()->create(['current_version' => 0]);
    assertSameAvailability($this, $neverPublished->code, 404);

    $published = publish(createLessonWithAllBlockTypes());
    assertSameAvailability($this, $published->code, 200);

    $published->forceFill(['status' => LessonStatus::Archived])->save();
    assertSameAvailability($this, $published->code, 404);
});

test('the view receives exactly the manifest the API returns', function () {
    $lesson = publish(createLessonWithAllBlockTypes());

    $api = $this->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();
    $embedded = $this->get("/lessons/{$lesson->code}")->assertOk()->viewData('manifest');

    $this->assertSame($api, $embedded);
});

test('the embedded manifest carries no answers, feedback, rubrics, or sources', function () {
    $lesson = publish(createLessonWithAllBlockTypes());

    $manifest = $this->get("/lessons/{$lesson->code}")->assertOk()->viewData('manifest');

    assertNoForbiddenKeys($manifest);
});

test('a page with read-aloud turned off is embedded with empty speech arrays', function () {
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
            'config' => ['html' => '<p>Read this to me.</p>'],
        ]);
    }

    $lesson = publish($lesson);

    $pages = $this->get("/lessons/{$lesson->code}")->assertOk()->viewData('manifest')['pages'];

    expect($pages[0]['blocks'][0]['speech'])->not->toBe([])
        ->and($pages[1]['blocks'][0]['speech'])->toBe([])
        ->and($pages[1]['settings']['allow_read_aloud'])->toBeFalse();
});

test('every content block type resolves to an existing partial', function () {
    foreach (CONTENT_BLOCK_TYPES as $type) {
        expect(view()->exists("lesson-player.blocks.{$type}"))
            ->toBeTrue("no player partial for content block type \"{$type}\"");
    }
});

test('every content block type renders through its own partial', function () {
    $registry = app(BlockTypeRegistry::class);
    $lesson = Lesson::factory()->create();

    $page = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 1,
        'title' => 'Content Types',
    ]);

    $position = 1;

    foreach (CONTENT_BLOCK_TYPES as $type) {
        LessonBlock::factory()->create([
            'lesson_page_id' => $page->id,
            'type' => $type,
            'position' => $position++,
            'config' => $registry->get($type)->defaultConfig(),
        ]);
    }

    $lesson = publish($lesson);

    $response = $this->get("/lessons/{$lesson->code}")->assertOk();

    $response->assertDontSee('This content is currently unavailable');

    foreach (CONTENT_BLOCK_TYPES as $type) {
        $response->assertSee('data-block-type="' . $type . '"', false);
    }

    // Semantics the renderers promise, spot-checked on real output.
    $response->assertSee('<table', false)
        ->assertSee('scope="col"', false)
        ->assertSee('youtube-nocookie.com/embed/', false)
        ->assertSee('<figure', false);
});

test('a block type with no renderer shows a neutral message and logs the details', function () {
    Log::spy();

    $lesson = publishedLessonWithManifestBlocks([
        ['block_id' => 'BLOCK-QUIZ', 'type' => 'quiz', 'config' => [
            'questions' => [],
            'shuffle_questions' => false,
            'shuffle_options' => false,
        ], 'grading' => null],
    ]);

    $this->get("/lessons/{$lesson->code}")
        ->assertOk()
        ->assertSee('This content is currently unavailable');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context) => $context['block_id'] === 'BLOCK-QUIZ'
            && $context['type'] === 'quiz'
    );
});

test('blocks missing every optional field still render', function () {
    $lesson = publishedLessonWithManifestBlocks([
        ['block_id' => 'B1', 'type' => 'rich_text', 'config' => [], 'grading' => null],
        ['block_id' => 'B2', 'type' => 'image', 'config' => [
            'url' => '/storage/plate.png',
            'alt' => 'A steel plate',
        ], 'grading' => null],
        ['block_id' => 'B3', 'type' => 'video', 'config' => [
            'provider' => 'youtube',
            'video_id' => 'abc123',
        ], 'grading' => null],
        ['block_id' => 'B4', 'type' => 'file_link', 'config' => [
            'url' => '/storage/handout.pdf',
            'label' => 'Handout',
        ], 'grading' => null],
        ['block_id' => 'B5', 'type' => 'callout', 'config' => [
            'html' => '<p>Mind the sparks.</p>',
        ], 'grading' => null],
        ['block_id' => 'B6', 'type' => 'static_table', 'config' => [
            'headers' => ['Method'],
            'rows' => [['Weld']],
        ], 'grading' => null],
        ['block_id' => 'B7', 'type' => 'vocabulary_cards', 'config' => [
            'terms' => [['id' => 't1', 'term' => 'Weld', 'definition' => 'A joint.']],
        ], 'grading' => null],
    ]);

    $this->get("/lessons/{$lesson->code}")
        ->assertOk()
        ->assertSee('A steel plate', false)
        ->assertSee('Handout')
        ->assertDontSee('This content is currently unavailable');
});

test('a page with no blocks still renders its title and navigation', function () {
    $lesson = Lesson::factory()->create();

    LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 1,
        'title' => 'Nothing Here Yet',
    ]);

    LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 2,
        'title' => 'Second Page',
    ]);

    $lesson = publish($lesson);

    $this->get("/lessons/{$lesson->code}")
        ->assertOk()
        ->assertSee('Nothing Here Yet')
        ->assertSee('Second Page')
        ->assertSee('Continue')
        ->assertSee('Lesson navigation', false);
});

test('the player embeds the manifest without a client-side fetch', function () {
    $lesson = publish(createLessonWithAllBlockTypes());

    $html = $this->get("/lessons/{$lesson->code}")->assertOk()->getContent();

    // Escaped by @js into the x-data attribute, never spliced into markup.
    expect($html)->toContain('x-data="lessonPlayer(')
        ->and($html)->not->toContain('fetch(')
        ->and($html)->not->toContain('/api/lessons/');
});

/**
 * A published lesson whose manifest is written by hand, so a page or block
 * can be shaped in ways the publisher would normally normalise away.
 */
function publishedLessonWithManifestBlocks(array $blocks): Lesson
{
    $lesson = Lesson::factory()->published()->create(['current_version' => 1]);

    LessonVersion::factory()->create([
        'lesson_id' => $lesson->id,
        'version' => 1,
        'manifest' => [
            'schema_version' => 1,
            'code' => $lesson->code,
            'title' => $lesson->title,
            'version' => 1,
            'estimated_minutes' => null,
            'learning_target' => null,
            'success_criteria' => null,
            'pages' => [
                [
                    'page_id' => 'PAGE-SPARSE',
                    'title' => 'Sparse Page',
                    'position' => 1,
                    'completion_type' => 'view',
                    'estimated_minutes' => null,
                    'settings' => [],
                    'blocks' => $blocks,
                ],
            ],
        ],
    ]);

    return $lesson->fresh();
}
