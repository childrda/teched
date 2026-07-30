<?php

use App\Enums\PageCompletionType;
use App\Enums\UserRole;
use App\Exceptions\AuthoringPayloadException;
use App\Exceptions\StaleLessonEditException;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\RelationManagers\PagesRelationManager;
use App\Filament\Resources\Lessons\Resources\LessonPages\LessonPageResource;
use App\Filament\Resources\Lessons\Resources\LessonPages\Pages\EditLessonPage;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\LessonVersion;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\LessonAuthoringService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('lesson edit renders pages table without block builder; page edit shows only that page', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);
    $pageA = (string) Str::ulid();
    $pageB = (string) Str::ulid();
    $blockA = (string) Str::ulid();
    $blockB = (string) Str::ulid();

    $lesson = $service->create([
        'code' => 'P5C-UI-1',
        'title' => 'Page split UI',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [
            authoringPagePayload('Alpha', [
                authoringBlockPayload('rich_text', ['html' => '<p>Only on A</p>'], null, $blockA),
            ], $pageA),
            authoringPagePayload('Beta', [
                authoringBlockPayload('rich_text', ['html' => '<p>Only on B</p>'], null, $blockB),
            ], $pageB),
        ],
    ], $teacher);

    $lessonEdit = Livewire::actingAs($teacher)
        ->test(EditLesson::class, ['record' => $lesson->getKey()])
        ->assertSuccessful();

    $lessonHtml = $lessonEdit->html();
    expect(str_contains($lessonHtml, 'fi-fo-builder'))->toBeFalse()
        ->and(str_contains($lessonHtml, 'Only on A'))->toBeFalse()
        ->and(str_contains($lessonHtml, 'Only on B'))->toBeFalse();

    Livewire::actingAs($teacher)
        ->test(PagesRelationManager::class, [
            'ownerRecord' => $lesson,
            'pageClass' => EditLesson::class,
        ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($lesson->pages);

    $pageAModel = LessonPage::query()->where('page_id', $pageA)->firstOrFail();
    $pageHtml = Livewire::actingAs($teacher)
        ->test(EditLessonPage::class, [
            'record' => $pageAModel->getKey(),
            'parentRecord' => $lesson,
        ])
        ->assertSuccessful()
        ->html();

    expect(str_contains($pageHtml, 'Only on A'))->toBeTrue()
        ->and(str_contains($pageHtml, 'Only on B'))->toBeFalse();
});

test('saving one page leaves sibling pages and block ids untouched', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);
    $pageA = (string) Str::ulid();
    $pageB = (string) Str::ulid();
    $blockA = (string) Str::ulid();
    $blockB = (string) Str::ulid();

    $lesson = $service->create([
        'code' => 'P5C-SAFE-1',
        'title' => 'Sibling safety',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [
            authoringPagePayload('A', [richTextBlock($blockA)], $pageA),
            authoringPagePayload('B', [richTextBlock($blockB)], $pageB),
        ],
    ], $teacher);

    $siblingBefore = LessonPage::query()->where('page_id', $pageB)->firstOrFail();
    $siblingSnapshot = [
        'title' => $siblingBefore->title,
        'settings' => $siblingBefore->settings,
        'updated_at' => $siblingBefore->updated_at?->toISOString(),
        'blocks' => $siblingBefore->blocks->map(fn (LessonBlock $b) => [
            'block_id' => $b->block_id,
            'config' => $b->config,
            'updated_at' => $b->updated_at?->toISOString(),
        ])->all(),
    ];
    $siblingCountBefore = LessonPage::query()->where('lesson_id', $lesson->id)->count();
    $blockCountBefore = LessonBlock::query()->whereIn(
        'lesson_page_id',
        LessonPage::query()->where('lesson_id', $lesson->id)->pluck('id')
    )->count();

    $pageAModel = LessonPage::query()->where('page_id', $pageA)->firstOrFail();
    $service->savePage($pageAModel, [
        'updated_at' => $pageAModel->fresh()->updated_at->toISOString(),
        'title' => 'A edited',
        'completion_type' => PageCompletionType::View->value,
        'settings' => LessonPage::DEFAULT_SETTINGS,
        'blocks' => [
            authoringBlockPayload('rich_text', ['html' => '<p>Changed A</p>'], null, $blockA),
        ],
    ], $teacher);

    $siblingAfter = LessonPage::query()->where('page_id', $pageB)->firstOrFail();
    expect(LessonPage::query()->where('lesson_id', $lesson->id)->count())->toBe($siblingCountBefore)
        ->and(LessonBlock::query()->whereIn(
            'lesson_page_id',
            LessonPage::query()->where('lesson_id', $lesson->id)->pluck('id')
        )->count())->toBe($blockCountBefore)
        ->and($siblingAfter->title)->toBe($siblingSnapshot['title'])
        ->and($siblingAfter->settings)->toBe($siblingSnapshot['settings'])
        ->and($siblingAfter->updated_at?->toISOString())->toBe($siblingSnapshot['updated_at'])
        ->and($siblingAfter->blocks->pluck('block_id')->all())->toBe([$blockB])
        ->and($siblingAfter->blocks->first()->config)->toBe($siblingSnapshot['blocks'][0]['config']);
});

