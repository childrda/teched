<?php

use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\User;
use App\Services\LessonPublisher;

const MALICIOUS_HTML = '<p onclick="steal()">Real content</p>'
    . '<script>alert("xss")</script>'
    . '<a href="javascript:alert(1)">click</a>'
    . '<iframe src="https://evil.example"></iframe>';

function expectSanitized(string $html): void
{
    expect($html)
        ->toContain('Real content')
        ->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('javascript:')
        ->not->toContain('<iframe');
}

test('malicious markup is stripped from every HTML-bearing field in the published manifest', function () {
    $lesson = Lesson::factory()->create();
    $page = LessonPage::factory()->create(['lesson_id' => $lesson->id, 'position' => 1]);

    $position = 1;

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'position' => $position++,
        'type' => 'rich_text',
        'config' => ['html' => MALICIOUS_HTML],
    ]);

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'position' => $position++,
        'type' => 'callout',
        'config' => ['style' => 'info', 'heading' => 'H', 'html' => MALICIOUS_HTML],
    ]);

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'position' => $position++,
        'type' => 'video',
        'config' => [
            'provider' => 'youtube',
            'video_id' => 'abc123',
            'title' => null,
            'instructions' => null,
            'focus_questions' => [],
            'require_confirmation' => false,
            'captions_available' => false,
            'transcript_html' => MALICIOUS_HTML,
        ],
    ]);

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'position' => $position++,
        'type' => 'short_response',
        'config' => [
            'prompt_html' => MALICIOUS_HTML,
            'placeholder' => null,
            'min_length' => null,
            'rubric_html' => MALICIOUS_HTML,
        ],
    ]);

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'position' => $position++,
        'type' => 'cer',
        'config' => [
            'scenario_html' => MALICIOUS_HTML,
            'fields' => [
                ['id' => 'claim', 'label' => 'Claim', 'placeholder' => null, 'min_length' => null],
            ],
        ],
    ]);

    $version = app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $blocks = collect($version->manifest['pages'][0]['blocks'])->keyBy('type');

    expectSanitized($blocks['rich_text']['config']['html']);
    expectSanitized($blocks['callout']['config']['html']);
    expectSanitized($blocks['video']['config']['transcript_html']);
    expectSanitized($blocks['short_response']['config']['prompt_html']);
    expectSanitized($blocks['short_response']['config']['rubric_html']);
    expectSanitized($blocks['cer']['config']['scenario_html']);
});

test('allowed markup survives sanitization with safe links forced to rel=noopener', function () {
    $sanitizer = app(App\Services\HtmlSanitizer::class);

    $html = '<h2>Title</h2><ul><li><strong>bold</strong> and <em>italic</em></li></ul>'
        . '<blockquote>quote</blockquote><code>code</code>'
        . '<table><thead><tr><th>H</th></tr></thead><tbody><tr><td>C</td></tr></tbody></table>'
        . '<a href="https://example.com">link</a>';

    $result = $sanitizer->sanitize($html);

    expect($result)
        ->toContain('<h2>Title</h2>')
        ->toContain('<strong>bold</strong>')
        ->toContain('<em>italic</em>')
        ->toContain('<blockquote>quote</blockquote>')
        ->toContain('<code>code</code>')
        ->toContain('<table>')
        ->toContain('rel="noopener"')
        ->toContain('href="https://example.com"');
});

test('style attributes and data URLs are removed', function () {
    $sanitizer = app(App\Services\HtmlSanitizer::class);

    expect($sanitizer->sanitize('<p style="color:red">text</p>'))
        ->not->toContain('style=');

    expect($sanitizer->sanitize('<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'))
        ->not->toContain('data:');
});
