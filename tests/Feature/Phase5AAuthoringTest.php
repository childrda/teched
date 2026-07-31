<?php

use App\Enums\LessonStatus;
use App\Enums\PageCompletionType;
use App\Enums\UserRole;
use App\Exceptions\AuthoringValidationException;
use App\Exceptions\StaleLessonEditException;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\BlockState;
use App\Models\BlockSubmission;
use App\Models\Lesson;
use App\Models\LessonPage;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\LessonAuthoringService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

function ownedLesson(User $owner, array $overrides = []): Lesson
{
    return Lesson::factory()->create(array_merge([
        'created_by_user_id' => $owner->id,
        'updated_by' => $owner->id,
    ], $overrides));
}

function authoringPagePayload(string $title, array $blocks, ?string $pageId = null): array
{
    return [
        'page_id' => $pageId ?? (string) Str::ulid(),
        'title' => $title,
        'completion_type' => PageCompletionType::View->value,
        'estimated_minutes' => null,
        'settings' => LessonPage::DEFAULT_SETTINGS,
        'blocks' => $blocks,
    ];
}

function authoringBlockPayload(string $type, array $config, ?array $grading = null, ?string $blockId = null): array
{
    return [
        'type' => $type,
        'data' => array_merge($config, [
            'block_id' => $blockId ?? (string) Str::ulid(),
            'grading' => $grading,
        ]),
    ];
}

function richTextBlock(?string $blockId = null): array
{
    return authoringBlockPayload('rich_text', [
        'html' => '<p>Hello</p>',
    ], null, $blockId);
}

test('students cannot access the admin panel; teachers and admins can', function () {
    $student = User::factory()->create();
    expect($student->canAccessPanel(filament()->getPanel('admin')))->toBeFalse();

    $teacher = User::factory()->create();
    $teacher->forceFill(['role' => UserRole::Teacher])->save();
    expect($teacher->fresh()->canAccessPanel(filament()->getPanel('admin')))->toBeTrue();

    $admin = User::factory()->create();
    $admin->forceFill(['role' => UserRole::Admin])->save();
    expect($admin->fresh()->canAccessPanel(filament()->getPanel('admin')))->toBeTrue();

    $this->actingAs($student)
        ->get('/admin')
        ->assertForbidden();
});

test('teachers are SQL-scoped to owned lessons plus published library; others drafts are unreachable', function () {
    $owner = asTeacher();
    $other = User::factory()->create();
    $other->forceFill(['role' => UserRole::Teacher])->save();

    $mine = ownedLesson($owner, ['title' => 'Mine']);
    $theirDraft = ownedLesson($other, ['title' => 'Their draft']);
    $theirPublished = ownedLesson($other, [
        'title' => 'Their published',
        'status' => LessonStatus::Published,
        'current_version' => 1,
    ]);

    Auth::login($owner);
    $ids = LessonResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($mine->id)
        ->and($ids)->not->toContain($theirDraft->id)
        ->and($ids)->toContain($theirPublished->id);

    expect(Gate::forUser($owner)->allows('view', $mine))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $theirDraft))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $theirPublished))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $theirPublished))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('publish', $theirPublished))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('archive', $theirPublished))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('unarchive', $theirPublished))->toBeFalse();

    // Draft owned by another teacher stays invisible (404). Published library
    // lessons resolve in the query but edit is refused (403).
    $this->get(LessonResource::getUrl('edit', ['record' => $theirDraft]))
        ->assertNotFound();

    $this->get(LessonResource::getUrl('edit', ['record' => $theirPublished]))
        ->assertForbidden();
});

test('admins can manage every lesson', function () {
    $teacher = User::factory()->create();
    $teacher->forceFill(['role' => UserRole::Teacher])->save();
    $lesson = ownedLesson($teacher);

    $admin = asAdmin();

    expect(Gate::forUser($admin)->allows('view', $lesson))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $lesson))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('publish', $lesson))->toBeTrue();

    Auth::login($admin);
    expect(LessonResource::getEloquentQuery()->pluck('id')->all())->toContain($lesson->id);
});

