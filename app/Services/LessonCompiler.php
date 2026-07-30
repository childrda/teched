<?php

namespace App\Services;

use App\Blocks\BlockTypeRegistry;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;

/**
 * Compiles a lesson's live authoring tree into a manifest array.
 *
 * Never writes lesson_versions. LessonPublisher is the only writer of
 * version rows; authoring publish-readiness and Preview as Student share
 * this compiler so the three paths cannot drift.
 */
class LessonCompiler
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly BlockTypeRegistry $registry)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function compileManifest(Lesson $lesson, int $version): array
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

    /**
     * @return array<string, mixed>
     */
    public function compilePage(LessonPage $page): array
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
     * The compiler owns the block wrapper: block type classes compile
     * only their own config, never block_id / type / grading.
     *
     * @return array<string, mixed>
     */
    public function compileBlock(LessonBlock $block): array
    {
        // Raw string key so an unregistered type surfaces as a descriptive
        // UnknownBlockTypeException instead of an enum cast ValueError.
        $typeKey = (string) $block->getRawOriginal('type');

        $type = $this->registry->get($typeKey);

        $validated = $type->validateConfig($block->config ?? []);
        $compiled = $type->compileConfig($validated);
        $grading = $type->validateGrading(
            is_array($block->grading) ? $block->grading : null
        );

        return [
            'block_id' => $block->block_id,
            'type' => $typeKey,
            'config' => $compiled,
            'grading' => $grading,
        ];
    }
}
