<?php

namespace App\Exceptions;

use RuntimeException;

class StaleLessonEditException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'This lesson was changed in another tab or by another author. Reload the page and try again.'
        );
    }
}
