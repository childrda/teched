<?php

use App\Blocks\BlockTypeRegistry;

/** Runs a config through the real pipeline, then reads the redacted result. */
function speechFor(string $typeKey, ?array $config = null): array
{
    $type = app(BlockTypeRegistry::class)->get($typeKey);
    $config ??= $type->defaultConfig();

    $redacted = $type->redactConfig($type->compileConfig($type->validateConfig($config)));

    return $type->speakableText($redacted);
}

test('every registered type returns well-formed plain-text speech segments', function () {
    foreach (app(BlockTypeRegistry::class)->all() as $key => $type) {
        $segments = speechFor($key);

        expect(array_is_list($segments))->toBeTrue("{$key} must return a list of segments");

        $seenIds = [];

        foreach ($segments as $segment) {
            // Exactly these keys: a segment is speech, not a payload.
            expect(array_keys($segment))
                ->toBe(['id', 'label', 'text'], "{$key} segments must carry only id, label, and text");

            expect($segment['id'])->toBeString()->not->toBe('');
            expect($segment['text'])->toBeString()->not->toBe('');

            if ($segment['label'] !== null) {
                expect($segment['label'])->toBeString()->not->toBe('');
            }

            // Plain text: no markup and no collapsed whitespace runs.
            expect($segment['text'])->not->toMatch('/<[^>]+>/')->not->toMatch('/\s{2,}/');

            expect(isset($seenIds[$segment['id']]))
                ->toBeFalse("duplicate segment id \"{$segment['id']}\" in {$key}");

            $seenIds[$segment['id']] = true;
        }
    }
});

test('speakableText never mutates the config it is given', function () {
    foreach (app(BlockTypeRegistry::class)->all() as $key => $type) {
        $redacted = $type->redactConfig($type->compileConfig($type->validateConfig($type->defaultConfig())));
        $before = $redacted;

        $type->speakableText($redacted);

        expect($redacted)->toEqual($before, "speakableText for {$key} mutated its input");
    }
});

test('file_link has nothing to read aloud', function () {
    expect(speechFor('file_link'))->toBe([]);
});

test('markup is stripped and whitespace collapsed without splitting inline words', function () {
    $segments = app(BlockTypeRegistry::class)->get('rich_text')->speakableText([
        'html' => "<h2>Safety   First</h2>\n<p>Wear a <strong>mask</strong>. Use <em>gloves</em>too.</p>",
    ]);

    // One segment per top-level element, so the player can highlight each
    // in place; inline tags stay inside their sentence.
    expect($segments)->toBe([
        ['id' => 'html:0', 'label' => null, 'text' => 'Safety First'],
        ['id' => 'html:1', 'label' => null, 'text' => 'Wear a mask. Use glovestoo.'],
    ]);
});

test('HTML entities are decoded for speech', function () {
    $segments = app(BlockTypeRegistry::class)->get('rich_text')->speakableText([
        'html' => '<p>Bolts &amp; nuts &mdash; not welds</p>',
    ]);

    expect($segments[0]['text'])->toBe('Bolts & nuts — not welds');
});

test('callout speaks its heading as the segment label', function () {
    $segments = speechFor('callout', [
        'style' => 'warning',
        'heading' => 'Safety First',
        'html' => '<p>Wear a mask.</p>',
    ]);

    expect($segments)->toBe([
        ['id' => 'html', 'label' => 'Safety First', 'text' => 'Wear a mask.'],
    ]);
});

test('image reads alt text then long description', function () {
    $segments = speechFor('image', [
        'url' => '/storage/lessons/welder.png',
        'alt' => 'A welder at work',
        'caption' => 'Caption shown under the image',
        'long_description' => 'A long description of the scene.',
    ]);

    expect(array_column($segments, 'text'))->toBe([
        'A welder at work',
        'A long description of the scene.',
    ]);
});

test('image_labeling reads instructions, then alt text, then long description', function () {
    $type = app(BlockTypeRegistry::class)->get('image_labeling');

    $config = $type->defaultConfig();
    $config['instructions'] = 'Drag each label onto its point.';
    $config['image_alt'] = 'Weld diagram';
    $config['long_description'] = 'Shows the torch and the weld pool.';

    $segments = speechFor('image_labeling', $config);

    expect($segments[0])->toBe([
        'id' => 'instructions',
        'label' => 'Instructions',
        'text' => 'Drag each label onto its point.',
    ]);

    expect(array_column($segments, 'text'))->toBe([
        'Drag each label onto its point.',
        'Weld diagram',
        'Shows the torch and the weld pool.',
    ]);
});

test('image_labeling without instructions emits no instructions segment', function () {
    $type = app(BlockTypeRegistry::class)->get('image_labeling');

    $config = $type->defaultConfig();
    $config['instructions'] = null;
    $config['image_alt'] = 'Weld diagram';
    $config['long_description'] = 'Shows the torch and the weld pool.';

    expect(array_column(speechFor('image_labeling', $config), 'id'))->toBe([
        'image_alt',
        'long_description',
    ]);
});

test('static_table linearizes each row with its column headers', function () {
    $segments = speechFor('static_table', [
        'caption' => 'Ways to join metal',
        'headers' => ['Method', 'Permanent?', 'Strength'],
        'rows' => [
            ['Bolts', 'No', 'Medium'],
            ['Weld', 'Yes', 'High'],
        ],
    ]);

    expect(array_column($segments, 'text'))->toBe([
        'Ways to join metal',
        'Bolts. Permanent?: No. Strength: Medium.',
        'Weld. Permanent?: Yes. Strength: High.',
    ])->and(array_column($segments, 'label'))->toBe([null, 'Row 1', 'Row 2']);
});

