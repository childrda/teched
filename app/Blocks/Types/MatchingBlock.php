<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;
use Illuminate\Validation\Validator;

/**
 * Students match a bank item's label to a slot's description.
 *
 * Bank items and slots carry independent IDs, and the correct pairing lives
 * only in each slot's answer_id, which redaction strips. A student who reads
 * the published manifest therefore learns every label and every description
 * but not which belong together. An earlier shape stored one row per pair,
 * which put each label beside its own answer in what students received.
 *
 * The response maps a slot ID to the chosen bank item ID.
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
            'bank' => ['required', 'array', 'min:2'],
            'bank.*.id' => ['required', 'string'],
            'bank.*.label' => ['required', 'string'],
            'slots' => ['required', 'array', 'min:2'],
            'slots.*.id' => ['required', 'string'],
            'slots.*.description' => ['required', 'string'],
            'slots.*.answer_id' => ['required', 'string'],
            'shuffle' => ['required', 'boolean'],
        ];
    }

    protected function afterValidation(Validator $validator, array $config): void
    {
        $bank = is_array($config['bank'] ?? null) ? $config['bank'] : [];
        $slots = is_array($config['slots'] ?? null) ? $config['slots'] : [];

        $this->assertDistinctIds($validator, $bank, 'bank');
        $this->assertDistinctIds($validator, $slots, 'slots');

        $bankIds = array_column(array_filter($bank, 'is_array'), 'id');

        foreach ($slots as $index => $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $answerId = $slot['answer_id'] ?? null;

            if ($answerId !== null && ! in_array($answerId, $bankIds, true)) {
                $validator->errors()->add(
                    "slots.{$index}.answer_id",
                    "Slot answer_id \"{$answerId}\" does not reference an item in this block's bank."
                );
            }

            // A shared ID would survive redaction and tell a student which
            // item answers the slot, which is the whole point of redacting
            // answer_id in the first place.
            $slotId = $slot['id'] ?? null;

            if (is_string($slotId) && in_array($slotId, $bankIds, true)) {
                $validator->errors()->add(
                    "slots.{$index}.id",
                    "Slot id \"{$slotId}\" is also a bank item id; a shared id would reveal the answer."
                );
            }
        }
    }

    public function defaultConfig(): array
    {
        return [
            'instructions' => 'Match each term to its description.',
            'bank' => [
                ['id' => 'item-1', 'label' => 'Term A'],
                ['id' => 'item-2', 'label' => 'Term B'],
            ],
            'slots' => [
                ['id' => 'slot-1', 'description' => 'Description A', 'answer_id' => 'item-1'],
                ['id' => 'slot-2', 'description' => 'Description B', 'answer_id' => 'item-2'],
            ],
            'shuffle' => true,
        ];
    }

    public function compileConfig(array $validatedConfig): array
    {
        return [
            'instructions' => $validatedConfig['instructions'] ?? null,
            'bank' => $this->orderBankByLabel(array_map(
                fn (array $item) => ['id' => $item['id'], 'label' => $item['label']],
                $validatedConfig['bank']
            )),
            'slots' => array_values(array_map(
                fn (array $slot) => [
                    'id' => $slot['id'],
                    'description' => $slot['description'],
                    'answer_id' => $slot['answer_id'],
                ],
                $validatedConfig['slots']
            )),
            'shuffle' => (bool) $validatedConfig['shuffle'],
        ];
    }

    public function redactConfig(array $compiledConfig): array
    {
        $redacted = $compiledConfig;

        $redacted['slots'] = array_map(function (array $slot) {
            unset($slot['answer_id']);

            return $slot;
        }, $redacted['slots']);

        return $redacted;
    }

    public function grade(array $compiledConfig, ?array $grading, array $response): ?array
    {
        $matches = $response['matches'] ?? [];

        $details = array_map(function (array $slot) use ($matches) {
            $chosen = $matches[$slot['id']] ?? null;

            return [
                'item_id' => $slot['id'],
                'correct' => $chosen === $slot['answer_id'],
                'feedback' => null,
            ];
        }, $compiledConfig['slots']);

        return $this->buildGradingResult(array_values($details), $grading);
    }

    /**
     * Reads the instructions, then the bank labels in compiled order, then
     * the slot descriptions — separate groups rather than
     * label-followed-by-answer. Segments carry stable ids, so highlighting
     * follows each spoken item even when the player has shuffled the bank —
     * but the spoken order of terms is manifest order, not display order.
     */
    public function speakableText(array $redactedConfig): array
    {
        $segments = [];

        $this->pushSegment($segments, 'instructions', 'Instructions', $redactedConfig['instructions'] ?? null);

        foreach (array_values($redactedConfig['bank'] ?? []) as $index => $item) {
            $this->pushSegment(
                $segments,
                ($item['id'] ?? $index) . ':label',
                'Term',
                $item['label'] ?? null
            );
        }

        foreach (array_values($redactedConfig['slots'] ?? []) as $index => $slot) {
            $this->pushSegment(
                $segments,
                ($slot['id'] ?? $index) . ':description',
                'Description',
                $slot['description'] ?? null
            );
        }

        return $segments;
    }
}