test('stable page and block ids survive reorder, insert, and edit', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);

    $pageA = (string) Str::ulid();
    $pageB = (string) Str::ulid();
    $block1 = (string) Str::ulid();
    $block2 = (string) Str::ulid();

    $lesson = $service->create([
        'code' => 'TST-5A-1',
        'title' => 'Stable ids',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [
            authoringPagePayload('A', [richTextBlock($block1)], $pageA),
            authoringPagePayload('B', [richTextBlock($block2)], $pageB),
        ],
    ], $teacher);

    $published = $service->publish($lesson->fresh(), $teacher);
    $student = asStudent();
    $resolved = app(AttemptService::class)->resolveForPlayer($student, $lesson->fresh());
    $attempt = $resolved['attempt'];

    BlockState::query()->create([
        'lesson_attempt_id' => $attempt->id,
        'block_id' => $block1,
        'block_type' => 'rich_text',
        'state' => ['note' => 'keep-me'],
    ]);

    asTeacher($teacher);
    // Reorder pages B then A; insert a block on B; edit A — page-scoped APIs.
    $blockMid = (string) Str::ulid();
    $service->reorderPages(
        $lesson->fresh(),
        [$pageB, $pageA],
        $teacher,
        $lesson->fresh()->updated_at->toISOString(),
    );

    $pageBModel = LessonPage::query()->where('page_id', $pageB)->firstOrFail();
    $service->savePage($pageBModel, [
        'updated_at' => $pageBModel->fresh()->updated_at->toISOString(),
        'title' => 'B',
        'completion_type' => PageCompletionType::View->value,
        'settings' => LessonPage::DEFAULT_SETTINGS,
        'blocks' => [
            richTextBlock($block2),
            richTextBlock($blockMid),
        ],
    ], $teacher);

    $pageAModel = LessonPage::query()->where('page_id', $pageA)->firstOrFail();
    $service->savePage($pageAModel, [
        'updated_at' => $pageAModel->fresh()->updated_at->toISOString(),
        'title' => 'A edited',
        'completion_type' => PageCompletionType::View->value,
        'settings' => LessonPage::DEFAULT_SETTINGS,
        'blocks' => [
            authoringBlockPayload('rich_text', ['html' => '<p>Changed</p>'], null, $block1),
        ],
    ], $teacher);

    $fresh = $lesson->fresh(['pages.blocks']);
    $pageIds = $fresh->pages->pluck('page_id')->all();
    $blockIds = $fresh->pages->flatMap->blocks->pluck('block_id')->all();

    expect($pageIds)->toBe([$pageB, $pageA])
        ->and($blockIds)->toContain($block1, $block2, $blockMid)
        ->and($fresh->pages->firstWhere('page_id', $pageA)->blocks->first()->block_id)->toBe($block1)
        ->and($fresh->pages->firstWhere('page_id', $pageA)->blocks->first()->config['html'])->toBe('<p>Changed</p>');

    expect(BlockState::query()->where('block_id', $block1)->exists())->toBeTrue();

    // Pinned attempt still resolves the original published block through its manifest.
    $manifestBlockIds = collect($published->fresh()->manifest['pages'])
        ->flatMap(fn ($p) => collect($p['blocks'])->pluck('block_id'))
        ->all();
    expect($manifestBlockIds)->toContain($block1, $block2)
        ->and($manifestBlockIds)->not->toContain($blockMid);
});

