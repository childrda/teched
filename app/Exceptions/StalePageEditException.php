<?php

namespace App\Exceptions;

use RuntimeException;

class StalePageEditException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'This page was changed in another tab or by another author. Reload the page and try again.'
        );
    }
}
