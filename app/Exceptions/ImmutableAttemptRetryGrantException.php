<?php

namespace App\Exceptions;

use RuntimeException;

class ImmutableAttemptRetryGrantException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            "Attempt retry grants are immutable: cannot {$operation} an existing AttemptRetryGrant. ".
            'Create another grant instead.'
        );
    }
}
