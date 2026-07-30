<?php

namespace App\Exceptions;

use RuntimeException;

class ImmutableLessonOwnerChangeException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self("LessonOwnerChange rows are immutable; {$operation} is not allowed.");
    }
}
