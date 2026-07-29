<?php

namespace App\Blocks\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * A lesson block type. Implementations own ONLY their config shape:
 * the publisher owns the { block_id, type, config, grading } wrapper,
 * so block classes must never construct block_id, type, or grading.
 */
interface BlockType
{
    /** Stable string key stored in lesson_blocks.type and the manifest. */
    public function key(): string;

    /** Human-readable label for authoring UIs. */
    public function label(): string;

    /** True when grade() performs real scoring. */
    public function isAutoGradable(): bool;

    /** True when a student produces stored response data. */
    public function collectsResponse(): bool;

    /** Base Laravel validation rules for the author-side config. */
    public function configRules(): array;

    /**
     * Applies configRules() plus cross-field checks (via validator after
     * hooks). Returns the validated config.
     *
     * @throws ValidationException
     */
    public function validateConfig(array $config): array;

    /** A valid starting config for a newly created block. */
    public function defaultConfig(): array;

    /** Transforms a validated author config into its published manifest form. */
    public function compileConfig(array $validatedConfig): array;

    /**
     * Returns a student-safe copy of a compiled config with all answer
     * keys, feedback, rubrics, and source references removed. Must not
     * mutate the given array.
     */
    public function redactConfig(array $compiledConfig): array;

    /**
     * Grades a student response against the compiled config. Returns the
     * standard grading result array, or null when the type is not
     * auto-gradable.
     */
    public function grade(array $compiledConfig, ?array $grading, array $response): ?array;
}
