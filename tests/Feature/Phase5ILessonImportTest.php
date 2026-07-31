<?php

use App\Enums\LessonStatus;
use App\Enums\UserRole;
use App\Exceptions\AuthoringValidationException;
use App\Models\Lesson;
use App\Models\LessonPage;
use App\Models\User;
use App\Services\LessonAuthoringService;
use App\Services\LessonImportService;
use App\Services\StudentManifest;
use App\Support\ImportAssetPlaceholder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * @return array<string, mixed>
 */
function importMinimalPackage(array $overrides = []): array
{
    $base = [
        'format_version' => '1.0',
        'source' => [
            'title' => 'Welding deck',
            'type' => 'pptx',
            'filename' => 'welding.pptx',
        ],
        'lesson' => [
            'code' => 'IMP-'.Str::upper(Str::random(6)),
            'title' => 'Import smoke lesson',
            'description' => 'From JSON import.',
            'subject' => 'Welding',
            'grade_range' => '6-8',
            'estimated_minutes' => 20,
            'learning_target' => 'I can explain welding.',
            'success_criteria' => ['I can define welding.'],
            'standards' => ['CTE.WEL.6.1'],
        ],
        'pages' => [
            [
                'key' => 'page1',
                'title' => 'Intro',
                'completion_type' => 'view',
                'estimated_minutes' => 5,
                'blocks' => [
                    [
                        'key' => 'intro',
                        'type' => 'rich_text',
                        'html' => '<p>Welding joins materials.</p><script>alert(1)</script>',
                    ],
                    [
                        'key' => 'diagram',
                        'type' => 'image',
                        'url' => ImportAssetPlaceholder::IMAGE_REQUIRED,
                        'alt' => 'A MIG welding torch held near a joint',
                        'caption' => null,
                        'long_description' => 'Torch tip near a metal seam.',
                    ],
                ],
            ],
            [
                'key' => 'page2',
                'title' => 'Check',
                'completion_type' => 'pass_activity',
                'estimated_minutes' => 10,
                'blocks' => [
                    [
                        'key' => 'quiz1',
                        'type' => 'quiz',
                        'shuffle_questions' => false,
                        'questions' => [
                            [
                                'key' => 'q1',
                                'prompt' => 'What is welding?',
                                'options' => [
                                    ['key' => 'a', 'text' => 'Joining permanently with heat or pressure'],
                                    ['key' => 'b', 'text' => 'Bolting pieces together'],
                                ],
                                'answer_id' => 'a',
                                'feedback' => 'Welding joins permanently.',
                                'source_ref' => [
                                    'page' => 'Slide 4',
                                    'excerpt' => 'Welding permanently joins materials using heat, pressure, or both.',
                                ],
                            ],
                            [
                                'key' => 'q2',
                                'prompt' => 'Bolts are?',
                                'options' => [
                                    ['key' => 'a', 'text' => 'Fasteners'],
                                    ['key' => 'b', 'text' => 'Welds'],
                                ],
                                'answer_id' => 'a',
                                'feedback' => null,
                                'source_ref' => [
                                    'page' => 'Slide 5',
                                    'excerpt' => 'Fasteners hold pieces together without joining the metal.',
                                ],
                            ],
                        ],
                        'grading' => [
                            'rule' => 'min_score',
                            'min_score' => 80,
                            'allow_retry' => true,
                            'max_attempts' => 3,
                            'record_first_attempt' => true,
                            'points' => 10,
                            'reveal_policy' => 'on_pass',
                            'reveal_answers' => false,
                        ],
                    ],
                ],
            ],
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

test('valid package creates one editable draft with no published version', function () {
    $teacher = asTeacher();
    $package = importMinimalPackage();

    $result = app(LessonImportService::class)->import($package, $teacher);
    $lesson = $result['lesson']->fresh(['pages.blocks']);

    expect($lesson->status)->toBe(LessonStatus::Draft)
        ->and($lesson->current_version)->toBe(0)
        ->and((int) $lesson->created_by_user_id)->toBe((int) $teacher->id)
        ->and($lesson->pages)->toHaveCount(2);

    $placeholderWarnings = collect($result['warnings'])->where('code', 'ASSET_PLACEHOLDER');
    expect($placeholderWarnings)->not->toBeEmpty();
});

test('local keys become ulids and answer references rewrite; option keys do not collide across questions', function () {
    $teacher = asTeacher();
    $lesson = app(LessonImportService::class)->import(importMinimalPackage(), $teacher)['lesson']
        ->fresh(['pages.blocks']);

    $quiz = $lesson->pages->firstWhere('title', 'Check')->blocks->firstWhere('type', 'quiz');
    $questions = $quiz->config['questions'];

    expect($questions[0]['id'])->not->toBe('q1')
        ->and(strlen($questions[0]['id']))->toBeGreaterThan(20)
        ->and($questions[0]['options'][0]['id'])->not->toBe('a')
        ->and($questions[0]['answer_id'])->toBe($questions[0]['options'][0]['id'])
        ->and($questions[1]['options'][0]['id'])->not->toBe($questions[0]['options'][0]['id'])
        ->and($questions[1]['answer_id'])->toBe($questions[1]['options'][0]['id']);
});

test('unresolved or duplicate keys reject the whole import with no rows written', function () {
    $teacher = asTeacher();
    $beforeLessons = Lesson::query()->count();
    $beforePages = LessonPage::query()->count();

    $bad = importMinimalPackage();
    $bad['pages'][1]['blocks'][0]['questions'][0]['answer_id'] = 'missing';

    try {
        app(LessonImportService::class)->import($bad, $teacher);
        expect(false)->toBeTrue('expected AuthoringValidationException');
    } catch (AuthoringValidationException $e) {
        expect(implode("\n", $e->errors))->toContain('answer_id')
            ->and(Lesson::query()->count())->toBe($beforeLessons)
            ->and(LessonPage::query()->count())->toBe($beforePages);
    }

    $dup = importMinimalPackage(['lesson' => ['code' => 'IMP-DUP'.Str::random(4)]]);
    $dup['pages'][0]['blocks'][] = [
        'key' => 'intro',
        'type' => 'rich_text',
        'html' => '<p>dup</p>',
    ];

    expect(fn () => app(LessonImportService::class)->import($dup, $teacher))
        ->toThrow(AuthoringValidationException::class);
});

test('unknown fields block types and enum values reject the import', function () {
    $teacher = asTeacher();

    $unknownField = importMinimalPackage();
    $unknownField['lesson']['owner_id'] = 99;
    expect(fn () => app(LessonImportService::class)->import($unknownField, $teacher))
        ->toThrow(AuthoringValidationException::class);

    $unknownType = importMinimalPackage();
    $unknownType['pages'][0]['blocks'][0]['type'] = 'magic_block';
    expect(fn () => app(LessonImportService::class)->import($unknownType, $teacher))
        ->toThrow(AuthoringValidationException::class);

    $badEnum = importMinimalPackage();
    $badEnum['pages'][0]['completion_type'] = 'finish_when_bored';
    expect(fn () => app(LessonImportService::class)->import($badEnum, $teacher))
        ->toThrow(AuthoringValidationException::class);

    $badVersion = importMinimalPackage(['format_version' => '9.9']);
    expect(fn () => app(LessonImportService::class)->import($badVersion, $teacher))
        ->toThrow(AuthoringValidationException::class);
});

test('failure mid-package creates no lesson page or block rows', function () {
    $teacher = asTeacher();
    $codes = Lesson::withTrashed()->pluck('code')->all();

    $package = importMinimalPackage();
    $package['pages'][1]['blocks'][0]['questions'][1]['options'] = [
        ['key' => 'a', 'text' => 'only one option'],
    ];

    $lessonCount = Lesson::query()->count();

    expect(fn () => app(LessonImportService::class)->import($package, $teacher))
        ->toThrow(AuthoringValidationException::class);

    expect(Lesson::query()->count())->toBe($lessonCount)
        ->and(Lesson::withTrashed()->pluck('code')->all())->toBe($codes);
});

test('unsafe html is sanitized before persistence', function () {
    $teacher = asTeacher();
    $lesson = app(LessonImportService::class)->import(importMinimalPackage(), $teacher)['lesson']
        ->fresh(['pages.blocks']);

    $html = $lesson->pages->first()->blocks->firstWhere('type', 'rich_text')->config['html'];
    expect($html)->toContain('<p>Welding joins materials.</p>')
        ->and($html)->not->toContain('<script>')
        ->and($html)->not->toContain('alert');
});

test('existing lesson code does not modify the existing lesson', function () {
    $teacher = asTeacher();
    $existing = app(LessonAuthoringService::class)->create([
        'code' => 'KEEP-CODE-1',
        'title' => 'Keep me',
        'pages' => [],
    ], $teacher);

    $beforeTitle = $existing->title;
    $package = importMinimalPackage();
    $package['lesson']['code'] = 'KEEP-CODE-1';
    $package['lesson']['title'] = 'Should not win';

    expect(fn () => app(LessonImportService::class)->import($package, $teacher))
        ->toThrow(AuthoringValidationException::class);

    expect($existing->fresh()->title)->toBe($beforeTitle)
        ->and(Lesson::query()->where('code', 'KEEP-CODE-1')->count())->toBe(1);
});

test('placeholders allow draft review but block publication', function () {
    $teacher = asTeacher();
    $lesson = app(LessonImportService::class)->import(importMinimalPackage(), $teacher)['lesson'];

    expect(fn () => app(LessonAuthoringService::class)->publish($lesson->fresh(), $teacher))
        ->toThrow(AuthoringValidationException::class);

    $image = $lesson->fresh(['pages.blocks'])->pages->first()->blocks->firstWhere('type', 'image');
    $image->forceFill([
        'config' => array_merge($image->config, [
            'url' => 'https://example.com/torch.png',
        ]),
    ])->save();

    // Quiz still needs publish-ready source_ref (already present) and schema;
    // image placeholder was the intentional gate for this test.
    try {
        app(LessonAuthoringService::class)->assertPublishReady($lesson->fresh(['pages.blocks']));
    } catch (AuthoringValidationException $e) {
        expect(implode("\n", $e->errors))->not->toContain('import placeholder');
    }
});

test('source_ref and rubric_html stay out of student manifests', function () {
    $teacher = asTeacher();
    $package = importMinimalPackage();
    $package['pages'][0]['blocks'][] = [
        'key' => 'sr1',
        'type' => 'short_response',
        'prompt_html' => '<p>Explain welding.</p>',
        'placeholder' => 'Your answer',
        'min_length' => 10,
        'rubric_html' => '<p>SECRET_RUBRIC_IMPORT</p>',
    ];
    $package['pages'][0]['completion_type'] = 'submit_required';

    $lesson = app(LessonImportService::class)->import($package, $teacher)['lesson']
        ->fresh(['pages.blocks']);

    // Replace placeholder so we can publish after filling other publish gates.
    $image = $lesson->pages->first()->blocks->firstWhere('type', 'image');
    $image->forceFill([
        'config' => array_merge($image->config, ['url' => 'https://example.com/ok.png']),
    ])->save();

    $version = app(LessonAuthoringService::class)->publish($lesson->fresh(), $teacher);
    $student = app(StudentManifest::class)->forVersion($version);
    $encoded = json_encode($student);

    expect($encoded)->not->toContain('SECRET_RUBRIC_IMPORT')
        ->and($encoded)->not->toContain('source_ref')
        ->and($encoded)->not->toContain('Slide 4');

    $staffQuiz = $lesson->fresh(['pages.blocks'])->pages->firstWhere('title', 'Check')->blocks->first();
    expect($staffQuiz->config['questions'][0]['source_ref']['page'] ?? null)->toBe('Slide 4');
});

test('importing teacher owns the result regardless of json claims', function () {
    $teacher = asTeacher();
    $other = User::factory()->create();
    $other->forceFill(['role' => UserRole::Teacher])->save();

    $package = importMinimalPackage();
    // Forbidden ownership fields must reject — prove actor ownership on a clean package.
    $lesson = app(LessonImportService::class)->import($package, $teacher)['lesson'];
    expect((int) $lesson->created_by_user_id)->toBe((int) $teacher->id)
        ->and((int) $lesson->created_by_user_id)->not->toBe((int) $other->id);

    $claimed = importMinimalPackage();
    $claimed['created_by_user_id'] = $other->id;
    expect(fn () => app(LessonImportService::class)->import($claimed, $teacher))
        ->toThrow(AuthoringValidationException::class);
});

test('imported lessons pass the same authoring validators as hand-authored ones after assets are real', function () {
    $teacher = asTeacher();
    $package = importMinimalPackage();
    // Use a fixture-style URL that LessonScopedAssetUrl and AssetUrl accept.
    $package['pages'][0]['blocks'][1]['url'] = 'https://example.com/diagram.png';

    $lesson = app(LessonImportService::class)->import($package, $teacher)['lesson'];

    expect(fn () => app(LessonAuthoringService::class)->assertPublishReady($lesson->fresh(['pages.blocks'])))
        ->not->toThrow(AuthoringValidationException::class);

    $version = app(LessonAuthoringService::class)->publish($lesson->fresh(), $teacher);
    expect($version->version)->toBe(1)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Published);
});

test('transactional refusal leaves no partial graph even if create were somehow reached', function () {
    $teacher = asTeacher();
    $started = false;

    DB::listen(function () use (&$started) {
        $started = true;
    });

    $package = importMinimalPackage();
    $package['pages'][0]['key'] = '!!!';

    expect(fn () => app(LessonImportService::class)->import($package, $teacher))
        ->toThrow(AuthoringValidationException::class);

    // Validation fails before authoring create — no lesson insert.
    expect(Lesson::query()->where('title', 'Import smoke lesson')->exists())->toBeFalse();
});
