<?php

namespace App\Services;

use App\Blocks\BlockTypeRegistry;
use App\Enums\LessonStatus;
use App\Enums\PageCompletionType;
use App\Exceptions\AuthoringPayloadException;
use App\Exceptions\AuthoringValidationException;
use App\Exceptions\StaleLessonEditException;
use App\Exceptions\StalePageEditException;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonOwnerChange;
use App\Models\LessonPage;
use App\Models\User;
use App\Services\Authoring\AuthoringErrorFormatter;
use App\Services\Authoring\DraftConfigValidator;
use App\Services\Authoring\NestedIdReconciler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter as SchemaErrorFormatter;
use Opis\JsonSchema\Validator as JsonSchemaValidator;

class LessonAuthoringService
{
    /**
     * Keys that must never appear in a savePage() payload. Identity and
     * lesson membership come from the route / loaded models only.
     *
     * @var list<string>
     */
    private const SAVE_PAGE_FORBIDDEN_KEYS = [
        'lesson_id',
        'page_id',
        'pages',
        'created_at',
        'code',
        'subject',
        'grade_range',
        'learning_target',
        'success_criteria',
        'standards',
        'status',
        'current_version',
        'has_unpublished_changes',
        'created_by_user_id',
        'updated_by',
        'uuid',
        'description',
    ];

    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly DraftConfigValidator $draftValidator,
        private readonly NestedIdReconciler $idReconciler,
        private readonly AuthoringErrorFormatter $errorFormatter,
        private readonly LessonPublisher $publisher,
        private readonly LessonCompiler $compiler,
        private readonly LessonContentDuplicator $duplicator,
    ) {
    }

    /**
     * Create a new draft lesson owned by $user.
     *
     * Optional pages[] is for seeding / tests only — the Filament create
     * form no longer embeds the page graph.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): Lesson
    {
        return DB::transaction(function () use ($data, $user) {
            $lesson = new Lesson;
            $lesson->forceFill([
                'code' => $data['code'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'subject' => $data['subject'] ?? null,
                'grade_range' => $data['grade_range'] ?? null,
                'estimated_minutes' => $data['estimated_minutes'] ?? null,
                'learning_target' => $data['learning_target'] ?? null,
                'success_criteria' => $this->normalizeStringList($data['success_criteria'] ?? null),
                'standards' => $this->normalizeStringList($data['standards'] ?? null),
                'settings' => array_merge(Lesson::DEFAULT_SETTINGS, $data['settings'] ?? []),
                'status' => LessonStatus::Draft,
                'current_version' => 0,
                'has_unpublished_changes' => false,
                'created_by_user_id' => $user->getKey(),
                'updated_by' => $user->getKey(),
            ])->save();

            if (array_key_exists('pages', $data)) {
                $this->syncGraph($lesson, $data['pages'] ?? [], $user);
            }

            return $lesson->fresh(['pages.blocks']);
        });
    }

    /**
     * Persist lesson metadata only.
     *
     * Concurrency token: lessons.updated_at — protects lesson metadata AND
     * page ordering. This method must never reconcile the page graph; absent
     * or empty pages[] must not delete pages. Use savePage() / createPage() /
     * deletePage() / reorderPages() for page-scoped work.
     *
     * @param  array<string, mixed>  $data
     * @return array{lesson: Lesson, warnings: list<string>}
     */
    public function save(Lesson $lesson, array $data, User $user): array
    {
        return DB::transaction(function () use ($lesson, $data, $user) {
            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());
            $this->assertLessonRevision($locked, $data['updated_at'] ?? null);

            $locked->forceFill([
                'code' => $data['code'] ?? $locked->code,
                'title' => $data['title'] ?? $locked->title,
                'description' => $data['description'] ?? null,
                'subject' => $data['subject'] ?? null,
                'grade_range' => $data['grade_range'] ?? null,
                'estimated_minutes' => $data['estimated_minutes'] ?? null,
                'learning_target' => $data['learning_target'] ?? null,
                'success_criteria' => $this->normalizeStringList($data['success_criteria'] ?? null),
                'standards' => $this->normalizeStringList($data['standards'] ?? null),
                'settings' => array_merge(
                    Lesson::DEFAULT_SETTINGS,
                    is_array($data['settings'] ?? null) ? $data['settings'] : ($locked->settings ?? [])
                ),
                'updated_by' => $user->getKey(),
            ])->save();

            // Flag only — must not bump lessons.updated_at again.
            $locked->markUnpublishedChanges();

            return [
                'lesson' => $locked->fresh(['pages.blocks']),
                'warnings' => [],
            ];
        });
    }

    /**
     * Create an empty page and append it to the lesson.
     *
     * Concurrency token: lessons.updated_at — adding a page changes the page
     * graph. Does not use lesson_pages.updated_at (the row does not exist yet).
     *
     * lessons.has_unpublished_changes is a flag, not a concurrency token, and
     * is toggled without changing lessons.updated_at (via markUnpublishedChanges).
     */
    public function createPage(Lesson $lesson, User $user, string $expectedUpdatedAt, array $attributes = []): LessonPage
    {
        return DB::transaction(function () use ($lesson, $user, $expectedUpdatedAt, $attributes) {
            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());
            // Callers must pass the revision they were holding — never fall back
            // to the locked row's own updated_at (that always compares equal).
            $this->assertLessonRevision($locked, $expectedUpdatedAt);

            $pageId = is_string($attributes['page_id'] ?? null) && $attributes['page_id'] !== ''
                ? $attributes['page_id']
                : (string) Str::ulid();

            $max = (int) $locked->pages()->max('position');
            $allowReadAloud = (bool) ($locked->settings['default_allow_read_aloud']
                ?? Lesson::DEFAULT_SETTINGS['default_allow_read_aloud']);

            $page = new LessonPage;
            $page->setRelation('lesson', $locked);
            $page->forceFill([
                'lesson_id' => $locked->getKey(),
                'page_id' => $pageId,
                'position' => $max + 1,
                'title' => $attributes['title'] ?? 'Untitled page',
                'completion_type' => PageCompletionType::tryFrom((string) ($attributes['completion_type'] ?? ''))
                    ?? PageCompletionType::View,
                'estimated_minutes' => $attributes['estimated_minutes'] ?? null,
                'settings' => array_merge(LessonPage::DEFAULT_SETTINGS, [
                    'allow_read_aloud' => $allowReadAloud,
                ], is_array($attributes['settings'] ?? null) ? $attributes['settings'] : []),
            ])->save();

            $this->bumpLessonRevision($locked, $user);

            return $page->fresh('blocks');
        });
    }

    /**
     * Persist one page's settings and blocks. Never touches sibling pages.
     *
     * Concurrency token: lesson_pages.updated_at — protects that page's
     * settings and blocks. Must NOT bump lessons.updated_at (two teachers on
     * different pages must not collide; an open lesson-metadata form must
     * stay valid).
     *
     * lessons.has_unpublished_changes is a flag, not a concurrency token.
     *
     * @param  array<string, mixed>  $data
     * @return array{page: LessonPage, warnings: list<string>}
     */
    public function savePage(LessonPage $page, array $data, User $user): array
    {
        $this->rejectForbiddenSavePageKeys($data);

        return DB::transaction(function () use ($page, $data, $user) {
            /** @var LessonPage $locked */
            $locked = LessonPage::query()->lockForUpdate()->findOrFail($page->getKey());
            $this->assertPageRevision($locked, $data['updated_at'] ?? null);

            $normalized = $this->normalizeIncomingGraph([[
                'page_id' => $locked->page_id,
                'title' => $data['title'] ?? $locked->title,
                'completion_type' => $data['completion_type'] ?? $locked->completion_type?->value,
                'estimated_minutes' => array_key_exists('estimated_minutes', $data)
                    ? $data['estimated_minutes']
                    : $locked->estimated_minutes,
                'settings' => is_array($data['settings'] ?? null)
                    ? $data['settings']
                    : ($locked->settings ?? []),
                'blocks' => $data['blocks'] ?? [],
            ]]);

            if ($normalized['errors'] !== []) {
                throw AuthoringValidationException::with($normalized['errors'], $normalized['warnings']);
            }

            $pageData = $normalized['pages'][0];

            $locked->forceFill([
                'title' => $pageData['title'],
                'completion_type' => $pageData['completion_type'],
                'estimated_minutes' => $pageData['estimated_minutes'],
                'settings' => array_merge(LessonPage::DEFAULT_SETTINGS, $pageData['settings']),
            ])->save();

            $this->persistBlocks($locked, $pageData['blocks']);

            // Page model / block events already flag unpublished changes via
            // query-builder updates that leave lessons.updated_at alone.
            $locked->lesson?->markUnpublishedChanges();

            return [
                'page' => $locked->fresh('blocks'),
                'warnings' => $normalized['warnings'],
            ];
        });
    }

    /**
     * Delete an authoring page row (and its blocks via FK cascade). Published
     * manifests and pinned attempts are untouched.
     *
     * Concurrency token: lessons.updated_at — deleting a page changes the
     * page graph.
     */
    public function deletePage(Lesson $lesson, LessonPage $page, User $user, string $expectedUpdatedAt): void
    {
        if ((int) $page->lesson_id !== (int) $lesson->getKey()) {
            throw AuthoringPayloadException::forbidden('lesson_id');
        }

        DB::transaction(function () use ($lesson, $page, $user, $expectedUpdatedAt) {
            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());
            $this->assertLessonRevision($locked, $expectedUpdatedAt);

            /** @var LessonPage $lockedPage */
            $lockedPage = LessonPage::query()
                ->where('lesson_id', $locked->getKey())
                ->whereKey($page->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPage->delete();

            $remainingIds = LessonPage::query()
                ->where('lesson_id', $locked->getKey())
                ->orderBy('position')
                ->pluck('page_id')
                ->all();

            if ($remainingIds !== []) {
                LessonPage::reorderWithin($locked->fresh(), $remainingIds);
            }

            $this->bumpLessonRevision($locked, $user);
        });
    }

    /**
     * Reorder pages via two-phase LessonPage::reorderWithin. Never delete or
     * recreate pages — page_id is referenced by pinned manifests and attempts.
     *
     * Concurrency token: lessons.updated_at — page ordering is lesson-scoped.
     *
     * @param  list<string>  $orderedPageIds
     */
    public function reorderPages(Lesson $lesson, array $orderedPageIds, User $user, string $expectedUpdatedAt): Lesson
    {
        return DB::transaction(function () use ($lesson, $orderedPageIds, $user, $expectedUpdatedAt) {
            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());
            $this->assertLessonRevision($locked, $expectedUpdatedAt);

            LessonPage::reorderWithin($locked, $orderedPageIds);

            $this->bumpLessonRevision($locked, $user);

            return $locked->fresh(['pages.blocks']);
        });
    }

    /**
     * Duplicate a page within the same lesson (fresh identifiers).
     *
     * Concurrency token: lessons.updated_at — duplication changes the page graph.
     */
    public function duplicatePage(Lesson $lesson, LessonPage $sourcePage, User $user, string $expectedUpdatedAt): LessonPage
    {
        if ((int) $sourcePage->lesson_id !== (int) $lesson->getKey()) {
            throw AuthoringPayloadException::forbidden('lesson_id');
        }

        return DB::transaction(function () use ($lesson, $sourcePage, $user, $expectedUpdatedAt) {
            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());
            $this->assertLessonRevision($locked, $expectedUpdatedAt);

            $copy = $this->duplicator->duplicatePageWithin($locked, $sourcePage);

            $this->bumpLessonRevision($locked, $user);

            return $copy->fresh('blocks');
        });
    }

    /**
     * Copy a page from any viewable lesson into $target (fresh identifiers).
     *
     * Concurrency token: lessons.updated_at — adding a page changes the page graph.
     * Source lesson / page timestamps must not change.
     */
    public function copyPageInto(LessonPage $sourcePage, Lesson $target, User $user, string $expectedUpdatedAt): LessonPage
    {
        return DB::transaction(function () use ($sourcePage, $target, $user, $expectedUpdatedAt) {
            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($target->getKey());
            $this->assertLessonRevision($locked, $expectedUpdatedAt);

            $copy = $this->duplicator->copyPageInto($sourcePage, $locked);

            $this->bumpLessonRevision($locked, $user);

            return $copy->fresh('blocks');
        });
    }

    /**
     * Always advance lessons.updated_at for page-graph ops. forceFill(updated_by)
     * alone is a no-op when the same teacher writes again, and Eloquent would
     * skip touching updated_at — defeating optimistic locks and tests that
     * travel time.
     */
    private function bumpLessonRevision(Lesson $lesson, User $user): void
    {
        $lesson->forceFill([
            'updated_by' => $user->getKey(),
            'updated_at' => now(),
        ])->save();

        $lesson->markUnpublishedChanges();
    }

    /**
     * Validate for publish with addressed errors, then create a new version.
     *
     * @throws AuthoringValidationException
     */
    public function publish(Lesson $lesson, User $user): \App\Models\LessonVersion
    {
        if ($lesson->status === LessonStatus::Archived) {
            throw AuthoringValidationException::with([
                'Archived lessons cannot be published. Unarchive first.',
            ]);
        }

        $this->assertPublishReady($lesson);

        return $this->publisher->publish($lesson, $user);
    }

    public function archive(Lesson $lesson, User $user): Lesson
    {
        $lesson->forceFill([
            'status' => LessonStatus::Archived,
            'updated_by' => $user->getKey(),
        ])->save();

        return $lesson->fresh();
    }

    public function unarchive(Lesson $lesson, User $user): Lesson
    {
        $lesson->forceFill([
            // Unarchive restores published status when a version exists.
            'status' => $lesson->current_version > 0
                ? LessonStatus::Published
                : LessonStatus::Draft,
            'updated_by' => $user->getKey(),
        ])->save();

        return $lesson->fresh();
    }

    /**
     * Admin-only ownership transfer with an immutable audit row.
     */
    public function reassignOwner(Lesson $lesson, User $newOwner, User $actor): Lesson
    {
        return DB::transaction(function () use ($lesson, $newOwner, $actor) {
            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());
            $previous = $locked->created_by_user_id;

            if ((int) $previous === (int) $newOwner->getKey()) {
                return $locked;
            }

            LessonOwnerChange::query()->create([
                'lesson_id' => $locked->getKey(),
                'previous_owner_user_id' => $previous,
                'new_owner_user_id' => $newOwner->getKey(),
                'changed_by_user_id' => $actor->getKey(),
                'source' => 'manual',
                'created_at' => now(),
            ]);

            $locked->forceFill([
                'created_by_user_id' => $newOwner->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            return $locked->fresh();
        });
    }

    /**
     * @throws AuthoringValidationException
     */
    public function assertPublishReady(Lesson $lesson): void
    {
        $lesson->load(['pages.blocks']);
        $errors = [];
        $pageSummaries = [];

        foreach ($lesson->pages as $pageIndex => $page) {
            $blockSummaries = [];

            foreach ($page->blocks as $blockIndex => $block) {
                $typeKey = is_string($block->type) ? $block->type : $block->type->value;
                $blockSummaries[] = ['type' => $typeKey, 'block_id' => $block->block_id];
                $context = [
                    'page_title' => $page->title,
                    'page_index' => $pageIndex,
                    'block_type' => $typeKey,
                    'block_index' => $blockIndex,
                    'block_id' => $block->block_id,
                ];

                if (! $this->registry->has($typeKey)) {
                    $errors[] = "{$page->title} / {$typeKey} #".($blockIndex + 1).': unregistered block type.';

                    continue;
                }

                $type = $this->registry->get($typeKey);

                try {
                    $validated = $type->validateConfig($block->config ?? []);
                    $type->compileConfig($validated);
                    $type->validateGrading(is_array($block->grading) ? $block->grading : null);
                } catch (ValidationException $e) {
                    $errors = array_merge($errors, $this->errorFormatter->fromValidationException($e, $context));
                }
            }

            $pageSummaries[] = [
                'title' => $page->title,
                'blocks' => $blockSummaries,
            ];
        }

        if ($errors !== []) {
            throw AuthoringValidationException::with($errors);
        }

        // Compile in memory for schema validation — never creates a version.
        $manifest = $this->compiler->compileManifest(
            $lesson,
            max(1, (int) $lesson->current_version + 1),
        );

        [$valid, $schemaErrors] = $this->validateManifestSchema($manifest);
        if (! $valid) {
            $pointers = array_keys($schemaErrors);
            throw AuthoringValidationException::with(
                $this->errorFormatter->fromSchemaPointers($pointers, $pageSummaries)
            );
        }
    }

    /**
     * Shape lesson metadata for the Filament lesson form (no pages / blocks).
     *
     * @return array<string, mixed>
     */
    public function toFormState(Lesson $lesson): array
    {
        return [
            'code' => $lesson->code,
            'title' => $lesson->title,
            'description' => $lesson->description,
            'subject' => $lesson->subject,
            'grade_range' => $lesson->grade_range,
            'estimated_minutes' => $lesson->estimated_minutes,
            'learning_target' => $lesson->learning_target,
            'success_criteria' => is_array($lesson->success_criteria)
                ? implode("\n", $lesson->success_criteria)
                : $lesson->success_criteria,
            'standards' => is_array($lesson->standards)
                ? implode("\n", $lesson->standards)
                : $lesson->standards,
            'settings' => $lesson->settings ?? Lesson::DEFAULT_SETTINGS,
            'status' => $lesson->status?->value,
            'current_version' => $lesson->current_version,
            'has_unpublished_changes' => $lesson->has_unpublished_changes,
            // lessons.updated_at — lesson metadata + page ordering token.
            'updated_at' => $lesson->updated_at?->toISOString(),
        ];
    }

    /**
     * Shape one page + its blocks for the Filament page form.
     *
     * @return array<string, mixed>
     */
    public function toPageFormState(LessonPage $page): array
    {
        $page->loadMissing('blocks');

        return [
            'title' => $page->title,
            'completion_type' => $page->completion_type?->value,
            'estimated_minutes' => $page->estimated_minutes,
            'settings' => array_merge(LessonPage::DEFAULT_SETTINGS, $page->settings ?? []),
            // lesson_pages.updated_at — page settings + blocks token.
            'updated_at' => $page->updated_at?->toISOString(),
            'blocks' => $page->blocks->map(function (LessonBlock $block) {
                $typeKey = is_string($block->type) ? $block->type : $block->type->value;
                $config = $this->idReconciler->forForm($typeKey, $block->config ?? []);

                return [
                    'type' => $typeKey,
                    'data' => array_merge($config, [
                        'block_id' => $block->block_id,
                        'grading' => $block->grading,
                    ]),
                ];
            })->values()->all(),
        ];
    }

    /**
     * lessons.updated_at concurrency check. Equal second-precision timestamps
     * mean "same known version" for this phase's optimistic lock.
     */
    private function assertLessonRevision(Lesson $locked, mixed $expected): void
    {
        if ($expected === null || $locked->updated_at === null) {
            throw StaleLessonEditException::make();
        }

        $expectedAt = \Illuminate\Support\Carbon::parse($expected);
        // MySQL datetime is second-precision; equal timestamps mean "same
        // known version" for this phase's optimistic lock.
        if ($locked->updated_at->timestamp !== $expectedAt->timestamp) {
            throw StaleLessonEditException::make();
        }
    }

    /**
     * lesson_pages.updated_at concurrency check.
     */
    private function assertPageRevision(LessonPage $locked, mixed $expected): void
    {
        if ($expected === null || $locked->updated_at === null) {
            throw StalePageEditException::make();
        }

        $expectedAt = \Illuminate\Support\Carbon::parse($expected);
        if ($locked->updated_at->timestamp !== $expectedAt->timestamp) {
            throw StalePageEditException::make();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rejectForbiddenSavePageKeys(array $data): void
    {
        foreach (self::SAVE_PAGE_FORBIDDEN_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                throw AuthoringPayloadException::forbidden($key);
            }
        }
    }

    /**
     * Normalize and draft-validate the entire graph in memory first. If any
     * block fails, write nothing and return every addressed error together.
     *
     * Used by create() seeding and internally by savePage() for a one-page
     * graph. Never called from metadata-only save().
     *
     * @param  list<array<string, mixed>>  $pagesData
     * @return list<string> warnings
     */
    private function syncGraph(Lesson $lesson, array $pagesData, User $user): array
    {
        $normalized = $this->normalizeIncomingGraph($pagesData);

        if ($normalized['errors'] !== []) {
            throw AuthoringValidationException::with($normalized['errors'], $normalized['warnings']);
        }

        $warnings = $normalized['warnings'];
        $existingPages = LessonPage::query()
            ->where('lesson_id', $lesson->getKey())
            ->with('blocks')
            ->lockForUpdate()
            ->get()
            ->keyBy('page_id');

        $orderedPageIds = [];

        foreach ($normalized['pages'] as $pageIndex => $pageData) {
            $pageId = $pageData['page_id'];

            /** @var LessonPage|null $page */
            $page = $existingPages->get($pageId);
            $isNewPage = $page === null;

            if ($isNewPage) {
                $page = new LessonPage;
                $page->lesson_id = $lesson->getKey();
                $page->page_id = $pageId;
                $page->position = 10_000 + $pageIndex;
                $page->setRelation('lesson', $lesson);
            }

            $page->forceFill([
                'title' => $pageData['title'],
                'completion_type' => $pageData['completion_type'],
                'estimated_minutes' => $pageData['estimated_minutes'],
                'settings' => $isNewPage
                    ? $pageData['settings']
                    : array_merge(LessonPage::DEFAULT_SETTINGS, $pageData['settings']),
            ])->save();

            $this->persistBlocks($page, $pageData['blocks']);

            $orderedPageIds[] = $pageId;
            $existingPages->forget($pageId);
        }

        foreach ($existingPages as $removed) {
            $removed->blocks()->delete();
            $removed->delete();
        }

        if ($orderedPageIds !== []) {
            LessonPage::reorderWithin($lesson->fresh(), $orderedPageIds);
        }

        return $warnings;
    }

    /**
     * @param  list<array<string, mixed>>  $pagesData
     * @return array{pages: list<array<string, mixed>>, errors: list<string>, warnings: list<string>}
     */
    private function normalizeIncomingGraph(array $pagesData): array
    {
        $errors = [];
        $warnings = [];
        $pages = [];

        foreach (array_values($pagesData) as $pageIndex => $pageData) {
            if (! is_array($pageData)) {
                $errors[] = 'Page #'.($pageIndex + 1).': must be an object.';

                continue;
            }

            $pageId = is_string($pageData['page_id'] ?? null) && $pageData['page_id'] !== ''
                ? $pageData['page_id']
                : (string) Str::ulid();

            $title = (string) ($pageData['title'] ?? 'Untitled page');
            $completion = PageCompletionType::tryFrom((string) ($pageData['completion_type'] ?? ''))
                ?? PageCompletionType::View;
            $providedSettings = is_array($pageData['settings'] ?? null) ? $pageData['settings'] : [];
            $blocks = [];

            foreach (array_values($pageData['blocks'] ?? []) as $blockIndex => $item) {
                if (! is_array($item)) {
                    $errors[] = "{$title} / block #".($blockIndex + 1).': must be an object.';

                    continue;
                }

                $typeKey = (string) ($item['type'] ?? '');
                $data = is_array($item['data'] ?? null) ? $item['data'] : [];
                $blockId = is_string($data['block_id'] ?? null) && $data['block_id'] !== ''
                    ? $data['block_id']
                    : (string) Str::ulid();
                $grading = is_array($data['grading'] ?? null) ? $data['grading'] : null;
                unset($data['block_id'], $data['grading']);

                $draft = $this->draftValidator->validate($typeKey, $data, $grading);
                foreach ($draft['errors'] as $error) {
                    $errors[] = "{$title} / {$typeKey} #".($blockIndex + 1)." / {$error}";
                }
                foreach ($draft['warnings'] as $warning) {
                    $warnings[] = "{$title} / {$typeKey} #".($blockIndex + 1)." / {$warning}";
                }

                $blocks[] = [
                    'block_id' => $blockId,
                    'type' => $typeKey,
                    'config' => $draft['errors'] === []
                        ? $this->idReconciler->reconcile($typeKey, $data)
                        : $data,
                    'grading' => $grading,
                ];
            }

            $pages[] = [
                'page_id' => $pageId,
                'title' => $title,
                'completion_type' => $completion,
                'estimated_minutes' => $pageData['estimated_minutes'] ?? null,
                'settings' => $providedSettings,
                'blocks' => $blocks,
            ];
        }

        return compact('pages', 'errors', 'warnings');
    }

    /**
     * @param  list<array{block_id: string, type: string, config: array, grading: ?array}>  $blocksData
     */
    private function persistBlocks(LessonPage $page, array $blocksData): void
    {
        $existing = LessonBlock::query()
            ->where('lesson_page_id', $page->getKey())
            ->lockForUpdate()
            ->get()
            ->keyBy('block_id');

        $orderedBlockIds = [];

        foreach (array_values($blocksData) as $blockIndex => $item) {
            $blockId = $item['block_id'];

            /** @var LessonBlock|null $block */
            $block = $existing->get($blockId);

            if ($block === null) {
                $block = new LessonBlock;
                $block->lesson_page_id = $page->getKey();
                $block->block_id = $blockId;
                $block->position = 10_000 + $blockIndex;
            }

            $block->forceFill([
                'type' => $item['type'],
                'config' => $item['config'],
                'grading' => $item['grading'],
            ])->save();

            $orderedBlockIds[] = $blockId;
            $existing->forget($blockId);
        }

        foreach ($existing as $removed) {
            $removed->delete();
        }

        if ($orderedBlockIds !== []) {
            LessonBlock::reorderWithin($page->fresh(), $orderedBlockIds);
        }
    }

    /**
     * @return array{0: bool, 1: array<string, string>}
     */
    private function validateManifestSchema(array $manifest): array
    {
        $validator = new JsonSchemaValidator;
        $schemaId = 'https://teched.example/schemas/lesson-manifest.schema.json';
        $validator->resolver()->registerFile(
            $schemaId,
            base_path('docs/schemas/lesson-manifest.schema.json')
        );

        $result = $validator->validate(
            json_decode(json_encode($manifest)),
            $schemaId
        );

        if ($result->isValid()) {
            return [true, []];
        }

        $error = $result->error();
        if ($error === null) {
            return [false, ['/' => 'Unknown schema validation failure']];
        }

        return [false, (new SchemaErrorFormatter)->format($error)];
    }

    /**
     * @return list<string>|null
     */
    private function normalizeStringList(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];

            return array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
        }

        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        return null;
    }
}
