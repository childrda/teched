<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Every failure mode of GradingToken::resolve() collapses to this type so
 * the grading endpoint can return one indistinguishable 422. Distinguishing
 * a forged token from a real but unknown version_id is exactly what must
 * not be observable.
 */
class InvalidGradingToken extends RuntimeException
{
    public static function make(): self
    {
        return new self('The grading session is invalid.');
    }
}
