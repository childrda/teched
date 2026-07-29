<?php

namespace App\Exceptions;

use RuntimeException;

class UnknownBlockTypeException extends RuntimeException
{
    /**
     * @param list<string> $registered
     */
    public static function forKey(string $key, array $registered): self
    {
        $known = $registered === [] ? '(none)' : implode(', ', $registered);

        return new self(
            "Unknown block type \"{$key}\". It is not registered in the BlockTypeRegistry. " .
            "Registered types: {$known}. Register the type in BlockTypeServiceProvider before publishing or reading it."
        );
    }
}
