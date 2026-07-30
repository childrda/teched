<?php

namespace App\Blocks\Concerns;

use Illuminate\Validation\ValidationException;

trait ValidatesStudentTextState
{
    private const MAX_TEXT_CHARS = 20000;

    /**
     * Normalize line endings only — do not trim. Restored text must match
     * what was on screen, including leading/trailing whitespace.
     */
    protected function normalizeStudentText(mixed $value, string $attribute): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages([
                $attribute => 'State text must be a string.',
            ]);
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $value);

        if (mb_strlen($normalized) > self::MAX_TEXT_CHARS) {
            throw ValidationException::withMessages([
                $attribute => 'State text may not exceed '.self::MAX_TEXT_CHARS.' characters.',
            ]);
        }

        return $normalized;
    }

    /**
     * Satisfaction uses the trimmed length; null min_length means non-empty.
     * Mirrors resources/js/lesson-player/response-field.js.
     */
    protected function textMeetsMinLength(string $value, mixed $minLength): bool
    {
        $trimmed = trim($value);

        if ($minLength === null) {
            return $trimmed !== '';
        }

        if (! is_int($minLength) && ! (is_string($minLength) && is_numeric($minLength))) {
            return $trimmed !== '';
        }

        $minimum = (int) $minLength;

        if ($minimum < 0) {
            return $trimmed !== '';
        }

        return mb_strlen($trimmed) >= $minimum;
    }
}
