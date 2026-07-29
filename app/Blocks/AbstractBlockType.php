<?php

namespace App\Blocks;

use App\Blocks\Contracts\BlockType;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

abstract class AbstractBlockType implements BlockType
{
    public function validateConfig(array $config): array
    {
        $validator = ValidatorFacade::make($config, $this->configRules());

        $validator->after(function (Validator $v) use ($config) {
            $this->afterValidation($v, $config);
        });

        return $validator->validate();
    }

    /**
     * Cross-field checks that plain rules cannot express. Add errors to
     * the validator; any error causes validateConfig() to throw.
     */
    protected function afterValidation(Validator $validator, array $config): void
    {
        // No cross-field checks by default.
    }

    public function compileConfig(array $validatedConfig): array
    {
        return $validatedConfig;
    }

    public function redactConfig(array $compiledConfig): array
    {
        return $compiledConfig;
    }

    public function grade(array $compiledConfig, ?array $grading, array $response): ?array
    {
        return null;
    }

    /** Tags that sit inside a sentence and must not introduce a space. */
    private const INLINE_TAGS = 'a|abbr|b|cite|code|em|i|q|s|small|span|strong|sub|sup|u|var';

    /**
     * Converts author markup (or plain text) into speech-ready plain text:
     * tags removed, entities decoded, whitespace collapsed. Inline tags
     * vanish so words and punctuation stay intact, while every other tag
     * becomes a space so adjacent blocks never run their words together.
     */
    protected function toPlainText(?string $html): string
    {
        if ($html === null) {
            return '';
        }

        $text = preg_replace('#</?(?:' . self::INLINE_TAGS . ')(?:\s[^>]*)?>#i', '', $html) ?? $html;
        $text = preg_replace('/<[^>]*>/', ' ', $text) ?? '';
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim(preg_replace('/ +([.,;:!?])/u', '$1', $text) ?? $text);
    }

    /**
     * Appends a speech segment, skipping it when there is nothing to say.
     *
     * @param list<array{id: string, label: ?string, text: string}> $segments
     */
    protected function pushSegment(array &$segments, string $id, ?string $label, ?string $text): void
    {
        $plainText = $this->toPlainText($text);

        if ($plainText === '') {
            return;
        }

        $plainLabel = $this->toPlainText($label);

        $segments[] = [
            'id' => $id,
            'label' => $plainLabel === '' ? null : $plainLabel,
            'text' => $plainText,
        ];
    }

    /** Spoken option letters: A, B, ... Z, AA, AB, ... */
    protected function optionLetter(int $index): string
    {
        $letter = '';

        for ($n = $index; $n >= 0; $n = intdiv($n, 26) - 1) {
            $letter = chr(65 + ($n % 26)) . $letter;
        }

        return $letter;
    }

    /**
     * Asserts every ['id' => ...] entry in $items is a non-empty string and
     * distinct within the list.
     *
     * @param array<int, mixed> $items
     */
    protected function assertDistinctIds(Validator $validator, array $items, string $attribute): void
    {
        $seen = [];

        foreach ($items as $index => $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;

            if (! is_string($id) || trim($id) === '') {
                $validator->errors()->add(
                    "{$attribute}.{$index}.id",
                    "Each {$attribute} item must have a non-empty string id."
                );

                continue;
            }

            if (isset($seen[$id])) {
                $validator->errors()->add(
                    "{$attribute}.{$index}.id",
                    "Duplicate id \"{$id}\" in {$attribute}; stable IDs must be distinct within their scope."
                );
            }

            $seen[$id] = true;
        }
    }

    /**
     * Assembles the standard grading result from per-item detail rows of
     * shape { item_id, correct, feedback }.
     *
     * @param list<array{item_id: string, correct: bool, feedback: ?string}> $details
     */
    protected function buildGradingResult(array $details, ?array $grading): array
    {
        $maxScore = count($details);
        $score = count(array_filter($details, fn (array $d) => $d['correct']));
        $percentage = $maxScore > 0 ? (int) round($score / $maxScore * 100) : 0;
        $allCorrect = $score === $maxScore;

        $rule = $grading['rule'] ?? 'all_correct';
        $passed = match ($rule) {
            'min_score' => $percentage >= (int) ($grading['min_score'] ?? 100),
            'completion_only' => true,
            default => $allCorrect,
        };

        if (! (bool) ($grading['show_feedback'] ?? true)) {
            $details = array_map(
                fn (array $d) => array_merge($d, ['feedback' => null]),
                $details
            );
        }

        return [
            'correct' => $allCorrect,
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'passed' => $passed,
            'requires_manual_review' => false,
            'details' => array_values($details),
        ];
    }
}
