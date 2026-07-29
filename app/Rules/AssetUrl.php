<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an asset location: either an absolute http/https URL or a
 * root-relative path beginning with "/" (uploaded assets are served from
 * /storage/...). Protocol-relative values and dangerous schemes
 * (javascript:, data:, ...) are rejected.
 */
class AssetUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be a non-empty string.');

            return;
        }

        // Protocol-relative values never pass, including the "/\" form that
        // browsers normalize into one.
        if (str_starts_with($value, '//') || str_starts_with($value, '/\\')) {
            $fail('The :attribute must not be a protocol-relative URL.');

            return;
        }

        // Absolute http/https URL.
        if (preg_match('#^https?://#i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return;
        }

        // Root-relative path ("/storage/...").
        if (str_starts_with($value, '/')) {
            return;
        }

        $fail('The :attribute must be an absolute http/https URL or a root-relative path starting with "/".');
    }
}
