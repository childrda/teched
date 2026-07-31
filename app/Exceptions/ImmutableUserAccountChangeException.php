<?php

namespace App\Exceptions;

use RuntimeException;

class ImmutableUserAccountChangeException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self("UserAccountChange rows are immutable; {$operation} is not allowed.");
    }
}
