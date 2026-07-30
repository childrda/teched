<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

/**
 * Student working state must round-trip exactly — leading/trailing spaces are
 * part of what was typed. Autosave payloads under "state" are therefore never
 * trimmed; satisfaction still uses trim() when evaluating length.
 */
class TrimStrings extends Middleware
{
    protected function transform($key, $value)
    {
        if (is_string($key) && (str_starts_with($key, 'state') || str_starts_with($key, 'state.'))) {
            return $value;
        }

        return parent::transform($key, $value);
    }
}