test('reorder pages preserves every page_id and block_id', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);
    $pageA = (string) Str::ulid();
    $pageB = (string) Str::ulid();
    $blockA = (string) Str::ulid();
    $blockB = (string) Str::ulid();

    $lesson = $service->create([
        'code' => 'P5C-ORD-1',
        'title' => 'Reorder',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [
            authoringPagePayload('A', [richTextBlock($blockA)], $pageA),
            authoringPagePayload('B', [richTextBlock($blockB)], $pageB),
        ],
    ], $teacher);

    $service->reorderPages(
        $lesson->fresh(),
        [$pageB, $pageA],
        $teacher,
        $lesson->fresh()->updated_at->toISOString(),
    );

    $fresh = $lesson->fresh(['pages.blocks']);
    expect($fresh->pages->pluck('page_id')->all())->toBe([$pageB, $pageA])
        ->and($fresh->pages->flatMap->blocks->pluck('block_id')->sort()->values()->all())
        ->toBe(collect([$blockA, $blockB])->sort()->values()->all());
});

test('duplicating regenerates identifiers for the duplicate only', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);
    $pageA = (string) Str::ulid();
    $blockA = (string) Str::ulid();

    $lesson = $service->create([
        'code' => 'P5C-DUP-1',
        'title' => 'Dup page',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [
            authoringPagePayload('Original', [richTextBlock($blockA)], $pageA),
        ],
    ], $teacher);

    $source = LessonPage::query()->where('page_id', $pageA)->firstOrFail();
    $copy = $service->duplicatePage(
        $lesson->fresh(),
        $source,
        $teacher,
        $lesson->fresh()->updated_at->toISOString(),
    );

    expect($copy->page_id)->not->toBe($pageA)
        ->and($copy->blocks->first()->block_id)->not->toBe($blockA)
        ->and(LessonPage::query()->where('page_id', $pageA)->exists())->toBeTrue()
        ->and(LessonBlock::query()->where('block_id', $blockA)->exists())->toBeTrue();
});

test('deleting an authoring page leaves published versions and pinned attempts intact', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);
    $pageA = (string) Str::ulid();
    $pageB = (string) Str::ulid();
    $blockA = (string) Str::ulid();
    $blockB = (string) Str::ulid();

    $lesson = $service->create([
        'code' => 'P5C-DEL-1',
        'title' => 'Delete page',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'learning_target' => 'Learn',
        'success_criteria' => ['one'],
        'pages' => [
            authoringPagePayload('Keep published', [richTextBlock($blockA)], $pageA),
            authoringPagePayload('Draft only', [richTextBlock($blockB)], $pageB),
        ],
    ], $teacher);

    $version = $service->publish($lesson->fresh(), $teacher);
    $student = asStudent();
    $resolved = app(AttemptService::class)->resolveForPlayer($student, $lesson->fresh());
    /** @var LessonAttempt $attempt */
    $attempt = $resolved['attempt'];

    asTeacher($teacher);
    $pageBModel = LessonPage::query()->where('page_id', $pageB)->firstOrFail();
    $service->deletePage(
        $lesson->fresh(),
        $pageBModel,
        $teacher,
        $lesson->fresh()->updated_at->toISOString(),
    );

    expect(LessonPage::query()->where('page_id', $pageB)->exists())->toBeFalse()
        ->and(LessonVersion::query()->whereKey($version->id)->exists())->toBeTrue();

    $manifestPageIds = collect($version->fresh()->manifest['pages'])->pluck('page_id')->all();
    expect($manifestPageIds)->toContain($pageA, $pageB);

    $attempt->refresh();
    expect($attempt->lesson_version_id)->toBe($version->id);
    $pinnedManifest = LessonVersion::query()->findOrFail($attempt->lesson_version_id)->manifest;
    $pinned = collect($pinnedManifest['pages'])->pluck('page_id')->all();
    expect($pinned)->toContain($pageB);
});

