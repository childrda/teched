<?php

namespace App\Services\Authoring;

use Illuminate\Support\Str;

/**
 * Ensures nested config identifiers from the form are preserved on save.
 * New items without an id receive a ULID; existing ids are never reminted
 * based on content similarity.
 *
 * Duplication is the inverse: LessonContentDuplicator regenerates every
 * authoring id and rewrites answer_id / bank associations through a map.
 * Do not "fix" this reconciler to remint on copy, and do not teach the
 * duplicator to preserve ids the way this class does on edit.
 */
class NestedIdReconciler
{
    public function reconcile(string $type, array $config): array
    {
        return match ($type) {
            'quiz' => $this->reconcileQuiz($config),
            'matching' => $this->reconcileMatching($config),
            'image_labeling' => $this->reconcileImageLabeling($config),
            'cer' => $this->ensureListIds($config, 'fields'),
            'vocabulary_cards' => $this->ensureListIds($config, 'terms'),
            'video' => $this->ensureListIds($config, 'focus_questions'),
            'static_table' => $this->normalizeStaticTable($config),
            default => $config,
        };
    }

    private function reconcileQuiz(array $config): array
    {
        $questions = [];

        foreach ($config['questions'] ?? [] as $question) {
            if (! is_array($question)) {
                continue;
            }

            $question = $this->ensureId($question);
            $options = [];

            foreach ($question['options'] ?? [] as $option) {
                if (! is_array($option)) {
                    continue;
                }
                $options[] = $this->ensureId($option);
            }

            $question['options'] = $options;
            $questions[] = $question;
        }

        $config['questions'] = $questions;

        return $config;
    }

    private function reconcileMatching(array $config): array
    {
        $config = $this->ensureListIds($config, 'bank');
        $config = $this->ensureListIds($config, 'slots');

        return $config;
    }

    private function reconcileImageLabeling(array $config): array
    {
        $config = $this->ensureListIds($config, 'bank');
        $config = $this->ensureListIds($config, 'hotspots');

        return $config;
    }

    private function ensureListIds(array $config, string $key): array
    {
        $items = [];

        foreach ($config[$key] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $items[] = $this->ensureId($item);
        }

        $config[$key] = $items;

        return $config;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function ensureId(array $item): array
    {
        if (! is_string($item['id'] ?? null) || $item['id'] === '') {
            $item['id'] = (string) Str::ulid();
        }

        return $item;
    }

    /**
     * Form rows are {cells: [...]}; domain rows are plain string lists.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeStaticTable(array $config): array
    {
        $rows = [];

        foreach ($config['rows'] ?? [] as $row) {
            if (is_array($row) && array_key_exists('cells', $row) && is_array($row['cells'])) {
                $rows[] = array_values(array_map('strval', $row['cells']));
            } elseif (is_array($row)) {
                $rows[] = array_values(array_map(
                    fn ($cell) => is_scalar($cell) ? (string) $cell : '',
                    $row
                ));
            }
        }

        $config['rows'] = $rows;

        return $config;
    }

    /**
     * Inverse of normalizeStaticTable for form fill.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function forForm(string $type, array $config): array
    {
        if ($type !== 'static_table') {
            return $config;
        }

        $rows = [];
        foreach ($config['rows'] ?? [] as $row) {
            if (is_array($row) && array_is_list($row)) {
                $rows[] = ['cells' => array_values(array_map('strval', $row))];
            } elseif (is_array($row) && isset($row['cells'])) {
                $rows[] = $row;
            }
        }
        $config['rows'] = $rows;

        return $config;
    }
}
