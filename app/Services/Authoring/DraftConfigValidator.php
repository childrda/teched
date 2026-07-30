<?php

namespace App\Services\Authoring;

use App\Blocks\BlockTypeRegistry;

/**
 * Structural draft checks: known keys, basic types, identifier shapes.
 * Incomplete publish-required fields become warnings, not hard errors.
 */
class DraftConfigValidator
{
    public function __construct(private readonly BlockTypeRegistry $registry)
    {
    }

    /**
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(string $type, array $config, ?array $grading): array
    {
        $errors = [];
        $warnings = [];

        if (! $this->registry->has($type)) {
            return [
                'errors' => ["Unknown block type \"{$type}\"."],
                'warnings' => [],
            ];
        }

        $blockType = $this->registry->get($type);
        $allowed = array_keys($blockType->defaultConfig());

        foreach (array_keys($config) as $key) {
            if (! in_array($key, $allowed, true)) {
                $errors[] = "Unknown config key \"{$key}\" for type \"{$type}\".";
            }
        }

        foreach ($this->idPaths($type) as $path) {
            $root = explode('.', $path)[0];
            if (array_key_exists($root, $config) && ! is_array($config[$root])) {
                $errors[] = "{$root} must be an array.";

                continue;
            }

            $this->assertIdList($config, $path, $errors);
        }

        // Structural errors must fail the draft before answer walks or
        // validateConfig (those assume arrays and can throw ErrorException).
        if ($errors !== []) {
            return ['errors' => $errors, 'warnings' => $warnings];
        }

        $this->assertAnswerReferences($type, $config, $errors, $warnings);

        if ($blockType->isAutoGradable() && ($grading === null || $grading === [])) {
            $warnings[] = 'Grading configuration is incomplete; required before publish.';
        }

        try {
            $blockType->validateConfig($config);
        } catch (\Illuminate\Validation\ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $warnings[] = "{$field}: {$message}";
                }
            }
        }

        if ($grading !== null) {
            try {
                $blockType->validateGrading($grading);
            } catch (\Illuminate\Validation\ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $warnings[] = "grading.{$field}: {$message}";
                    }
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @return list<string>
     */
    private function idPaths(string $type): array
    {
        return match ($type) {
            'quiz' => ['questions', 'questions.*.options'],
            'matching' => ['bank', 'slots'],
            'image_labeling' => ['hotspots', 'bank'],
            'cer' => ['fields'],
            'vocabulary_cards' => ['terms'],
            'video' => ['focus_questions'],
            default => [],
        };
    }

    /**
     * @param  list<string>  $errors
     */
    private function assertIdList(array $config, string $path, array &$errors): void
    {
        if ($path === 'questions.*.options') {
            foreach ($config['questions'] ?? [] as $qi => $question) {
                if (! is_array($question)) {
                    $errors[] = "questions.{$qi} must be an object.";

                    continue;
                }
                $this->assertItemsHaveStringIds($question['options'] ?? null, "questions.{$qi}.options", $errors);
            }

            return;
        }

        $this->assertItemsHaveStringIds($config[$path] ?? null, $path, $errors);
    }

    /**
     * @param  list<string>  $errors
     */
    private function assertItemsHaveStringIds(mixed $items, string $path, array &$errors): void
    {
        if ($items === null) {
            return;
        }

        if (! is_array($items)) {
            $errors[] = "{$path} must be an array.";

            return;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $errors[] = "{$path}.{$index} must be an object.";

                continue;
            }

            $id = $item['id'] ?? null;
            if ($id !== null && (! is_string($id) || $id === '')) {
                $errors[] = "{$path}.{$index}.id must be a non-empty string when present.";
            }
        }
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function assertAnswerReferences(string $type, array $config, array &$errors, array &$warnings): void
    {
        if ($type === 'quiz') {
            foreach ($config['questions'] ?? [] as $qi => $question) {
                if (! is_array($question)) {
                    continue;
                }
                $answerId = $question['answer_id'] ?? null;
                if ($answerId === null || $answerId === '') {
                    $warnings[] = "questions.{$qi}.answer_id is empty.";

                    continue;
                }
                $optionIds = array_column(array_filter($question['options'] ?? [], 'is_array'), 'id');
                if (! in_array($answerId, $optionIds, true)) {
                    $warnings[] = "questions.{$qi}.answer_id does not match any option (fix before publish).";
                }
            }
        }

        if ($type === 'matching') {
            $bankIds = array_column(array_filter($config['bank'] ?? [], 'is_array'), 'id');
            foreach ($config['slots'] ?? [] as $si => $slot) {
                if (! is_array($slot)) {
                    continue;
                }
                $answerId = $slot['answer_id'] ?? null;
                if ($answerId !== null && $answerId !== '' && ! in_array($answerId, $bankIds, true)) {
                    $warnings[] = "slots.{$si}.answer_id does not match any bank item (fix before publish).";
                }
            }
        }

        if ($type === 'image_labeling') {
            $bankIds = array_column(array_filter($config['bank'] ?? [], 'is_array'), 'id');
            foreach ($config['hotspots'] ?? [] as $hi => $hotspot) {
                if (! is_array($hotspot)) {
                    continue;
                }
                $answerId = $hotspot['answer_id'] ?? null;
                if ($answerId !== null && $answerId !== '' && ! in_array($answerId, $bankIds, true)) {
                    $warnings[] = "hotspots.{$hi}.answer_id does not match any bank item (fix before publish).";
                }
            }
        }
    }
}
