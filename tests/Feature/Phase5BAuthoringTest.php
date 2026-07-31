<?php

use App\Enums\UserRole;
use App\Exceptions\AuthoringValidationException;
use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Filament\Resources\Lessons\Resources\LessonPages\Pages\EditLessonPage;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\LessonBlock;
use App\Models\LessonOwnerChange;
use App\Models\LessonPage;
use App\Models\LessonVersion;
use App\Models\User;
use App\Services\LessonAuthoringService;
use App\Services\LessonCompiler;
use App\Services\LessonContentDuplicator;
use App\Services\LessonPublisher;
use App\Services\StudentManifest;
use App\Support\PlayerCapabilities;
use Database\Seeders\UserSeeder;
use Database\Seeders\WeldingLessonSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;

function phase5bOwnedLesson(User $owner): Lesson
{
    $service = app(LessonAuthoringService::class);

    return $service->create([
        'code' => 'P5B-'.Str::upper(Str::random(6)),
        'title' => 'Phase 5B lesson',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'learning_target' => 'Learn',
        'success_criteria' => ['one'],
        'pages' => [[
            'page_id' => (string) Str::ulid(),
            'title' => 'Page one',
            'completion_type' => 'view',
            'settings' => LessonPage::DEFAULT_SETTINGS,
            'blocks' => [[
                'type' => 'rich_text',
                'data' => [
                    'block_id' => (string) Str::ulid(),
                    'html' => '<p>Hello</p>',
                    'grading' => null,
                ],
            ]],
        ]],
    ], $owner);
}

test('filament list create and edit pages render', function () {
    $teacher = asTeacher();
    // Every registered type with populated defaultConfig — Select option
    // closures (matching / quiz / image_labeling) must resolve without TypeError.
    $lesson = createOwnedLessonWithAllBlockTypes($teacher);

    Livewire::actingAs($teacher)
        ->test(ListLessons::class)
        ->assertSuccessful();

    Livewire::actingAs($teacher)
        ->test(CreateLesson::class)
        ->assertSuccessful();

    $lessonEdit = Livewire::actingAs($teacher)
        ->test(EditLesson::class, ['record' => $lesson->getKey()])
        ->assertSuccessful();

    // Lesson screen: pages table, no block Builder.
    $lessonHtml = $lessonEdit->html();
    expect(str_contains($lessonHtml, 'wire:id'))->toBeTrue()
        ->and(str_contains($lessonHtml, 'Add page') || str_contains($lessonHtml, 'Pages'))->toBeTrue()
        ->and(str_contains($lessonHtml, 'Term A'))->toBeFalse();

    $page = $lesson->pages()->whereHas('blocks', fn ($q) => $q->where('type', 'matching'))->first()
        ?? $lesson->pages()->first();

    $pageEdit = Livewire::actingAs($teacher)
        ->test(EditLessonPage::class, [
            'record' => $page->getKey(),
            'parentRecord' => $lesson,
        ])
        ->assertSuccessful();

    // Avoid Livewire assertSee — failure dumps the full Filament HTML and
    // hangs PHPUnit on Windows. Check needles with a short expect message.
    $html = $pageEdit->html();
    foreach (['Term A', 'Correct answer', 'Label'] as $needle) {
        expect(str_contains($html, $needle))->toBeTrue("EditLessonPage HTML missing \"{$needle}\"");
    }
});

test('filament edit page renders the seeded WEL lesson with every interaction type', function () {
    $this->seed(WeldingLessonSeeder::class);
    $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
    $teacher = User::query()->findOrFail($lesson->created_by_user_id);

    $lessonEdit = Livewire::actingAs($teacher)
        ->test(EditLesson::class, ['record' => $lesson->getKey()])
        ->assertSuccessful();

    // Compact pages table — no block Builder on the lesson screen.
    $lessonHtml = $lessonEdit->html();
    expect(str_contains($lessonHtml, 'fi-fo-builder'))->toBeFalse('Lesson edit must not embed the block Builder');

    $matchingPage = $lesson->pages()
        ->whereHas('blocks', fn ($q) => $q->where('type', 'matching'))
        ->firstOrFail();

    $pageEdit = Livewire::actingAs($teacher)
        ->test(EditLessonPage::class, [
            'record' => $matchingPage->getKey(),
            'parentRecord' => $lesson,
        ])
        ->assertSuccessful();

    // Bank labels in HTML alone are a false positive — they also appear in
    // bank TextInputs. Assert resolved Select options instead (below).
    expect($pageEdit->html())->toContain('Correct bank item');
});

