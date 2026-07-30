<?php

namespace App\Exceptions;

use RuntimeException;

class ImmutableBlockSubmissionException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            "Block submissions are immutable: cannot {$operation} an existing BlockSubmission. ".
            'Create a new submission row instead.'
        );
    }
}
