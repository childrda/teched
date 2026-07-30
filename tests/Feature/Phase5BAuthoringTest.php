<?php

use App\Enums\UserRole;
use App\Exceptions\AuthoringValidationException;
use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
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

    $edit = Livewire::actingAs($teacher)
        ->test(EditLesson::class, ['record' => $lesson->getKey()])
        ->assertSuccessful();

    // Avoid Livewire assertSee — failure dumps the full Filament HTML and
    // hangs PHPUnit on Windows. Check needles with a short expect message.
    $html = $edit->html();
    foreach (['Term A', 'Correct answer', 'Label'] as $needle) {
        expect(str_contains($html, $needle))->toBeTrue("EditLesson HTML missing \"{$needle}\"");
    }
});

test('filament edit page renders the seeded WEL lesson with every interaction type', function () {
    $this->seed(WeldingLessonSeeder::class);
    $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
    $teacher = User::query()->findOrFail($lesson->created_by_user_id);

    $edit = Livewire::actingAs($teacher)
        ->test(EditLesson::class, ['record' => $lesson->getKey()])
        ->assertSuccessful();

    $html = $edit->html();
    foreach (['Electrode', 'Weld Pool'] as $needle) {
        expect(str_contains($html, $needle))->toBeTrue("WEL EditLesson HTML missing \"{$needle}\"");
    }
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
    $pageId = $lesson->pages->first()->page_id;
    $blockId = $lesson->pages->first()->blocks->first()->block_id;
    $titleBefore = $lesson->title;

    try {
        $service->save($lesson->fresh(), [
            'code' => $lesson->code,
            'title' => 'Should not persist',
            'settings' => $lesson->settings,
            'updated_at' => $lesson->fresh()->updated_at->toISOString(),
            'pages' => [[
                'page_id' => $pageId,
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
            ]],
        ], $teacher);
        expect(false)->toBeTrue('expected validation failure');
    } catch (AuthoringValidationException $e) {
        $joined = implode("\n", $e->errors);
        expect($joined)->toContain('quiz')
            ->and($joined)->toContain('matching')
            ->and($joined)->toContain('cer')
            ->and($joined)->toContain('Multi error page');
    }

    expect($lesson->fresh()->title)->toBe($titleBefore);
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
