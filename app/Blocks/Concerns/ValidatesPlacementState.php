<?php

namespace App\Blocks\Concerns;

use Illuminate\Validation\ValidationException;

trait ValidatesPlacementState
{
    /**
     * Slot-id → item-id map (null = empty). Every slot and item id must exist
     * in the compiled config; no item may occupy two slots.
     *
     * @param  list<array<string, mixed>>  $slots
     * @param  list<array<string, mixed>>  $bank
     * @return array<string, string|null>
     */
    protected function validatePlacementMap(array $state, array $slots, array $bank, string $mapKey): array
    {
        if (! array_key_exists($mapKey, $state) || ! is_array($state[$mapKey])) {
            throw ValidationException::withMessages([
                "state.{$mapKey}" => "State must include a {$mapKey} object.",
            ]);
        }

        $map = $state[$mapKey];
        $slotIds = [];

        foreach ($slots as $slot) {
            if (is_array($slot) && is_string($slot['id'] ?? null)) {
                $slotIds[$slot['id']] = true;
            }
        }

        $bankIds = [];

        foreach ($bank as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $bankIds[$item['id']] = true;
            }
        }

        $normalized = [];
        $usedItems = [];

        foreach ($map as $slotId => $itemId) {
            if (! is_string($slotId) || ! isset($slotIds[$slotId])) {
                throw ValidationException::withMessages([
                    "state.{$mapKey}" => "Unknown slot id \"{$slotId}\".",
                ]);
            }

            if ($itemId !== null && ! is_string($itemId)) {
                throw ValidationException::withMessages([
                    "state.{$mapKey}.{$slotId}" => 'Each placement must be a string item id or null.',
                ]);
            }

            if (is_string($itemId)) {
                if (! isset($bankIds[$itemId])) {
                    throw ValidationException::withMessages([
                        "state.{$mapKey}.{$slotId}" => "Unknown item id \"{$itemId}\".",
                    ]);
                }

                if (isset($usedItems[$itemId])) {
                    throw ValidationException::withMessages([
                        "state.{$mapKey}.{$slotId}" => "Item \"{$itemId}\" is placed in more than one slot.",
                    ]);
                }

                $usedItems[$itemId] = true;
            }

            $normalized[$slotId] = $itemId;
        }

        // Unknown keys already rejected; require every configured slot so the
        // stored map is complete and restore does not invent empties.
        foreach (array_keys($slotIds) as $slotId) {
            if (! array_key_exists($slotId, $normalized)) {
                throw ValidationException::withMessages([
                    "state.{$mapKey}.{$slotId}" => 'Every slot must be present in the state map.',
                ]);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, string|null>  $map
     * @param  list<array<string, mixed>>  $slots
     */
    protected function placementMapIsComplete(array $map, array $slots): bool
    {
        foreach ($slots as $slot) {
            if (! is_array($slot) || ! is_string($slot['id'] ?? null)) {
                return false;
            }

            $itemId = $map[$slot['id']] ?? null;

            if (! is_string($itemId) || $itemId === '') {
                return false;
            }
        }

        return $slots !== [];
    }
}