/**
 * Find the first answer_id Select under a named repeater (hotspots / slots).
 */
function phase5bAnswerSelect(mixed $livewire, string $repeater): ?Select
{
    $schema = $livewire->instance()->form;
    $fields = $schema->getFlatFields(withHidden: true, withAbsoluteKeys: true);

    foreach ($fields as $field) {
        if (! $field instanceof Select) {
            continue;
        }
        $path = (string) $field->getStatePath();
        if (str_contains($path, ".{$repeater}.") && str_ends_with($path, '.answer_id')) {
            return $field;
        }
    }

    return null;
}

/**
 * Expected bank id → label from Livewire form state for the block that owns $select.
 *
 * @return array<string, string>
 */
function phase5bBankOptionMap(mixed $livewire, Select $select): array
{
    $bankPath = $select->resolveRelativeStatePath('../../bank');
    $bank = data_get($livewire->get('data'), str($bankPath)->after('data.')->toString())
        ?? data_get($livewire->instance(), $bankPath)
        ?? [];

    // Livewire state is under the form's statePath ('data'); absolute paths may
    // already include that prefix depending on Filament version.
    if ($bank === [] || $bank === null) {
        $bank = data_get($livewire->instance(), $bankPath) ?? [];
    }
    if (($bank === [] || $bank === null) && str_starts_with($bankPath, 'data.')) {
        $bank = data_get($livewire->get('data'), substr($bankPath, strlen('data.'))) ?? [];
    }

    return collect(is_array($bank) ? $bank : [])
        ->filter(fn ($item) => is_array($item) && filled($item['id'] ?? null))
        ->mapWithKeys(fn (array $item) => [$item['id'] => $item['label'] ?: $item['id']])
        ->all();
}

test('image_labeling hotspot answer_id select resolves bank labels by id not via ../bank', function () {
    $teacher = asTeacher();
    $lesson = createOwnedLessonWithAllBlockTypes($teacher);
    $page = $lesson->pages()
        ->whereHas('blocks', fn ($q) => $q->where('type', 'image_labeling'))
        ->firstOrFail();

    $lw = Livewire::actingAs($teacher)
        ->test(EditLessonPage::class, [
            'record' => $page->getKey(),
            'parentRecord' => $lesson,
        ])
        ->assertSuccessful();

    $select = phase5bAnswerSelect($lw, 'hotspots');
    expect($select)->toBeInstanceOf(Select::class);

    // Lock the relative depth: one ../ lands inside the hotspots repeater;
    // two reach block data where bank lives. If Filament nesting changes,
    // these path assertions fail before a silent empty-options regression.
    $oneUp = $select->resolveRelativeStatePath('../bank');
    $twoUp = $select->resolveRelativeStatePath('../../bank');
    expect($oneUp)->toMatch('/\.hotspots\.bank$/')
        ->and($twoUp)->toMatch('/\.data\.bank$/')
        ->and($twoUp)->not->toContain('.hotspots.bank');

    $options = $select->getOptions();
    $expected = phase5bBankOptionMap($lw, $select);

    expect($options)->not->toBeEmpty()
        ->and($expected)->not->toBeEmpty()
        ->and($options)->toEqual($expected)
        ->and(array_values($options))->toContain('Label');
});

