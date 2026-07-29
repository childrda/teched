<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;
use Illuminate\Validation\Validator;

class VocabularyCardsBlock extends AbstractBlockType
{
    public function key(): string
    {
        return 'vocabulary_cards';
    }

    public function label(): string
    {
        return 'Vocabulary Cards';
    }

    public function isAutoGradable(): bool
    {
        return false;
    }

    public function collectsResponse(): bool
    {
        return false;
    }

    public function configRules(): array
    {
        return [
            'terms' => ['required', 'array', 'min:1'],
            'terms.*.id' => ['required', 'string'],
            'terms.*.term' => ['required', 'string'],
            'terms.*.definition' => ['required', 'string'],
            'terms.*.analogy' => ['nullable', 'string'],
            'reveal_mode' => ['required', 'string', 'in:tap,always'],
        ];
    }

    protected function afterValidation(Validator $validator, array $config): void
    {
        $this->assertDistinctIds($validator, $config['terms'] ?? [], 'terms');
    }

    public function defaultConfig(): array
    {
        return [
            'terms' => [
                [
                    'id' => 'term-1',
                    'term' => 'Term',
                    'definition' => 'Definition of the term.',
                    'analogy' => null,
                ],
            ],
            'reveal_mode' => 'tap',
        ];
    }

    public function compileConfig(array $validatedConfig): array
    {
        return [
            'terms' => array_values(array_map(
                fn (array $t) => [
                    'id' => $t['id'],
                    'term' => $t['term'],
                    'definition' => $t['definition'],
                    'analogy' => $t['analogy'] ?? null,
                ],
                $validatedConfig['terms']
            )),
            'reveal_mode' => $validatedConfig['reveal_mode'],
        ];
    }

    public function speakableText(array $redactedConfig): array
    {
        $segments = [];

        foreach (array_values($redactedConfig['terms'] ?? []) as $index => $term) {
            $id = $term['id'] ?? $index;

            $this->pushSegment($segments, "{$id}:term", 'Term ' . ($index + 1), $term['term'] ?? null);
            $this->pushSegment($segments, "{$id}:definition", 'Definition', $term['definition'] ?? null);
            $this->pushSegment($segments, "{$id}:analogy", 'Analogy', $term['analogy'] ?? null);
        }

        return $segments;
    }
}
