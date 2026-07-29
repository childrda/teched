<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;
use Illuminate\Validation\Validator;

/**
 * Students match each pair's label to its description. The response maps
 * a slot (pair id) to the chosen pair id; a match is correct when the
 * chosen id equals the slot's own id.
 */
class MatchingBlock extends AbstractBlockType
{
    public function key(): string
    {
        return 'matching';
    }

    public function label(): string
    {
        return 'Matching';
    }

    public function isAutoGradable(): bool
    {
        return true;
    }

    public function collectsResponse(): bool
    {
        return true;
    }

    public function configRules(): array
    {
        return [
            'instructions' => ['nullable', 'string'],
            'pairs' => ['required', 'array', 'min:2'],
            'pairs.*.id' => ['required', 'string'],
            'pairs.*.label' => ['required', 'string'],
            'pairs.*.description' => ['required', 'string'],
            'shuffle' => ['required', 'boolean'],
        ];
    }

    protected function afterValidation(Validator $validator, array $config): void
    {
        $this->assertDistinctIds($validator, $config['pairs'] ?? [], 'pairs');
    }

    public function defaultConfig(): array
    {
        return [
            'instructions' => 'Match each term to its description.',
            'pairs' => [
                ['id' => 'pair-1', 'label' => 'Term A', 'description' => 'Description A'],
                ['id' => 'pair-2', 'label' => 'Term B', 'description' => 'Description B'],
            ],
            'shuffle' => true,
        ];
    }

    public function compileConfig(array $validatedConfig): array
    {
        return [
            'instructions' => $validatedConfig['instructions'] ?? null,
            'pairs' => array_values(array_map(
                fn (array $p) => [
                    'id' => $p['id'],
                    'label' => $p['label'],
                    'description' => $p['description'],
                ],
                $validatedConfig['pairs']
            )),
            'shuffle' => (bool) $validatedConfig['shuffle'],
        ];
    }

    public function grade(array $compiledConfig, ?array $grading, array $response): ?array
    {
        $matches = $response['matches'] ?? [];

        $details = array_map(function (array $pair) use ($matches) {
            $chosen = $matches[$pair['id']] ?? null;

            return [
                'item_id' => $pair['id'],
                'correct' => $chosen === $pair['id'],
                'feedback' => null,
            ];
        }, $compiledConfig['pairs']);

        return $this->buildGradingResult(array_values($details), $grading);
    }

    /**
     * Reads the instructions, then the terms, then the descriptions as
     * separate groups rather than term-followed-by-its-description.
     * Segments carry stable ids, so a player that shuffles speaks each
     * group in the order it actually rendered.
     */
    public function speakableText(array $redactedConfig): array
    {
        $segments = [];
        $pairs = array_values($redactedConfig['pairs'] ?? []);

        $this->pushSegment($segments, 'instructions', 'Instructions', $redactedConfig['instructions'] ?? null);

        foreach ($pairs as $index => $pair) {
            $this->pushSegment(
                $segments,
                ($pair['id'] ?? $index) . ':label',
                'Term',
                $pair['label'] ?? null
            );
        }

        foreach ($pairs as $index => $pair) {
            $this->pushSegment(
                $segments,
                ($pair['id'] ?? $index) . ':description',
                'Description',
                $pair['description'] ?? null
            );
        }

        return $segments;
    }
}