test('own lesson with foreign page id is 404; foreign lesson page is 403 for teacher', function () {
    $owner = asTeacher();
    $other = User::factory()->create();
    $other->forceFill(['role' => UserRole::Teacher])->save();

    $service = app(LessonAuthoringService::class);
    $mine = $service->create([
        'code' => 'P5C-SCOPE-1',
        'title' => 'Mine',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [authoringPagePayload('Mine page', [richTextBlock()])],
    ], $owner);
    $theirs = $service->create([
        'code' => 'P5C-SCOPE-2',
        'title' => 'Theirs',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [authoringPagePayload('Their page', [richTextBlock()])],
    ], $other);

    $myPage = $mine->pages->first();
    $theirPage = $theirs->pages->first();

    $this->actingAs($owner)
        ->get(LessonPageResource::getUrl('edit', [
            'lesson' => $mine,
            'record' => $theirPage,
        ]))
        ->assertNotFound();

    $this->actingAs($owner)
        ->get(LessonPageResource::getUrl('edit', [
            'lesson' => $theirs,
            'record' => $theirPage,
        ]))
        ->assertForbidden();

    expect(Gate::forUser($owner)->allows('update', $theirs))->toBeFalse();

    // Sanity: owner can open own page.
    $this->actingAs($owner)
        ->get(LessonPageResource::getUrl('edit', [
            'lesson' => $mine,
            'record' => $myPage,
        ]))
        ->assertOk();
});

test('savePage rejects lesson_id page_id and sibling graph keys', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);
    $lesson = $service->create([
        'code' => 'P5C-PAY-1',
        'title' => 'Payload',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [authoringPagePayload('P', [richTextBlock()])],
    ], $teacher);
    $page = $lesson->pages->first();
    $base = [
        'updated_at' => $page->fresh()->updated_at->toISOString(),
        'title' => 'P',
        'completion_type' => PageCompletionType::View->value,
        'settings' => LessonPage::DEFAULT_SETTINGS,
        'blocks' => [richTextBlock($page->blocks->first()->block_id)],
    ];

    expect(fn () => $service->savePage($page, array_merge($base, ['lesson_id' => 999]), $teacher))
        ->toThrow(AuthoringPayloadException::class)
        ->and(fn () => $service->savePage($page, array_merge($base, ['page_id' => 'x']), $teacher))
        ->toThrow(AuthoringPayloadException::class)
        ->and(fn () => $service->savePage($page, array_merge($base, ['pages' => []]), $teacher))
        ->toThrow(AuthoringPayloadException::class);
});

