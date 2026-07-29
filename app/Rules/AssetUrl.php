<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an image/asset location: either an absolute http/https URL
 * or a root-relative path beginning with "/" (uploaded assets are served
 * from /storage/...). Protocol-relative ("//...") values and dangerous
 * schemes (javascript:, data:, ...) are rejected.
 */
class AssetUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be a non-empty string.');

            return;
        }

        // Absolute http/https URL.
        if (preg_match('#^https?://#i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return;
        }

        // Root-relative path ("/storage/..."), but never protocol-relative ("//...").
        if (preg_match('#^/(?!/)#', $value) === 1) {
            return;
        }

        $fail('The :attribute must be an absolute http/https URL or a root-relative path starting with "/".');
    }
}