test('removing a block preserves neighbours and does not cascade student rows', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);

    $pageId = (string) Str::ulid();
    $a = (string) Str::ulid();
    $b = (string) Str::ulid();
    $c = (string) Str::ulid();

    $lesson = $service->create([
        'code' => 'TST-5A-2',
        'title' => 'Remove block',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [
            authoringPagePayload('P', [
                richTextBlock($a),
                richTextBlock($b),
                richTextBlock($c),
            ], $pageId),
        ],
    ], $teacher);

    $version = $service->publish($lesson->fresh(), $teacher);
    $student = asStudent();
    $resolved = app(AttemptService::class)->resolveForPlayer($student, $lesson->fresh());
    $attempt = $resolved['attempt'];

    BlockState::query()->create([
        'lesson_attempt_id' => $attempt->id,
        'block_id' => $b,
        'block_type' => 'rich_text',
        'state' => ['x' => 1],
    ]);
    BlockSubmission::query()->create([
        'lesson_attempt_id' => $attempt->id,
        'lesson_version_id' => $version->id,
        'block_id' => $b,
        'block_type' => 'rich_text',
        'response' => ['ok' => true],
        'grading_result' => ['passed' => true],
        'attempt_number' => 1,
        'submitted_at' => now(),
    ]);

    asTeacher($teacher);
    $page = LessonPage::query()->where('page_id', $pageId)->firstOrFail();
    $service->savePage($page, [
        'updated_at' => $page->fresh()->updated_at->toISOString(),
        'title' => 'P',
        'completion_type' => PageCompletionType::View->value,
        'settings' => LessonPage::DEFAULT_SETTINGS,
        'blocks' => [
            richTextBlock($a),
            richTextBlock($c),
        ],
    ], $teacher);

    $ids = $lesson->fresh()->pages->first()->blocks->pluck('block_id')->all();
    expect($ids)->toBe([$a, $c])
        ->and(BlockState::query()->where('block_id', $b)->exists())->toBeTrue()
        ->and(BlockSubmission::query()->where('block_id', $b)->exists())->toBeTrue();

    $oldIds = collect($version->fresh()->manifest['pages'][0]['blocks'])->pluck('block_id')->all();
    expect($oldIds)->toBe([$a, $b, $c]);
});

test('quiz option and cer field reorder preserves nested ids and answer_id', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);

    $pageId = (string) Str::ulid();
    $quizId = (string) Str::ulid();
    $qId = (string) Str::ulid();
    $optA = (string) Str::ulid();
    $optB = (string) Str::ulid();
    $cerId = (string) Str::ulid();
    $fieldClaim = (string) Str::ulid();
    $fieldEvidence = (string) Str::ulid();

    $lesson = $service->create([
        'code' => 'TST-5A-3',
        'title' => 'Nested ids',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [
            authoringPagePayload('P', [
                authoringBlockPayload('quiz', [
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
                ], fullGradingShape(), $quizId),
                authoringBlockPayload('cer', [
                    'scenario_html' => '<p>S</p>',
                    'fields' => [
                        ['id' => $fieldClaim, 'label' => 'Claim', 'placeholder' => null, 'min_length' => null],
                        ['id' => $fieldEvidence, 'label' => 'Evidence', 'placeholder' => null, 'min_length' => null],
                    ],
                ], null, $cerId),
            ], $pageId),
        ],
    ], $teacher);

    $page = LessonPage::query()->where('page_id', $pageId)->firstOrFail();
    $service->savePage($page, [
        'updated_at' => $page->fresh()->updated_at->toISOString(),
        'title' => 'P',
        'completion_type' => PageCompletionType::View->value,
        'settings' => LessonPage::DEFAULT_SETTINGS,
        'blocks' => [
            authoringBlockPayload('quiz', [
                'shuffle_questions' => false,
                'questions' => [[
                    'id' => $qId,
                    'prompt' => 'Q?',
                    'options' => [
                        ['id' => $optB, 'text' => 'B'],
                        ['id' => $optA, 'text' => 'A'],
                    ],
                    'answer_id' => $optA,
                    'feedback' => null,
                    'source_ref' => null,
                ]],
            ], fullGradingShape(), $quizId),
            authoringBlockPayload('cer', [
                'scenario_html' => '<p>S</p>',
                'fields' => [
                    ['id' => $fieldEvidence, 'label' => 'Evidence', 'placeholder' => null, 'min_length' => null],
                    ['id' => $fieldClaim, 'label' => 'Claim', 'placeholder' => null, 'min_length' => null],
                ],
            ], null, $cerId),
        ],
    ], $teacher);

    $quiz = $lesson->fresh()->pages->first()->blocks->firstWhere('block_id', $quizId);
    $cer = $lesson->fresh()->pages->first()->blocks->firstWhere('block_id', $cerId);

    expect($quiz->config['questions'][0]['options'][0]['id'])->toBe($optB)
        ->and($quiz->config['questions'][0]['options'][1]['id'])->toBe($optA)
        ->and($quiz->config['questions'][0]['answer_id'])->toBe($optA)
        ->and($cer->config['fields'][0]['id'])->toBe($fieldEvidence)
        ->and($cer->config['fields'][1]['id'])->toBe($fieldClaim);
});