test('matching slot answer_id select resolves bank labels by id not via ../bank', function () {
    $teacher = asTeacher();
    $lesson = createOwnedLessonWithAllBlockTypes($teacher);
    $page = $lesson->pages()
        ->whereHas('blocks', fn ($q) => $q->where('type', 'matching'))
        ->firstOrFail();

    $lw = Livewire::actingAs($teacher)
        ->test(EditLessonPage::class, [
            'record' => $page->getKey(),
            'parentRecord' => $lesson,
        ])
        ->assertSuccessful();

    $select = phase5bAnswerSelect($lw, 'slots');
    expect($select)->toBeInstanceOf(Select::class);

    $oneUp = $select->resolveRelativeStatePath('../bank');
    $twoUp = $select->resolveRelativeStatePath('../../bank');
    expect($oneUp)->toMatch('/\.slots\.bank$/')
        ->and($twoUp)->toMatch('/\.data\.bank$/')
        ->and($twoUp)->not->toContain('.slots.bank');

    $options = $select->getOptions();
    $expected = phase5bBankOptionMap($lw, $select);

    expect($options)->not->toBeEmpty()
        ->and($expected)->not->toBeEmpty()
        ->and($options)->toEqual($expected)
        ->and(array_values($options))->toContain('Term A')
        ->and(array_values($options))->toContain('Term B');
});

