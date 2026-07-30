<?php

namespace App\Services;

use App\Enums\LessonStatus;
use App\Enums\PageCompletionType;
use App\Exceptions\AuthoringValidationException;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Duplication is the one place authoring identifiers are regenerated.
 *
 * NestedIdReconciler preserves ids on edit so student state stays resolvable.
 * A copy is new content — every page_id, block_id, and nested config id must
 * be fresh, and every persisted reference (answer_id, bank associations) must
 * be rewritten through the same old→new map. Never apply reconcile-on-save
 * logic here, and never "fix" reconciler preservation into a duplicate.
 *
 * Work happens entirely in memory: map, rewrite, validate+compile, then
 * insert. A duplicate that cannot compile never reaches the database.
 */
class LessonContentDuplicator
{
    public function __construct(private readonly LessonCompiler $compiler)
    {
    }

    public function duplicateLesson(Lesson $source, User $actor): Lesson
    {
        $source->load(['pages.blocks']);

        $idMap = [];
        $pagesPayload = [];

        foreach ($source->pages as $page) {
            [$pagePayload, $idMap] = $this->duplicatePageInMemory($page, $idMap);
            $pagesPayload[] = $pagePayload;
        }

        $this->validateCompiledGraph($source, $pagesPayload, forNewLesson: true);

        return DB::transaction(function () use ($source, $actor, $pagesPayload) {
            $lesson = new Lesson;
            $lesson->forceFill([
                'code' => $this->uniqueCode($source->code),
                'title' => $source->title.' (Copy)',
                'description' => $source->description,
                'subject' => $source->subject,
                'grade_range' => $source->grade_range,
                'estimated_minutes' => $source->estimated_minutes,
                'learning_target' => $source->learning_target,
                'success_criteria' => $source->success_criteria,
                'standards' => $source->standards,
                'settings' => $source->settings ?? Lesson::DEFAULT_SETTINGS,
                'status' => LessonStatus::Draft,
                'current_version' => 0,
                'has_unpublished_changes' => false,
                'created_by_user_id' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->insertPages($lesson, $pagesPayload);

            return $lesson->fresh(['pages.blocks']);
        });
    }

    public function duplicatePageWithin(Lesson $lesson, LessonPage $sourcePage): LessonPage
    {
        $sourcePage->loadMissing('blocks');
        [$pagePayload] = $this->duplicatePageInMemory($sourcePage, []);
        $this->validateCompiledPages($lesson, [$pagePayload]);

        return DB::transaction(function () use ($lesson, $pagePayload) {
            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());
            $max = (int) $locked->pages()->max('position');
            $page = $this->insertPage($locked, $pagePayload, $max + 1);
            $locked->markUnpublishedChanges();

            return $page->fresh('blocks');
        });
    }

    public function duplicateBlockWithin(LessonPage $page, LessonBlock $sourceBlock): LessonBlock
    {
        $page->loadMissing('lesson');
        [$blockPayload] = $this->duplicateBlockInMemory($sourceBlock, []);
        $this->validateCompiledPages($page->lesson, [[
            'page_id' => $page->page_id,
            'title' => $page->title,
            'position' => $page->position,
            'completion_type' => $page->completion_type->value,
            'estimated_minutes' => $page->estimated_minutes,
            'settings' => $page->settings ?? [],
            'blocks' => [$blockPayload],
        ]]);

        return DB::transaction(function () use ($page, $blockPayload) {
            /** @var LessonPage $locked */
            $locked = LessonPage::query()->lockForUpdate()->findOrFail($page->getKey());
            $max = (int) $locked->blocks()->max('position');
            $block = $this->insertBlock($locked, $blockPayload, $max + 1);
            $locked->lesson?->markUnpublishedChanges();

            return $block->fresh();
        });
    }

    public function copyPageInto(LessonPage $sourcePage, Lesson $target): LessonPage
    {
        $sourcePage->loadMissing('blocks');
        [$pagePayload] = $this->duplicatePageInMemory($sourcePage, []);
        $this->validateCompiledPages($target, [$pagePayload]);

        return DB::transaction(function () use ($target, $pagePayload) {
            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($target->getKey());
            $max = (int) $locked->pages()->max('position');
            $page = $this->insertPage($locked, $pagePayload, $max + 1);
            $locked->markUnpublishedChanges();

            return $page->fresh('blocks');
        });
    }

    /**
     * @param  array<string, string>  $idMap
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function duplicatePageInMemory(LessonPage $page, array $idMap): array
    {
        $newPageId = (string) Str::ulid();
        $idMap[$page->page_id] = $newPageId;

        $blocks = [];
        foreach ($page->blocks as $block) {
            [$blockPayload, $idMap] = $this->duplicateBlockInMemory($block, $idMap);
            $blocks[] = $blockPayload;
        }

        return [[
            'page_id' => $newPageId,
            'title' => $page->title,
            'position' => $page->position,
            'completion_type' => $page->completion_type->value,
            'estimated_minutes' => $page->estimated_minutes,
            'settings' => $page->settings ?? LessonPage::DEFAULT_SETTINGS,
            'blocks' => $blocks,
        ], $idMap];
    }

    /**
     * @param  array<string, string>  $idMap
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function duplicateBlockInMemory(LessonBlock $block, array $idMap): array
    {
        $typeKey = is_string($block->type) ? $block->type : $block->type->value;
        $newBlockId = (string) Str::ulid();
        $idMap[$block->block_id] = $newBlockId;

        $config = is_array($block->config) ? $block->config : [];
        [$config, $idMap] = $this->remapConfigIds($typeKey, $config, $idMap);

        return [[
            'block_id' => $newBlockId,
            'type' => $typeKey,
            'config' => $config,
            'grading' => $block->grading,
            'position' => $block->position,
        ], $idMap];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $idMap
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function remapConfigIds(string $type, array $config, array $idMap): array
    {
        return match ($type) {
            'quiz' => $this->remapQuiz($config, $idMap),
            'matching' => $this->remapMatching($config, $idMap),
            'image_labeling' => $this->remapImageLabeling($config, $idMap),
            'cer' => $this->remapIdList($config, 'fields', $idMap),
            'vocabulary_cards' => $this->remapIdList($config, 'terms', $idMap),
            'video' => $this->remapIdList($config, 'focus_questions', $idMap),
            default => [$config, $idMap],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $idMap
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function remapQuiz(array $config, array $idMap): array
    {
        $questions = [];
        foreach ($config['questions'] ?? [] as $question) {
            if (! is_array($question)) {
                continue;
            }
            $oldQ = (string) ($question['id'] ?? '');
            $newQ = (string) Str::ulid();
            if ($oldQ !== '') {
                $idMap[$oldQ] = $newQ;
            }
            $options = [];
            foreach ($question['options'] ?? [] as $option) {
                if (! is_array($option)) {
                    continue;
                }
                $oldO = (string) ($option['id'] ?? '');
                $newO = (string) Str::ulid();
                if ($oldO !== '') {
                    $idMap[$oldO] = $newO;
                }
                $option['id'] = $newO;
                $options[] = $option;
            }
            $question['id'] = $newQ;
            $question['options'] = $options;
            $answer = (string) ($question['answer_id'] ?? '');
            $question['answer_id'] = $idMap[$answer] ?? $answer;
            $questions[] = $question;
        }
        $config['questions'] = $questions;

        return [$config, $idMap];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $idMap
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function remapMatching(array $config, array $idMap): array
    {
        [$config, $idMap] = $this->remapIdList($config, 'bank', $idMap);
        $slots = [];
        foreach ($config['slots'] ?? [] as $slot) {
            if (! is_array($slot)) {
                continue;
            }
            $old = (string) ($slot['id'] ?? '');
            $new = (string) Str::ulid();
            if ($old !== '') {
                $idMap[$old] = $new;
            }
            $slot['id'] = $new;
            $answer = (string) ($slot['answer_id'] ?? '');
            $slot['answer_id'] = $idMap[$answer] ?? $answer;
            $slots[] = $slot;
        }
        $config['slots'] = $slots;

        return [$config, $idMap];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $idMap
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function remapImageLabeling(array $config, array $idMap): array
    {
        [$config, $idMap] = $this->remapIdList($config, 'bank', $idMap);
        $hotspots = [];
        foreach ($config['hotspots'] ?? [] as $hotspot) {
            if (! is_array($hotspot)) {
                continue;
            }
            $old = (string) ($hotspot['id'] ?? '');
            $new = (string) Str::ulid();
            if ($old !== '') {
                $idMap[$old] = $new;
            }
            $hotspot['id'] = $new;
            $answer = (string) ($hotspot['answer_id'] ?? '');
            $hotspot['answer_id'] = $idMap[$answer] ?? $answer;
            $hotspots[] = $hotspot;
        }
        $config['hotspots'] = $hotspots;

        return [$config, $idMap];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $idMap
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function remapIdList(array $config, string $key, array $idMap): array
    {
        $items = [];
        foreach ($config[$key] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $old = (string) ($item['id'] ?? '');
            $new = (string) Str::ulid();
            if ($old !== '') {
                $idMap[$old] = $new;
            }
            $item['id'] = $new;
            $items[] = $item;
        }
        $config[$key] = $items;

        return [$config, $idMap];
    }

    /**
     * @param  list<array<string, mixed>>  $pagesPayload
     */
    private function validateCompiledGraph(Lesson $source, array $pagesPayload, bool $forNewLesson): void
    {
        $errors = [];
        $registry = app(\App\Blocks\BlockTypeRegistry::class);

        foreach ($pagesPayload as $page) {
            foreach ($page['blocks'] as $blockIndex => $block) {
                try {
                    $blockType = $registry->get($block['type']);
                    $validated = $blockType->validateConfig($block['config']);
                    $blockType->compileConfig($validated);
                    $blockType->validateGrading($block['grading']);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $errors[] = "{$page['title']} / {$block['type']} #".($blockIndex + 1)." / {$field}: {$message}";
                        }
                    }
                }
            }
        }