test('two blocks with identical content keep distinct ids on save', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);
    $pageId = (string) Str::ulid();
    $a = (string) Str::ulid();
    $b = (string) Str::ulid();

    $lesson = $service->create([
        'code' => 'TST-5A-4',
        'title' => 'Identical',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [
            authoringPagePayload('P', [
                authoringBlockPayload('rich_text', ['html' => '<p>Same</p>'], null, $a),
                authoringBlockPayload('rich_text', ['html' => '<p>Same</p>'], null, $b),
            ], $pageId),
        ],
    ], $teacher);

    $page = LessonPage::query()->where('page_id', $pageId)->firstOrFail();
    $state = $service->toPageFormState($page);
    $service->savePage($page, $state, $teacher);

    $ids = $lesson->fresh()->pages->first()->blocks->pluck('block_id')->all();
    expect($ids)->toBe([$a, $b]);
});

test('incomplete draft saves but cannot publish; unsafe config is rejected', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);
    $pageId = (string) Str::ulid();
    $quizId = (string) Str::ulid();

    $lesson = $service->create([
        'code' => 'TST-5A-5',
        'title' => 'Draft quiz',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [
            authoringPagePayload('P', [
                authoringBlockPayload('quiz', [
                    'shuffle_questions' => false,
                    'questions' => [[
                        'id' => (string) Str::ulid(),
                        'prompt' => 'WIP',
                        'options' => [
                            ['id' => (string) Str::ulid(), 'text' => 'Only one'],
                        ],
                        'answer_id' => null,
                        'feedback' => null,
                        'source_ref' => null,
                    ]],
                ], null, $quizId),
            ], $pageId),
        ],
    ], $teacher);

    expect($lesson->pages)->toHaveCount(1);

    expect(fn () => $service->publish($lesson->fresh(), $teacher))
        ->toThrow(AuthoringValidationException::class);

    try {
        $service->publish($lesson->fresh(), $teacher);
    } catch (AuthoringValidationException $e) {
        expect(implode(' ', $e->errors))->toContain('P')
            ->and(implode(' ', $e->errors))->toContain('quiz');
    }

    $page = LessonPage::query()->where('page_id', $pageId)->firstOrFail();
    expect(fn () => $service->savePage($page, [
        'updated_at' => $page->fresh()->updated_at->toISOString(),
        'title' => 'P',
        'completion_type' => PageCompletionType::View->value,
        'settings' => LessonPage::DEFAULT_SETTINGS,
        'blocks' => [
            authoringBlockPayload('quiz', [
                'shuffle_questions' => false,
                'questions' => 'not-an-array',
                'unknown_key' => true,
            ], null, $quizId),
        ],
    ], $teacher))->toThrow(AuthoringValidationException::class);
});

test('schema cross-reference failures name the page and block', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);
    $pageId = (string) Str::ulid();
    $quizId = (string) Str::ulid();
    $qId = (string) Str::ulid();
    $opt = (string) Str::ulid();

    // Valid enough for draft save, but answer_id points at a missing option —
    // validateConfig catches this with an addressed message.
    $lesson = $service->create([
        'code' => 'TST-5A-6',
        'title' => 'Bad answer',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [
            authoringPagePayload('Quiz page', [
                authoringBlockPayload('quiz', [
                    'shuffle_questions' => false,
                    'questions' => [[
                        'id' => $qId,
                        'prompt' => 'Q?',
                        'options' => [
                            ['id' => $opt, 'text' => 'A'],
                            ['id' => (string) Str::ulid(), 'text' => 'B'],
                        ],
                        'answer_id' => 'missing-option',
                        'feedback' => null,
                        'source_ref' => null,
                    ]],
                ], fullGradingShape(), $quizId),
            ], $pageId),
        ],
    ], $teacher);

    try {
        $service->publish($lesson->fresh(), $teacher);
        expect(false)->toBeTrue('expected publish to fail');
    } catch (AuthoringValidationException $e) {
        $joined = implode("\n", $e->errors);
        expect($joined)->toContain('Quiz page')
            ->and($joined)->toContain('answer_id');
    }
});