test('app/Filament never imports the Filament 3/4 Forms Get or Set utilities', function () {
    $root = app_path('Filament');
    $hits = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if (str_contains($contents, 'Filament\\Forms\\Get')
            || str_contains($contents, 'Filament\\Forms\\Set')) {
            $hits[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($hits)->toBeEmpty(
        'Filament 5 Get/Set live in Filament\\Schemas\\Components\\Utilities. Offending imports: '
        .implode(', ', $hits)
    );
});

test('user seeder creates a permanent admin account', function () {
    $this->seed(UserSeeder::class);

    $admin = User::query()->where('email', UserSeeder::ADMIN_EMAIL)->first();
    expect($admin)->not->toBeNull()
        ->and($admin->role)->toBe(UserRole::Admin);
});

test('compiler output is byte-identical to what publish stores', function () {
    $teacher = asTeacher();
    $lesson = phase5bOwnedLesson($teacher);
    $compiler = app(LessonCompiler::class);

    $compiled = $compiler->compileManifest($lesson->fresh(), 1);
    $version = app(LessonPublisher::class)->publish($lesson->fresh(), $teacher);

    expect(json_encode($compiled))->toBe(json_encode($version->fresh()->manifest))
        ->and(method_exists(LessonAuthoringService::class, 'dryCompileManifest'))->toBeFalse();
});

test('redaction works for published and preview manifests from the same fixture', function () {
    $this->seed(WeldingLessonSeeder::class);
    $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
    $studentManifest = app(StudentManifest::class);
    $compiler = app(LessonCompiler::class);

    $published = $studentManifest->forVersion($lesson->currentVersion());
    $preview = $studentManifest->redactCompiledManifest(
        $compiler->compileManifest($lesson, max(1, (int) $lesson->current_version))
    );

    assertNoForbiddenKeys($published);
    assertNoForbiddenKeys($preview);
    expect($published)->toHaveKey('grading_token')
        ->and($preview)->not->toHaveKey('grading_token');
});

test('preview renders for owner, forbids others, creates no version or attempt', function () {
    $owner = asTeacher();
    $lesson = phase5bOwnedLesson($owner);
    $versionsBefore = LessonVersion::query()->count();
    $attemptsBefore = LessonAttempt::query()->count();

    $response = $this->actingAs($owner)
        ->get(route('authoring.lessons.preview', $lesson))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('Previewing your last saved draft', false);

    expect(strtolower((string) $response->headers->get('Cache-Control')))->toContain('no-store')
        ->and(LessonVersion::query()->count())->toBe($versionsBefore)
        ->and(LessonAttempt::query()->count())->toBe($attemptsBefore);

    $other = User::factory()->create();
    $other->forceFill(['role' => UserRole::Teacher])->save();
    $this->actingAs($other)
        ->get(route('authoring.lessons.preview', $lesson))
        ->assertForbidden();

    auth()->logout();
    $this->get(route('authoring.lessons.preview', $lesson))
        ->assertRedirect(route('login'));
});

test('preview response embeds preview capabilities and no grading token', function () {
    $owner = asTeacher();
    $lesson = phase5bOwnedLesson($owner);

    $html = $this->actingAs($owner)
        ->get(route('authoring.lessons.preview', $lesson))
        ->assertOk()
        ->getContent();

    // @js() unicode-escapes quotes in the HTML source.
    expect($html)->not->toContain('grading_token')
        ->and($html)->toContain('canPersist\u0022:false')
        ->and($html)->toContain('canGrade\u0022:false')
        ->and($html)->toContain('bypassCompletionGates\u0022:true')
        ->and($html)->not->toContain('answer_id');
});

test('player capabilities schemas match for play and preview', function () {
    expect(array_keys(PlayerCapabilities::forPlay()))
        ->toEqual(array_keys(PlayerCapabilities::forPreview()))
        ->and(PlayerCapabilities::forPlay()['canPersist'])->toBeTrue()
        ->and(PlayerCapabilities::forPreview()['canPersist'])->toBeFalse();
});

test('duplicating a lesson remaps nested answer ids and inserts only after validation', function () {
    $teacher = asTeacher();
    $optA = (string) Str::ulid();
    $optB = (string) Str::ulid();
    $qId = (string) Str::ulid();
    $service = app(LessonAuthoringService::class);

    $lesson = $service->create([
        'code' => 'DUP-SRC',
        'title' => 'Source',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'learning_target' => 't',
        'success_criteria' => ['a'],
        'pages' => [[
            'page_id' => (string) Str::ulid(),
            'title' => 'Quiz page',
            'completion_type' => 'view',
            'settings' => LessonPage::DEFAULT_SETTINGS,
            'blocks' => [[
                'type' => 'quiz',
                'data' => [
                    'block_id' => (string) Str::ulid(),
                    'shuffle_questions' => false,
                    'questions' => [[
                        'id' => $qId,
                        'prompt' => 'Q?',
                        'options' => [
                            ['id' => $optA, 'text' => 'A'],
                            ['id' => $optB, 'text' => 'B'],
                        ],
                        'answer_id' => $optA,
                        'feedback' => null,
                        'source_ref' => null,
                    ]],
                    'grading' => fullGradingShape(),
                ],
            ]],
        ]],
    ], $teacher);

    $pagesBefore = LessonPage::query()->count();
    $blocksBefore = LessonBlock::query()->count();
    $lessonsBefore = Lesson::query()->count();

    $copy = app(LessonContentDuplicator::class)->duplicateLesson($lesson->fresh(), $teacher);

    expect($copy->status->value)->toBe('draft')
        ->and($copy->created_by_user_id)->toBe($teacher->id)
        ->and($copy->current_version)->toBe(0)
        ->and($copy->versions()->count())->toBe(0)
        ->and($copy->code)->not->toBe($lesson->code);

    $srcIds = $lesson->fresh()->pages->flatMap->blocks->pluck('block_id')->all();
    $copyIds = $copy->pages->flatMap->blocks->pluck('block_id')->all();
    expect(array_intersect($srcIds, $copyIds))->toBeEmpty();

    $copyQuiz = $copy->pages->first()->blocks->first();
    $answer = $copyQuiz->config['questions'][0]['answer_id'];
    $optionIds = array_column($copyQuiz->config['questions'][0]['options'], 'id');
    expect($optionIds)->toContain($answer)
        ->and($answer)->not->toBe($optA)
        ->and($optionIds)->not->toContain($optA);

    // Invalid duplicate inserts nothing.
    $bad = Lesson::factory()->create(['created_by_user_id' => $teacher->id]);
    $page = LessonPage::factory()->create(['lesson_id' => $bad->id]);
    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'type' => 'quiz',
        'config' => ['questions' => []],
        'grading' => null,
    ]);

    $lessonsMid = Lesson::query()->count();
    $pagesMid = LessonPage::query()->count();
    $blocksMid = LessonBlock::query()->count();

    expect(fn () => app(LessonContentDuplicator::class)->duplicateLesson($bad->fresh(), $teacher))
        ->toThrow(AuthoringValidationException::class);

    expect(Lesson::query()->count())->toBe($lessonsMid)
        ->and(LessonPage::query()->count())->toBe($pagesMid)
        ->and(LessonBlock::query()->count())->toBe($blocksMid)
        ->and(Lesson::query()->count())->toBeGreaterThan($lessonsBefore)
        ->and(LessonPage::query()->count())->toBeGreaterThan($pagesBefore)
        ->and(LessonBlock::query()->count())->toBeGreaterThan($blocksBefore);
});

