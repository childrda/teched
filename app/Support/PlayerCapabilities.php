<?php

namespace App\Support;

/**
 * Explicit player capability bag. Controllers and JS modules branch on these
 * flags only — never on preview mode, attempt presence, or token presence.
 *
 * @phpstan-type Caps array{
 *     canPersist: bool,
 *     canGrade: bool,
 *     canAdvancePersistently: bool,
 *     bypassCompletionGates: bool
 * }
 */
final class PlayerCapabilities
{
    /**
     * @return Caps
     */
    public static function forPlay(): array
    {
        return [
            'canPersist' => true,
            'canGrade' => true,
            'canAdvancePersistently' => true,
            'bypassCompletionGates' => false,
        ];
    }

    /**
     * Draft preview and staff assignment pin preview — local navigation only.
     *
     * @return Caps
     */
    public static function forPreview(): array
    {
        return [
            'canPersist' => false,
            'canGrade' => false,
            'canAdvancePersistently' => false,
            'bypassCompletionGates' => true,
        ];
    }

    /**
     * Completed / withdrawn history — view only, no further writes.
     *
     * @return Caps
     */
    public static function forReadOnly(): array
    {
        return [
            'canPersist' => false,
            'canGrade' => false,
            'canAdvancePersistently' => false,
            'bypassCompletionGates' => false,
        ];
    }
}