test('stale authoring form cannot overwrite a newer save', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);

    $lesson = $service->create([
        'code' => 'TST-5A-7',
        'title' => 'Concurrency',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [authoringPagePayload('P', [richTextBlock()])],
    ], $teacher);

    $stale = $service->toFormState($lesson->fresh());

    // Advance before the first write so its updated_at differs from the stale token.
    $this->travel(2)->seconds();

    $service->save($lesson->fresh(), array_merge($stale, [
        'title' => 'First writer',
        'updated_at' => $stale['updated_at'],
    ]), $teacher);

    expect(fn () => $service->save($lesson->fresh(), array_merge($stale, [
        'title' => 'Second writer',
    ]), $teacher))->toThrow(StaleLessonEditException::class);

    expect($lesson->fresh()->title)->toBe('First writer');
});

test('failed nested save rolls back page and block order', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);
    $pageId = (string) Str::ulid();
    $a = (string) Str::ulid();
    $b = (string) Str::ulid();

    $lesson = $service->create([
        'code' => 'TST-5A-8',
        'title' => 'Rollback',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [
            authoringPagePayload('P', [richTextBlock($a), richTextBlock($b)], $pageId),
        ],
    ], $teacher);

    $beforePages = $lesson->fresh()->pages->pluck('page_id')->all();
    $beforeBlocks = $lesson->fresh()->pages->first()->blocks->pluck('block_id')->all();
    $page = LessonPage::query()->where('page_id', $pageId)->firstOrFail();
    $titleBefore = $page->title;

    try {
        $service->savePage($page, [
            'updated_at' => $page->fresh()->updated_at->toISOString(),
            'title' => 'Should rollback',
            'completion_type' => PageCompletionType::View->value,
            'settings' => LessonPage::DEFAULT_SETTINGS,
            'blocks' => [
                richTextBlock($b),
                authoringBlockPayload('quiz', [
                    'not_a_real_key' => true,
                    'questions' => [],
                ], null, $a),
            ],
        ], $teacher);
    } catch (AuthoringValidationException) {
        // expected
    }

    $fresh = $lesson->fresh();
    expect($fresh->title)->toBe('Rollback')
        ->and($fresh->pages->pluck('page_id')->all())->toBe($beforePages)
        ->and($fresh->pages->first()->title)->toBe($titleBefore)
        ->and($fresh->pages->first()->blocks->pluck('block_id')->all())->toBe($beforeBlocks);
});

test('publish always creates a new version; edit leaves live version byte-identical', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);

    $lesson = $service->create([
        'code' => 'TST-5A-9',
        'title' => 'Publish twice',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'learning_target' => 'Learn',
        'success_criteria' => ['one'],
        'pages' => [
            authoringPagePayload('P', [
                authoringBlockPayload('rich_text', ['html' => '<p>Hi</p>']),
            ]),
        ],
    ], $teacher);

    $v1 = $service->publish($lesson->fresh(), $teacher);
    expect($lesson->fresh()->current_version)->toBe(1);

    $manifestBefore = json_encode($v1->fresh()->manifest);

    $form = $service->toFormState($lesson->fresh());
    $service->save($lesson->fresh(), array_merge($form, [
        'title' => 'Edited title',
        'updated_at' => $lesson->fresh()->updated_at->toISOString(),
    ]), $teacher);

    expect(json_encode($v1->fresh()->manifest))->toBe($manifestBefore)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Published)
        ->and($lesson->fresh()->has_unpublished_changes)->toBeTrue();

    $v2 = $service->publish($lesson->fresh(), $teacher);
    expect($v2->version)->toBe(2)
        ->and($lesson->fresh()->current_version)->toBe(2);

    // Second publish with no authoring changes still mints a version.
    $v3 = $service->publish($lesson->fresh(), $teacher);
    expect($v3->version)->toBe(3);
});