test('save collects errors across multiple blocks and writes nothing', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);
    $lesson = phase5bOwnedLesson($teacher);
    $page = $lesson->pages->first();
    $blockId = $page->blocks->first()->block_id;
    $pageTitleBefore = $page->title;

    try {
        $service->savePage($page, [
            'updated_at' => $page->fresh()->updated_at->toISOString(),
            'title' => 'Multi error page',
            'completion_type' => 'view',
            'settings' => LessonPage::DEFAULT_SETTINGS,
            'blocks' => [
                [
                    'type' => 'quiz',
                    'data' => [
                        'block_id' => $blockId,
                        'unknown_key' => true,
                        'questions' => 'bad',
                        'grading' => null,
                    ],
                ],
                [
                    'type' => 'matching',
                    'data' => [
                        'block_id' => (string) Str::ulid(),
                        'also_unknown' => 1,
                        'bank' => 'nope',
                        'grading' => null,
                    ],
                ],
                [
                    'type' => 'cer',
                    'data' => [
                        'block_id' => (string) Str::ulid(),
                        'bad_field' => true,
                        'fields' => 'x',
                        'grading' => null,
                    ],
                ],
            ],
        ], $teacher);
        expect(false)->toBeTrue('expected validation failure');
    } catch (AuthoringValidationException $e) {
        $joined = implode("\n", $e->errors);
        expect($joined)->toContain('quiz')
            ->and($joined)->toContain('matching')
            ->and($joined)->toContain('cer')
            ->and($joined)->toContain('Multi error page');
    }

    expect($page->fresh()->title)->toBe($pageTitleBefore);
});

test('admin can reassign owner with manual audit; owner cannot', function () {
    $this->seed(UserSeeder::class);
    $owner = User::query()->where('email', UserSeeder::TEACHER_EMAIL)->firstOrFail();
    $admin = User::query()->where('email', UserSeeder::ADMIN_EMAIL)->firstOrFail();
    $other = User::factory()->create();
    $other->forceFill(['role' => UserRole::Teacher])->save();

    $lesson = phase5bOwnedLesson($owner);

    expect(Gate::forUser($owner)->allows('reassignOwner', $lesson))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('reassignOwner', $lesson))->toBeTrue();

    app(LessonAuthoringService::class)->reassignOwner($lesson->fresh(), $other, $admin);

    expect($lesson->fresh()->created_by_user_id)->toBe($other->id);

    $audit = LessonOwnerChange::query()->where('lesson_id', $lesson->id)->where('source', 'manual')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->changed_by_user_id)->toBe($admin->id)
        ->and($audit->previous_owner_user_id)->toBe($owner->id)
        ->and($audit->new_owner_user_id)->toBe($other->id);

    expect(fn () => $audit->update(['source' => 'hack']))->toThrow(\App\Exceptions\ImmutableLessonOwnerChangeException::class);
});

test('created_by_user_id is non-nullable after ownership migration', function () {
    expect(\Illuminate\Support\Facades\Schema::getColumnType('lessons', 'created_by_user_id'))
        ->not->toBeNull();

    $nullable = collect(\Illuminate\Support\Facades\DB::select('SHOW COLUMNS FROM lessons WHERE Field = ?', ['created_by_user_id']))
        ->first();

    expect(strtolower($nullable->Null))->toBe('no');
});
