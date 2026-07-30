<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Raised when a page-scoped save payload tries to set fields that only the
 * route / loaded models may determine (lesson_id, page_id, siblings, etc.).
 */
class AuthoringPayloadException extends InvalidArgumentException
{
    public static function forbidden(string $field): self
    {
        return new self("savePage() payload must not include [{$field}].");
    }
}
