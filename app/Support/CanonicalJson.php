<?php

namespace App\Support;

/**
 * Canonical JSON encoding for deep equality of student state / response
 * payloads. Key order from a JSON round-trip is not meaningful, so arrays
 * are sorted recursively before encoding. Strict === on raw PHP arrays would
 * treat a reordered-but-identical structure as a change; loose == would treat
 * "" and null as equal.
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR);
    }

    public static function equal(mixed $left, mixed $right): bool
    {
        return self::encode($left) === self::encode($right);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => self::canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child);
        }

        return $value;
    }
}