test('archived lessons cannot publish but remain editable; unarchive then publish works', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);

    $lesson = $service->create([
        'code' => 'TST-5A-10',
        'title' => 'Archive me',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'pages' => [authoringPagePayload('P', [richTextBlock()])],
    ], $teacher);

    $service->publish($lesson->fresh(), $teacher);
    $service->archive($lesson->fresh(), $teacher);

    expect($lesson->fresh()->status)->toBe(LessonStatus::Archived)
        ->and(Gate::forUser($teacher)->allows('publish', $lesson->fresh()))->toBeFalse()
        ->and(Gate::forUser($teacher)->allows('update', $lesson->fresh()))->toBeTrue();

    expect(fn () => $service->publish($lesson->fresh(), $teacher))
        ->toThrow(AuthoringValidationException::class);

    $form = $service->toFormState($lesson->fresh());
    $service->save($lesson->fresh(), array_merge($form, [
        'title' => 'Still editable',
        'updated_at' => $lesson->fresh()->updated_at->toISOString(),
    ]), $teacher);
    expect($lesson->fresh()->title)->toBe('Still editable');

    $service->unarchive($lesson->fresh(), $teacher);
    expect($lesson->fresh()->status)->toBe(LessonStatus::Published);

    $version = $service->publish($lesson->fresh(), $teacher);
    expect($version->version)->toBeGreaterThan(1);
});

test('default_allow_read_aloud seeds new pages only', function () {
    $teacher = asTeacher();
    $service = app(LessonAuthoringService::class);
    $pageId = (string) Str::ulid();

    $lesson = $service->create([
        'code' => 'TST-5A-11',
        'title' => 'Read aloud default',
        'settings' => ['default_allow_read_aloud' => false],
        'pages' => [
            authoringPagePayload('Existing', [richTextBlock()], $pageId),
        ],
    ], $teacher);

    // Flip the lesson default; existing page must keep its own setting.
    $existing = $lesson->fresh()->pages->first();
    $existing->forceFill([
        'settings' => array_merge($existing->settings, ['allow_read_aloud' => true]),
    ])->save();

    $form = $service->toFormState($lesson->fresh());
    $service->save($lesson->fresh(), array_merge($form, [
        'settings' => ['default_allow_read_aloud' => false],
        'updated_at' => $lesson->fresh()->updated_at->toISOString(),
    ]), $teacher);

    $service->createPage($lesson->fresh(), $teacher, $lesson->fresh()->updated_at->toISOString(), [
        'title' => 'Brand new',
        // Omit allow_read_aloud so createPage seeds from lesson default.
        'settings' => [
            'minimum_score' => null,
            'require_all_blocks' => false,
            'allow_back_navigation' => true,
            'allow_skip' => false,
            'show_in_nav' => true,
        ],
    ]);

    $fresh = $lesson->fresh()->pages()->orderBy('position')->get();
    expect($fresh[0]->settings['allow_read_aloud'])->toBeTrue()
        ->and($fresh[1]->settings['allow_read_aloud'])->toBeFalse();
});

test('player vite entry points remain unchanged when the admin theme is added', function () {
    $vite = file_get_contents(base_path('vite.config.js'));
    $appJs = file_get_contents(base_path('resources/js/app.js'));

    // Player entries stay; the Filament admin theme is an additional CSS entry only.
    expect($vite)->toContain("resources/css/app.css")
        ->and($vite)->toContain("resources/js/app.js")
        ->and($vite)->toContain("resources/js/authoring/hotspot-editor-register.js")
        ->and($vite)->toContain("resources/css/filament/admin/theme.css")
        ->and($appJs)->toContain('./lesson-player/player')
        ->and($appJs)->not->toContain('filament')
        ->and(file_exists(base_path('resources/js/lesson-player/player.js')))->toBeTrue();
});
