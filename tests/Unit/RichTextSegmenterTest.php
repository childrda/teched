<?php

use App\Blocks\BlockTypeRegistry;
use App\Services\RichTextSegmenter;

/** The ids the player will find in the rendered markup, in document order. */
function taggedSpeechIds(string $html): array
{
    preg_match_all(
        '/data-speech-id="([^"]*)"/',
        app(RichTextSegmenter::class)->tag($html),
        $matches
    );

    return $matches[1];
}

function richTextSpeech(string $html): array
{
    return app(BlockTypeRegistry::class)->get('rich_text')->speakableText(['html' => $html]);
}

test('tagging marks one element per speech segment, with ids matching one to one', function () {
    $html = '<h2>Safety first</h2><p>Wear a mask.</p><ul><li>Gloves</li><li>Apron</li></ul>';

    $segments = richTextSpeech($html);

    // Two elements plus two list items, each addressable on its own.
    expect(array_column($segments, 'id'))->toBe(['html:0', 'html:1', 'html:2', 'html:3'])
        ->and(taggedSpeechIds($html))->toBe(array_column($segments, 'id'));

    expect(array_column($segments, 'text'))->toBe([
        'Safety first',
        'Wear a mask.',
        'Gloves',
        'Apron',
    ]);
});

test('a list is read one item at a time', function () {
    $html = '<ul><li>Gloves</li><li>Apron</li><li>Face shield</li></ul>';

    $segments = richTextSpeech($html);

    expect($segments)->toHaveCount(3)
        ->and(array_column($segments, 'text'))->toBe(['Gloves', 'Apron', 'Face shield'])
        ->and(taggedSpeechIds($html))->toBe(['html:0', 'html:1', 'html:2']);

    // The ids land on the items, not on the list that holds them.
    $tagged = app(RichTextSegmenter::class)->tag($html);

    expect($tagged)->toContain('<li data-speech-id="html:0">Gloves</li>')
        ->and($tagged)->toContain('<ul>');

    expect(preg_match('/<ul[^>]*data-speech-id/', $tagged))->toBe(0);
});

test('ordered lists are split the same way as unordered ones', function () {
    $segments = richTextSpeech('<ol><li>Strike the arc</li><li>Move steadily</li></ol>');

    expect(array_column($segments, 'text'))->toBe(['Strike the arc', 'Move steadily']);
});

test('a nested list is spoken as part of the item that owns it, not twice', function () {
    $html = '<ul><li>Protect yourself<ul><li>Gloves</li><li>Apron</li></ul></li><li>Check the work</li></ul>';

    $segments = richTextSpeech($html);

    // The outer items are the segments; the inner list rides along inside the
    // first one rather than being split out or repeated.
    expect(array_column($segments, 'text'))->toBe([
        'Protect yourself Gloves Apron',
        'Check the work',
    ])->and(taggedSpeechIds($html))->toBe(['html:0', 'html:1']);
});

test('an empty list contributes no segments', function () {
    $segments = richTextSpeech('<p>Before</p><ul></ul><ul><li></li></ul><p>After</p>');

    expect(array_column($segments, 'text'))->toBe(['Before', 'After']);
});

test('each id lands on the element whose words it speaks', function () {
    $tagged = app(RichTextSegmenter::class)->tag('<h2>Heading</h2><p>Body</p>');

    expect($tagged)->toContain('<h2 data-speech-id="html:0">Heading</h2>')
        ->and($tagged)->toContain('<p data-speech-id="html:1">Body</p>');
});

test('an element with nothing to say is neither tagged nor spoken', function () {
    $html = '<p>One</p><hr><p></p><p>Two</p>';

    $segments = richTextSpeech($html);

    expect(array_column($segments, 'text'))->toBe(['One', 'Two'])
        ->and(taggedSpeechIds($html))->toBe(['html:0', 'html:1']);

    // The empty paragraph and the rule survive; they just carry no id.
    expect(app(RichTextSegmenter::class)->tag($html))->toContain('<hr>');
});

test('loose top-level text is wrapped so it can be highlighted in place', function () {
    $segments = richTextSpeech('Bare words<p>In a paragraph</p>');

    expect(array_column($segments, 'text'))->toBe(['Bare words', 'In a paragraph'])
        ->and(app(RichTextSegmenter::class)->tag('Bare words<p>In a paragraph</p>'))
        ->toContain('<span data-speech-id="html:0">Bare words</span>');
});

test('speech extraction strips scripts and decodes entities', function () {
    $segments = richTextSpeech(
        '<p>Bolts &amp; nuts &mdash; not welds</p><script>alert("gotcha")</script>'
    );

    expect($segments)->toHaveCount(1)
        ->and($segments[0]['text'])->toBe('Bolts & nuts — not welds');

    $spoken = implode(' ', array_column($segments, 'text'));

    expect($spoken)->not->toContain('alert')
        ->and($spoken)->not->toContain('<');
});

test('a segment carries only an id, a label, and plain text', function () {
    foreach (richTextSpeech('<h2>Title</h2><p>Body text</p>') as $segment) {
        expect(array_keys($segment))->toBe(['id', 'label', 'text'])
            ->and($segment['label'])->toBeNull()
            ->and($segment['text'])->not->toMatch('/<[^>]+>/');
    }
});

test('empty rich text yields no segments and no tags', function () {
    expect(richTextSpeech(''))->toBe([])
        ->and(taggedSpeechIds(''))->toBe([]);
});

test('multi-byte characters survive tagging', function () {
    expect(app(RichTextSegmenter::class)->tag('<p>Weld — 90° joint · café</p>'))
        ->toContain('Weld — 90° joint · café');
});
