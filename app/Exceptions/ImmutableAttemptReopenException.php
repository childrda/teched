<?php

namespace App\Exceptions;

use RuntimeException;

class ImmutableAttemptReopenException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            "Attempt reopens are immutable: cannot {$operation} an existing AttemptReopen. ".
            'Record another reopen instead.'
        );
    }
}
