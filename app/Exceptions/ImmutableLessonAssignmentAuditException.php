<?php

namespace App\Exceptions;

use RuntimeException;

class ImmutableLessonAssignmentAuditException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self("Lesson assignment audit rows are immutable and cannot be {$operation}d.");
    }
}
