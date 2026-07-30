<?php

use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\User;
use App\Services\LessonPublisher;
use Database\Seeders\WeldingLessonSeeder;

beforeEach(fn () => asStudent());

test('a lesson defaults to allowing read-aloud', function () {
    $lesson = Lesson::factory()->create(['code' => 'RA-0.0.1', 'title' => 'Defaults']);

    expect($lesson->settings)->toHaveKey('default_allow_read_aloud')
        ->and($lesson->settings['default_allow_read_aloud'])->toBeTrue();
});

test('a new page inherits the lesson default_allow_read_aloud', function (bool $lessonDefault) {
    $lesson = Lesson::factory()->create([
        'settings' => ['default_allow_read_aloud' => $lessonDefault],
    ]);

    $page = LessonPage::factory()->create(['lesson_id' => $lesson->id, 'position' => 1]);

    expect($page->settings['allow_read_aloud'])->toBe($lessonDefault);
})->with([true, false]);

test('an explicit page setting overrides the lesson default', function () {
    $lesson = Lesson::factory()->create([
        'settings' => ['default_allow_read_aloud' => false],
    ]);

    $page = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 1,
        'settings' => ['allow_read_aloud' => true],
    ]);

    expect($page->settings['allow_read_aloud'])->toBeTrue();
});

test('changing the lesson default later leaves existing pages unchanged', function () {
    $lesson = Lesson::factory()->create([
        'settings' => ['default_allow_read_aloud' => true],
    ]);

    $existing = LessonPage::factory()->create(['lesson_id' => $lesson->id, 'position' => 1]);
    expect($existing->settings['allow_read_aloud'])->toBeTrue();

    $lesson->update(['settings' => ['default_allow_read_aloud' => false]]);

    expect($existing->fresh()->settings['allow_read_aloud'])->toBeTrue();

    // Only pages created after the change pick up the new default.
    $later = LessonPage::factory()->create(['lesson_id' => $lesson->id, 'position' => 2]);
    expect($later->settings['allow_read_aloud'])->toBeFalse();
});

test('the compiled manifest carries no lesson-level read-aloud key', function () {
    $lesson = createLessonWithAllBlockTypes();
    $version = app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $manifest = $version->manifest;

    // Lesson settings are authoring-only and never compiled.
    expect($manifest)->not->toHaveKey('settings');

    $offending = [];

    $walk = function ($value) use (&$walk, &$offending) {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            if (is_string($key) && str_contains(strtolower($key), 'default_allow_read_aloud')) {
                $offending[] = $key;
            }

            $walk($child);
        }
    };

    $walk($manifest);

    expect($offending)->toBe([]);
});

test('every manifest and API page carries exactly one boolean allow_read_aloud', function () {
    $this->seed(WeldingLessonSeeder::class);

    $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();

    $pages = $lesson->currentVersion()->manifest['pages'];
    expect($pages)->not->toBeEmpty();

    foreach ($pages as $page) {
        expect($page['settings'])->toHaveKey('allow_read_aloud')
            ->and($page['settings']['allow_read_aloud'])->toBeBool();
    }

    $payload = $this->getJson('/api/lessons/WEL-6.1.1')->assertOk()->json();

    foreach ($payload['pages'] as $page) {
        expect($page['settings']['allow_read_aloud'])->toBeBool();
    }
});

test('speech is derived at read time and never stored in the manifest', function () {
    $lesson = createLessonWithAllBlockTypes();
    $version = app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    foreach ($version->manifest['pages'][0]['blocks'] as $block) {
        expect($block)->not->toHaveKey('speech');
    }

    // Read-aloud needed no schema_version change.
    expect($version->schema_version)->toBe(1);

    $payload = $this->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();

    foreach ($payload['pages'][0]['blocks'] as $block) {
        expect($block)->toHaveKey('speech');
    }

    // Reading did not write speech back into the stored manifest.
    expect($version->fresh()->manifest['pages'][0]['blocks'][0])->not->toHaveKey('speech');
});

test('no quiz answer, feedback, rubric, or source text ever appears in a speech segment', function () {
    $lesson = Lesson::factory()->create();
    $page = LessonPage::factory()->create(['lesson_id' => $lesson->id, 'position' => 1]);

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'position' => 1,
        'type' => 'quiz',
        'config' => [
            'questions' => [
                [
                    'id' => 'q1',
                    'prompt' => 'Which statement is correct?',
                    'options' => [
                        ['id' => 'o1', 'text' => 'The first choice'],
                        ['id' => 'o2', 'text' => 'The second choice'],
                    ],
                    'answer_id' => 'o1',
                    'feedback' => 'FEEDBACKSENTINEL because welding fuses metal',
                    'source_ref' => ['page' => 'SOURCEPAGESENTINEL', 'excerpt' => 'EXCERPTSENTINEL'],
                ],
            ],
            'shuffle_questions' => false,
        ],
        'grading' => fullGradingShape(),
    ]);

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'position' => 2,
        'type' => 'short_response',
        'config' => [
            'prompt_html' => '<p>Explain your choice.</p>',
            'placeholder' => null,
            'min_length' => null,
            'rubric_html' => '<p>RUBRICSENTINEL full credit names two hazards</p>',
        ],
    ]);

    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $payload = $this->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();

    $spoken = [];

    foreach ($payload['pages'] as $page) {
        foreach ($page['blocks'] as $block) {
            expect($block)->toHaveKey('speech');

            foreach ($block['speech'] as $segment) {
                $spoken[] = $segment['text'] . ' ' . (string) $segment['label'];
            }
        }
    }

    expect($spoken)->not->toBeEmpty();

    $allSpeech = strtolower(implode(' || ', $spoken));

    foreach (['feedbacksentinel', 'sourcepagesentinel', 'excerptsentinel', 'rubricsentinel'] as $sentinel) {
        expect($allSpeech)->not->toContain($sentinel);
    }

    // The correct option's own text is still spoken — it is on screen — but
    // nothing in the speech marks it as the answer.
    expect($allSpeech)->toContain('the first choice')
        ->and($allSpeech)->toContain('the second choice');
});

test('a page with read-aloud disabled returns no speech segments', function () {
    $lesson = Lesson::factory()->create();

    $spokenPage = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 1,
        'settings' => ['allow_read_aloud' => true],
    ]);

    $silentPage = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 2,
        'settings' => ['allow_read_aloud' => false],
    ]);

    foreach ([$spokenPage, $silentPage] as $page) {
        LessonBlock::factory()->create([
            'lesson_page_id' => $page->id,
            'position' => 1,
            'config' => ['html' => '<p>Read me aloud.</p>'],
        ]);
    }

    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $payload = $this->getJson("/api/lessons/{$lesson->code}")->assertOk()->json();

    expect($payload['pages'][0]['blocks'][0]['speech'])->not->toBe([])
        ->and($payload['pages'][1]['blocks'][0]['speech'])->toBe([]);

    // Suppression is enforced server-side; the page still declares the flag
    // so the player can explain why read-aloud is unavailable.
    expect($payload['pages'][1]['settings']['allow_read_aloud'])->toBeFalse()
        ->and($payload['pages'][1]['blocks'][0]['config']['html'])->toContain('Read me aloud');
});
