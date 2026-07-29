<?php

namespace App\Services;

use App\Blocks\BlockTypeRegistry;
use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\LessonVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Compiles a lesson's live authoring tree (lesson -> pages -> blocks)
 * into an immutable JSON manifest stored on a new LessonVersion row.
 *
 * The whole publish runs in one transaction: a failure at any step
 * (validation, compilation, unregistered block type) leaves the lesson,
 * its status, current_version, and all version rows unchanged.
 */
class LessonPublisher
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly BlockTypeRegistry $registry)
    {
    }

    public function publish(Lesson $lesson, User $user): LessonVersion
    {
        return DB::transaction(function () use ($lesson, $user) {
            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            $nextVersion = ((int) $locked->versions()->max('version')) + 1;

            $manifest = $this->compileManifest($locked, $nextVersion);

            $version = $locked->versions()->create([
                'version' => $nextVersion,
                'schema_version' => self::SCHEMA_VERSION,
                'manifest' => $manifest,
                'published_by' => $user->getKey(),
                'published_at' => now(),
            ]);

            $locked->forceFill([
                'current_version' => $nextVersion,
                'status' => LessonStatus::Published,
                'has_unpublished_changes' => false,
            ])->save();

            $lesson->refresh();

            return $version;
        });
    }

    private function compileManifest(Lesson $lesson, int $version): array
    {
        $pages = $lesson->pages()->with('blocks')->get();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'code' => $lesson->code,
            'title' => $lesson->title,
            'version' => $version,
            'estimated_minutes' => $lesson->estimated_minutes,
            'learning_target' => $lesson->learning_target,
            'success_criteria' => $lesson->success_criteria,
            'pages' => $pages
                ->map(fn (LessonPage $page) => $this->compilePage($page))
                ->values()
                ->all(),
        ];
    }

    private function compilePage(LessonPage $page): array
    {
        return [
            'page_id' => $page->page_id,
            'title' => $page->title,
            'position' => $page->position,
            'completion_type' => $page->completion_type->value,
            'estimated_minutes' => $page->estimated_minutes,
            'settings' => array_merge(LessonPage::DEFAULT_SETTINGS, $page->settings ?? []),
            'blocks' => $page->blocks
                ->map(fn (LessonBlock $block) => $this->compileBlock($block))
                ->values()
                ->all(),
        ];
    }

    /**
     * The publisher owns the block wrapper: block type classes compile
     * only their own config, never block_id / type / grading.
     */
    private function compileBlock(LessonBlock $block): array
    {
        // Raw string key so an unregistered type surfaces as a descriptive
        // UnknownBlockTypeException instead of an enum cast ValueError.
        $typeKey = (string) $block->getRawOriginal('type');

        $type = $this->registry->get($typeKey);

        $validated = $type->validateConfig($block->config ?? []);
        $compiled = $type->compileConfig($validated);

        return [
            'block_id' => $block->block_id,
            'type' => $typeKey,
            'config' => $compiled,
            'grading' => $block->grading,
        ];
    }
}
