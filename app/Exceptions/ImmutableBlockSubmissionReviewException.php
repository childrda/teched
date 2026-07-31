<?php

namespace App\Exceptions;

use RuntimeException;

class ImmutableBlockSubmissionReviewException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            "Block submission reviews are immutable: cannot {$operation} an existing BlockSubmissionReview. ".
            'Create another review instead.'
        );
    }
}
