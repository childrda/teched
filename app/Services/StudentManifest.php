<?php

namespace App\Services;

use App\Blocks\BlockTypeRegistry;
use App\Enums\LessonStatus;
use App\Models\Lesson;

/**
 * Builds the single payload every student-facing surface consumes: the
 * published manifest with each block config passed through its type's
 * redactConfig() and each block given a read-aloud "speech" list.
 *
 * Both the JSON API and the web player call this, so the two can never
 * drift in what they redact or what they let a student hear.
 *
 * Speech is registry-driven: the per-type wording lives in each block
 * type's speakableText(), never here, in a controller, or in a template.
 *
 * grading_token binds the player to this version for the grading endpoint
 * without exposing the version's database id.
 */
class StudentManifest
{
    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly GradingToken $gradingToken,
    ) {
    }

    /**
     * The servable manifest, or null when the lesson has nothing a student
     * may see: any status other than published (drafts and archived
     * lessons stay invisible even when prior versions exist), or a missing
     * current version row.
     */
    public function forLesson(Lesson $lesson): ?array
    {
        if ($lesson->status !== LessonStatus::Published) {
            return null;
        }

        $version = $lesson->currentVersion();

        if ($version === null) {
            return null;
        }

        // The array cast returns a fresh decoded copy, so redaction below
        // never mutates the stored manifest or the model's attribute.
        $manifest = $version->manifest;

        $manifest['pages'] = array_map(
            fn (array $page) => $this->preparePage($page),
            $manifest['pages'] ?? []
        );

        $manifest['grading_token'] = $this->gradingToken->issue($version);

        return $manifest;
    }

    private function preparePage(array $page): array
    {
        // Already resolved at compile time; no further resolution here.
        $readAloud = (bool) ($page['settings']['allow_read_aloud'] ?? true);

        $page['blocks'] = array_map(
            fn (array $block) => $this->prepareBlock($block, $readAloud),
            $page['blocks'] ?? []
        );

        return $page;
    }

    private function prepareBlock(array $block, bool $readAloud): array
    {
        $type = $this->registry->get($block['type']);

        $redacted = $type->redactConfig($block['config']);

        // Speech is derived from the REDACTED config only, so an answer,
        // rubric, or feedback string can never be spoken.
        $block['config'] = $redacted;
        $block['speech'] = $readAloud ? $type->speakableText($redacted) : [];

        return $block;
    }
}
