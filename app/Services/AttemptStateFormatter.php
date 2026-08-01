<?php

namespace App\Services;

/**
 * Turns a stored block payload — a draft state row or a submitted response —
 * into presentation-ready lines for the teacher attempt screen.
 *
 * Draft state and submitted response are shaped independently per block type,
 * so each type below names every top-level key it accepts rather than
 * inferring one from the other. As verified against each type's own
 * validateState() and grade(), plus the two writers of block_submissions:
 *
 *   short_response  draft {value}            submitted {value}      (state snapshot)
 *   cer             draft {values}           submitted {values}     (state snapshot)
 *   quiz            draft {answers}          submitted {answers}    (graded path)
 *   matching        draft {placements}       grade() reads {matches}
 *   image_labeling  draft {placements}       grade() reads {placements}
 *
 * Matching is why the placement types accept both keys: MatchingBlock::grade()
 * reads `matches` while its validateState() writes `placements`, and no submit
 * path produces a matching submission yet. Accepting either means this keeps
 * working whichever key Phase 3 settles on.
 *
 * This is teacher-facing internal tooling: it resolves the full detail,
 * including anything the student-safe mapper would strip. It must never be
 * routed through a student-facing formatter.
 */
class AttemptStateFormatter
{
    public const MODE_TEXT = 'text';

    public const MODE_FIELDS = 'fields';

    public const MODE_PLACEMENTS = 'placements';

    public const MODE_ANSWERS = 'answers';

    /** The payload's overall shape was not recognized; show it verbatim. */
    public const MODE_RAW = 'raw';

    /** Nothing stored at all. */
    public const MODE_EMPTY = 'empty';