test('vocabulary_cards reads term, definition, then analogy per card', function () {
    $segments = speechFor('vocabulary_cards', [
        'terms' => [
            ['id' => 'a', 'term' => 'Weld', 'definition' => 'A fused joint.', 'analogy' => 'Like melted crayons.'],
            ['id' => 'b', 'term' => 'Filler', 'definition' => 'Added metal.', 'analogy' => null],
        ],
        'reveal_mode' => 'tap',
    ]);

    // The second card has no analogy, so no analogy segment is emitted.
    expect($segments)->toBe([
        ['id' => 'a:term', 'label' => 'Term 1', 'text' => 'Weld'],
        ['id' => 'a:definition', 'label' => 'Definition', 'text' => 'A fused joint.'],
        ['id' => 'a:analogy', 'label' => 'Analogy', 'text' => 'Like melted crayons.'],
        ['id' => 'b:term', 'label' => 'Term 2', 'text' => 'Filler'],
        ['id' => 'b:definition', 'label' => 'Definition', 'text' => 'Added metal.'],
    ]);
});

test('video reads title, instructions, and focus questions but never the transcript', function () {
    $segments = speechFor('video', [
        'provider' => 'youtube',
        'video_id' => 'abc123',
        'title' => 'Welding in Action',
        'instructions' => 'Watch the whole clip.',
        'focus_questions' => [
            ['id' => 'fq1', 'text' => 'What melts?'],
            ['id' => 'fq2', 'text' => 'What protects the welder?'],
        ],
        'require_confirmation' => true,
        'captions_available' => true,
        'transcript_html' => '<p>TRANSCRIPTSENTINEL the narrator speaks</p>',
    ]);

    expect(array_column($segments, 'text'))->toBe([
        'Welding in Action',
        'Watch the whole clip.',
        'What melts?',
        'What protects the welder?',
    ]);

    foreach ($segments as $segment) {
        expect($segment['text'])->not->toContain('TRANSCRIPTSENTINEL');
    }
});

test('matching reads instructions, then all labels, then all descriptions', function () {
    $segments = speechFor('matching', [
        'instructions' => 'Match each term to its description.',
        'bank' => [
            ['id' => 'i1', 'label' => 'Weld'],
            ['id' => 'i2', 'label' => 'Filler'],
        ],
        'slots' => [
            ['id' => 's1', 'description' => 'A fused joint', 'answer_id' => 'i1'],
            ['id' => 's2', 'description' => 'Added metal', 'answer_id' => 'i2'],
        ],
        'shuffle' => true,
    ]);

    // Labels are spoken in compiled bank order, which compiling sorts by
    // label: Filler before Weld, whatever order the author typed.
    expect(array_column($segments, 'id'))->toBe([
        'instructions',
        'i2:label',
        'i1:label',
        's1:description',
        's2:description',
    ]);
});

test('quiz reads each question prompt then its lettered options', function () {
    $segments = speechFor('quiz', [
        'questions' => [
            [
                'id' => 'q1',
                'prompt' => 'What does welding do?',
                'options' => [
                    ['id' => 'o1', 'text' => 'Fuses materials'],
                    ['id' => 'o2', 'text' => 'Glues materials'],
                ],
                'answer_id' => 'o1',
                'feedback' => 'FEEDBACKSENTINEL welding fuses metal',
                'source_ref' => ['page' => 'SOURCESENTINEL', 'excerpt' => 'EXCERPTSENTINEL'],
            ],
        ],
        'shuffle_questions' => false,
    ]);

    // Asserting the whole list proves nothing else (answer, feedback,
    // source_ref) reaches speech.
    expect($segments)->toBe([
        ['id' => 'q1:prompt', 'label' => 'Question 1', 'text' => 'What does welding do?'],
        ['id' => 'q1:o1', 'label' => 'Option A', 'text' => 'Fuses materials'],
        ['id' => 'q1:o2', 'label' => 'Option B', 'text' => 'Glues materials'],
    ]);
});

test('spoken option letters continue past Z', function () {
    $options = [];

    for ($i = 0; $i < 27; $i++) {
        $options[] = ['id' => "o{$i}", 'text' => "Choice number {$i}"];
    }

    $segments = speechFor('quiz', [
        'questions' => [
            [
                'id' => 'q1',
                'prompt' => 'Pick one',
                'options' => $options,
                'answer_id' => 'o0',
                'feedback' => null,
                'source_ref' => null,
            ],
        ],
        'shuffle_questions' => false,
    ]);

    // Segment 0 is the prompt, so option N is at segment N + 1.
    $labels = array_column($segments, 'label');

    expect($labels[1])->toBe('Option A')
        ->and($labels[26])->toBe('Option Z')
        ->and($labels[27])->toBe('Option AA');
});

test('short_response reads the prompt but never the rubric', function () {
    $segments = speechFor('short_response', [
        'prompt_html' => '<p>Describe a weld.</p>',
        'placeholder' => 'Type here',
        'min_length' => 10,
        'rubric_html' => '<p>RUBRICSENTINEL full credit names two hazards</p>',
    ]);

    expect(array_column($segments, 'text'))->toBe(['Describe a weld.']);
});

test('cer reads the scenario then each field label', function () {
    $segments = speechFor('cer', [
        'scenario_html' => '<p>A bike frame cracked.</p>',
        'fields' => [
            ['id' => 'claim', 'label' => 'Claim', 'placeholder' => null, 'min_length' => null],
            ['id' => 'evidence', 'label' => 'Evidence', 'placeholder' => null, 'min_length' => null],
        ],
    ]);

    expect(array_column($segments, 'text'))->toBe(['A bike frame cracked.', 'Claim', 'Evidence'])
        ->and(array_column($segments, 'id'))->toBe(['scenario', 'claim:label', 'evidence:label']);
});
