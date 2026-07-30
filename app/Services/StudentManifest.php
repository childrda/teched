<?php

namespace App\Services;

use App\Blocks\BlockTypeRegistry;
use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\LessonVersion;

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
     * The version a student may play, or null when the lesson has nothing
     * they may see. The single source of the availability rule: forLesson()
     * and the grading endpoint both go through here, so the two can never
     * disagree about what is playable.
     */
    public function availableVersion(Lesson $lesson): ?LessonVersion
    {
        if ($lesson->status !== LessonStatus::Published) {
            return null;
        }

        return $lesson->currentVersion();
    }

    /**
     * The currently available version's student payload, or null when the
     * lesson has nothing a student may see.
     */
    public function forLesson(Lesson $lesson): ?array
    {
        $version = $this->availableVersion($lesson);

        if ($version === null) {
            return null;
        }

        return $this->forVersion($version);
    }

    /**
     * Redacted, speech-annotated payload for a specific published version —
     * the attempt's pin, not necessarily the lesson's current version.
     *
     * @return array<string, mixed>
     */
    public function forVersion(LessonVersion $version): array
    {
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