        if ($errors !== []) {
            throw AuthoringValidationException::with($errors);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $pagesPayload
     */
    private function validateCompiledPages(Lesson $lesson, array $pagesPayload): void
    {
        $this->validateCompiledGraph($lesson, $pagesPayload, forNewLesson: false);
    }

    /**
     * @param  list<array<string, mixed>>  $pagesPayload
     */
    private function insertPages(Lesson $lesson, array $pagesPayload): void
    {
        foreach (array_values($pagesPayload) as $index => $pagePayload) {
            $this->insertPage($lesson, $pagePayload, $index + 1);
        }
    }

    /**
     * @param  array<string, mixed>  $pagePayload
     */
    private function insertPage(Lesson $lesson, array $pagePayload, int $position): LessonPage
    {
        $page = new LessonPage;
        $page->lesson_id = $lesson->getKey();
        $page->page_id = $pagePayload['page_id'];
        $page->setRelation('lesson', $lesson);
        $page->forceFill([
            'title' => $pagePayload['title'],
            'position' => $position,
            'completion_type' => PageCompletionType::from($pagePayload['completion_type']),
            'estimated_minutes' => $pagePayload['estimated_minutes'],
            'settings' => $pagePayload['settings'],
        ])->save();

        foreach (array_values($pagePayload['blocks']) as $index => $blockPayload) {
            $this->insertBlock($page, $blockPayload, $index + 1);
        }

        return $page;
    }

    /**
     * @param  array<string, mixed>  $blockPayload
     */
    private function insertBlock(LessonPage $page, array $blockPayload, int $position): LessonBlock
    {
        $block = new LessonBlock;
        $block->lesson_page_id = $page->getKey();
        $block->block_id = $blockPayload['block_id'];
        $block->forceFill([
            'type' => $blockPayload['type'],
            'position' => $position,
            'config' => $blockPayload['config'],
            'grading' => $blockPayload['grading'],
        ])->save();

        return $block;
    }

    private function uniqueCode(string $sourceCode): string
    {
        $base = Str::limit($sourceCode.'-COPY', 60, '');
        $candidate = $base;
        $n = 2;

        while (Lesson::withTrashed()->where('code', $candidate)->exists()) {
            $candidate = Str::limit($base.'-'.$n, 64, '');
            $n++;
        }

        return $candidate;
    }
}
