<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Draft or publish validation failures with human-addressed messages
 * ("Page Title / Block Type / field: reason").
 */
class AuthoringValidationException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $warnings = [],
        string $message = 'Authoring validation failed.',
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public static function with(array $errors, array $warnings = []): self
    {
        return new self($errors, $warnings);
    }
}
