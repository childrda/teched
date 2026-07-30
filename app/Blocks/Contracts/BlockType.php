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

    /**
     * The shape of student response this type accepts at the grading
     * endpoint, or null when it has no submit path yet. Phase 2C wires
     * only 'quiz_answers'; placement types get their own shape in Phase 3.
     */
    public function gradingResponseShape(): ?string;

    /**
     * Validate and normalize publisher-owned grading for this type.
     * Non-gradable types must receive null; reveal keys on them are errors.
     *
     * @param  array<string, mixed>|null  $grading
     * @return array<string, mixed>|null
     *
     * @throws ValidationException
     */
    public function validateGrading(?array $grading): ?array;

    /**
     * Map an internal grading_result (with details[]) into student-safe
     * reveal items. Shape is type-owned — quiz uses question_id; other
     * gradable types must not be forced into quiz vocabulary.
     *
     * @param  array<string, mixed>  $internalResult
     * @param  array<string, mixed>  $compiledConfig
     * @return list<array<string, mixed>>
     */
    public function revealItems(array $internalResult, array $compiledConfig, bool $revealAnswers): array;

    /** Whether this type keeps student working state between visits. */
    public function holdsStudentState(): bool;

    /**
     * Validate and normalize an incoming state payload against the compiled
     * config. Throws ValidationException on anything unrecognized. Returns the
     * state as it should be stored — never the caller's array unchanged.
     *
     * @throws ValidationException
     */
    public function validateState(array $state, array $compiledConfig): array;

    /** Whether stored state satisfies this block on its own terms. */
    public function isStateSatisfied(array $state, array $compiledConfig): bool;

    /**
     * Returns an ordered list of read-aloud (text-to-speech) segments:
     *   [{ id: string, label: string|null, text: string }]
     *
     * `text` is plain text — all markup stripped and whitespace collapsed.
     * `label` is an optional spoken lead-in ("Question 1", "Definition").
     * Blocks with nothing to read return an empty list.
     *
     * Receives a REDACTED config (the output of redactConfig()), never the
     * raw compiled config, so answers and feedback can never reach speech.
     * Must not mutate the given array.
     *
     * @return list<array{id: string, label: ?string, text: string}>
     */
    public function speakableText(array $redactedConfig): array;
}