    /**
     * One structure for every block type, and the same one for a draft and for
     * each submission — Blade branches on `mode` only, never on block type.
     *
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $config
     * @return array{mode: string, items: list<array{label: ?string, value: ?string, resolved: bool}>, raw: ?string, has_unresolved_values: bool}
     */
    public function format(string $blockType, ?array $payload, array $config): array
    {
        if ($payload === null || $payload === []) {
            return $this->empty();
        }

        return match ($blockType) {
            'short_response' => $this->shortResponse($payload),
            'cer' => $this->cer($payload, $config),
            'matching' => $this->placements($payload, $config, 'slots', false),
            'image_labeling' => $this->placements($payload, $config, 'hotspots', true),
            'quiz' => $this->quiz($payload, $config),
            default => $this->raw($payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shortResponse(array $payload): array
    {
        if (! array_key_exists('value', $payload)) {
            return $this->raw($payload);
        }

        $value = $payload['value'];

        if ($value !== null && ! is_string($value)) {
            return $this->raw($payload);
        }

        return $this->result(self::MODE_TEXT, [
            $this->item(null, $this->textOrBlank($value)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     */
    private function cer(array $payload, array $config): array
    {
        if (! is_array($payload['values'] ?? null)) {
            return $this->raw($payload);
        }

        $values = $payload['values'];
        $items = [];
        $seen = [];

        foreach ($this->rows($config, 'fields') as $field) {
            $id = $this->id($field);

            if ($id === null) {
                continue;
            }

            $seen[$id] = true;

            if (! array_key_exists($id, $values)) {
                continue;
            }

            $items[] = $this->item(
                $this->label($field, 'label', $id),
                $this->textOrBlank($values[$id]),
            );
        }

        // Anything the current config no longer describes still gets a line —
        // one unresolved key must not hide the rest of the response.
        foreach ($values as $id => $value) {
            if (isset($seen[$id])) {
                continue;
            }

            $items[] = $this->item(
                __('staff.unknown_field', ['id' => $id]),
                $this->textOrBlank($value),
                false,
            );
        }

        return $this->result(self::MODE_FIELDS, $items);
    }

    /**
     * Matching and image labeling share one map of target id => bank item id.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     */
    private function placements(array $payload, array $config, string $targetKey, bool $numbered): array
    {
        $map = null;

        foreach (['placements', 'matches'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $map = $payload[$key];

                break;
            }
        }

        if ($map === null) {
            return $this->raw($payload);
        }

        $bank = [];

        foreach ($this->rows($config, 'bank') as $entry) {
            $id = $this->id($entry);

            if ($id !== null) {
                $bank[$id] = $this->label($entry, 'label', $id);
            }
        }

        $items = [];
        $seen = [];

        foreach ($this->rows($config, $targetKey) as $target) {
            $id = $this->id($target);

            if ($id === null) {
                continue;
            }

            $seen[$id] = true;

            $label = $this->label($target, 'description', $id);

            if ($numbered && is_int($target['number'] ?? null)) {
                $label = $target['number'].'. '.$label;
            }

            $placed = $map[$id] ?? null;

            if (! is_string($placed)) {
                $items[] = $this->item($label, __('staff.response_not_placed'));

                continue;
            }

            $items[] = array_key_exists($placed, $bank)
                ? $this->item($label, $bank[$placed])
                : $this->item($label, __('staff.unknown_bank_item', ['id' => $placed]), false);
        }

        foreach ($map as $id => $placed) {
            if (isset($seen[$id])) {
                continue;
            }

            $unknownTarget = $numbered
                ? __('staff.unknown_hotspot', ['id' => $id])
                : __('staff.unknown_slot', ['id' => $id]);

            $items[] = $this->item(
                $unknownTarget,
                is_string($placed) && array_key_exists($placed, $bank)
                    ? $bank[$placed]
                    : (is_string($placed)
                        ? __('staff.unknown_bank_item', ['id' => $placed])
                        : __('staff.response_not_placed')),
                false,
            );
        }

        return $this->result(self::MODE_PLACEMENTS, $items);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     */
    private function quiz(array $payload, array $config): array
    {
        if (! is_array($payload['answers'] ?? null)) {
            return $this->raw($payload);
        }

        $answers = $payload['answers'];
        $items = [];
        $seen = [];

        foreach ($this->rows($config, 'questions') as $question) {
            $id = $this->id($question);

            if ($id === null) {
                continue;
            }

            $seen[$id] = true;

            $prompt = $this->label($question, 'prompt', $id);
            $chosen = $answers[$id] ?? null;

            if (! is_string($chosen)) {
                $items[] = $this->item($prompt, __('staff.response_not_answered'));

                continue;
            }

            // Options are this question's own, never a shared pool.
            $text = null;

            foreach ($this->rows($question, 'options') as $option) {
                if ($this->id($option) === $chosen) {
                    $text = $this->label($option, 'text', $chosen);

                    break;
                }
            }

            $items[] = $text === null
                ? $this->item($prompt, __('staff.unknown_option', ['id' => $chosen]), false)
                : $this->item($prompt, $text);
        }

        foreach ($answers as $id => $chosen) {
            if (isset($seen[$id])) {
                continue;
            }

            $items[] = $this->item(
                __('staff.unknown_question', ['id' => $id]),
                is_string($chosen)
                    ? __('staff.unknown_option', ['id' => $chosen])
                    : __('staff.response_not_answered'),
                false,
            );
        }

        return $this->result(self::MODE_ANSWERS, $items);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function raw(array $payload): array
    {
        return [
            'mode' => self::MODE_RAW,
            'items' => [],
            'raw' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'has_unresolved_values' => true,
        ];
    }

    private function empty(): array
    {
        return [
            'mode' => self::MODE_EMPTY,
            'items' => [],
            'raw' => null,
            'has_unresolved_values' => false,
        ];
    }

    /**
     * @param  list<array{label: ?string, value: ?string, resolved: bool}>  $items
     */
    private function result(string $mode, array $items): array
    {
        return [
            'mode' => $mode,
            'items' => $items,
            'raw' => null,
            'has_unresolved_values' => collect($items)->contains(fn (array $item) => $item['resolved'] === false),
        ];
    }

    /**
     * @return array{label: ?string, value: ?string, resolved: bool}
     */
    private function item(?string $label, ?string $value, bool $resolved = true): array
    {
        return ['label' => $label, 'value' => $value, 'resolved' => $resolved];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    private function rows(array $config, string $key): array
    {
        if (! is_array($config[$key] ?? null)) {
            return [];
        }

        return array_values(array_filter($config[$key], 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function id(array $row): ?string
    {
        return is_string($row['id'] ?? null) ? $row['id'] : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function label(array $row, string $key, string $fallback): string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : $fallback;
    }

    private function textOrBlank(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return __('staff.response_blank');
        }

        return $value;
    }
}
