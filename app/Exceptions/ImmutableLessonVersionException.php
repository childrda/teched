<?php

namespace App\Exceptions;

use RuntimeException;

class ImmutableLessonVersionException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            "Lesson versions are immutable: cannot {$operation} an existing LessonVersion. " .
            'Republish the lesson to create a new version instead.'
        );
    }
}