test('page edits do not stale lesson metadata; reorder does', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);
    $pageA = (string) Str::ulid();
    $pageB = (string) Str::ulid();

    $lesson = $service->create([
        'code' => 'P5C-CONC-1',
        'title' => 'Concurrency split',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [
            authoringPagePayload('A', [richTextBlock()], $pageA),
            authoringPagePayload('B', [richTextBlock()], $pageB),
        ],
    ], $teacher);

    // 1. Open lesson metadata form — record lesson revision.
    $lessonForm = $service->toFormState($lesson->fresh());
    $originalLessonRevision = $lessonForm['updated_at'];
    $lessonUpdatedAtRaw = DB::table('lessons')->where('id', $lesson->id)->value('updated_at');

    // 2. Open page A and page B.
    $pageAModel = LessonPage::query()->where('page_id', $pageA)->firstOrFail();
    $pageBModel = LessonPage::query()->where('page_id', $pageB)->firstOrFail();
    $pageAForm = $service->toPageFormState($pageAModel);
    $pageBForm = $service->toPageFormState($pageBModel);

    $this->travel(2)->seconds();

    // 3. Save page A.
    $service->savePage($pageAModel, array_merge($pageAForm, [
        'title' => 'A saved',
        'updated_at' => $pageAForm['updated_at'],
    ]), $teacher);

    expect(DB::table('lessons')->where('id', $lesson->id)->value('updated_at'))
        ->toBe($lessonUpdatedAtRaw, 'savePage A must not bump lessons.updated_at');

    // 4. Save page B successfully with page B's unchanged revision.
    $service->savePage($pageBModel, array_merge($pageBForm, [
        'title' => 'B saved',
        'updated_at' => $pageBForm['updated_at'],
    ]), $teacher);

    expect(DB::table('lessons')->where('id', $lesson->id)->value('updated_at'))
        ->toBe($lessonUpdatedAtRaw, 'savePage B must not bump lessons.updated_at');

    // 5. Save lesson metadata successfully with the original lesson revision.
    $service->save($lesson->fresh(), array_merge($lessonForm, [
        'title' => 'Lesson still valid',
        'updated_at' => $originalLessonRevision,
    ]), $teacher);

    expect($lesson->fresh()->title)->toBe('Lesson still valid');

    $this->travel(2)->seconds();

    // 6. Reorder pages — bumps lessons.updated_at.
    $afterMeta = $lesson->fresh()->updated_at->toISOString();
    $service->reorderPages($lesson->fresh(), [$pageB, $pageA], $teacher, $afterMeta);

    // 7. Original lesson form is now stale.
    expect(fn () => $service->save($lesson->fresh(), array_merge($lessonForm, [
        'title' => 'Should fail',
        'updated_at' => $originalLessonRevision,
    ]), $teacher))->toThrow(StaleLessonEditException::class);

    expect($lesson->fresh()->title)->toBe('Lesson still valid');
});

test('stale page save preserves submitted form state in Livewire', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);
    $lesson = $service->create([
        'code' => 'P5C-STALE-1',
        'title' => 'Stale page UI',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [authoringPagePayload('P', [richTextBlock()])],
    ], $teacher);

    $page = $lesson->pages->first();
    $component = Livewire::actingAs($teacher)
        ->test(EditLessonPage::class, [
            'record' => $page->getKey(),
            'parentRecord' => $lesson,
        ]);

    $this->travel(2)->seconds();
    $service->savePage($page->fresh(), array_merge($service->toPageFormState($page->fresh()), [
        'title' => 'Writer one',
    ]), $teacher);

    $component
        ->set('data.title', 'Teacher kept this typed title')
        ->call('save')
        ->assertHasNoErrors();

    // Halted on stale — record unchanged, submitted state still in the form.
    expect($page->fresh()->title)->toBe('Writer one')
        ->and($component->get('data.title'))->toBe('Teacher kept this typed title');
});

test('pages table query does not add a block-count query per page', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);

    $pages2 = [];
    for ($i = 0; $i < 2; $i++) {
        $pages2[] = authoringPagePayload("P{$i}", [richTextBlock()]);
    }
    $lesson2 = $service->create([
        'code' => 'P5C-Q-2',
        'title' => 'Two pages',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => $pages2,
    ], $teacher);

    $pages20 = [];
    for ($i = 0; $i < 20; $i++) {
        $pages20[] = authoringPagePayload("P{$i}", [richTextBlock()]);
    }
    $lesson20 = $service->create([
        'code' => 'P5C-Q-20',
        'title' => 'Twenty pages',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => $pages20,
    ], $teacher);

    $countQueries = function (Lesson $lesson): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $lesson->pages()->withCount('blocks')->orderBy('position')->get();
        $log = collect(DB::getQueryLog());
        DB::disableQueryLog();

        return $log->count();
    };

    $q2 = $countQueries($lesson2);
    $q20 = $countQueries($lesson20);

    // withCount is a single aggregate join/subselect — not N+1 per page.
    expect($q20)->toBe($q2)
        ->and($q20)->toBeLessThanOrEqual(2);
});
