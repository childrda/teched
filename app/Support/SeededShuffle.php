<?php

namespace App\Support;

/**
 * Deterministic Fisher–Yates shuffle for attempt-stable bank/question order.
 *
 * Algorithm (also implemented in resources/js/lesson-player/seeded-shuffle.js —
 * keep the two in lockstep; the shared fixture test proves they agree):
 *
 * 1. Derive a 32-bit seed: the low 32 bits of an FNV-1a hash of
 *    `shuffle_seed + "\0" + block_id` (UTF-8 bytes).
 * 2. Advance a Mulberry32 PRNG for each Fisher–Yates swap.
 * 3. Shuffle a copy; never mutate the caller's array.
 *
 * Integer ops mirror JavaScript `>>> 0` and `Math.imul` so both runtimes
 * produce identical sequences. Do not use mt_srand global state.
 */
final class SeededShuffle
{
    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public static function shuffle(array $items, string $shuffleSeed, string $blockId): array
    {
        $items = array_values($items);
        $count = count($items);

        if ($count < 2) {
            return $items;
        }

        $state = self::hashSeed($shuffleSeed, $blockId);

        for ($index = $count - 1; $index > 0; $index--) {
            $state = self::mulberry32($state);
            $swap = self::randomIndex($state, $index + 1);

            [$items[$index], $items[$swap]] = [$items[$swap], $items[$index]];
        }

        return $items;
    }

    public static function hashSeed(string $shuffleSeed, string $blockId): int
    {
        $bytes = $shuffleSeed."\0".$blockId;
        $hash = 2166136261;

        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $hash ^= ord($bytes[$i]);
            $hash = self::u32(self::imul($hash, 16777619));
        }

        return $hash;
    }

    /** Mulberry32 step: returns the next state word (not a float). */
    public static function mulberry32(int $state): int
    {
        $t = self::u32($state + 0x6D2B79F5);
        $t = self::u32(self::imul($t ^ self::usr($t, 15), $t | 1));
        $t = self::u32($t ^ self::u32($t + self::imul($t ^ self::usr($t, 7), $t | 61)));

        return self::u32($t ^ self::usr($t, 14));
    }

    public static function randomIndex(int $state, int $bound): int
    {
        return (int) floor(($state / 4294967296) * $bound);
    }

    /** JavaScript Math.imul — 32-bit signed multiply, result as unsigned. */
    private static function imul(int $a, int $b): int
    {
        $a = self::toSigned32($a);
        $b = self::toSigned32($b);

        return (int) (($a * $b) & 0xFFFFFFFF);
    }

    /** JavaScript `>>>` unsigned right shift. */
    private static function usr(int $value, int $shift): int
    {
        return self::u32($value) >> $shift;
    }

    private static function toSigned32(int $value): int
    {
        $value = self::u32($value);

        if ($value >= 0x80000000) {
            return $value - 0x100000000;
        }

        return $value;
    }

    private static function u32(int|float $value): int
    {
        if (is_float($value)) {
            // Keep the low 32 bits when products exceed PHP int range.
            $value = fmod(fmod($value, 4294967296) + 4294967296, 4294967296);
        }

        return (int) $value & 0xFFFFFFFF;
    }
}
