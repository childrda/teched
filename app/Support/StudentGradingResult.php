<?php

namespace App\Support;

use App\Blocks\Contracts\BlockType;
use App\Models\BlockSubmission;

/**
 * Maps internal grading results to the six-key student result object and the
 * grading envelope ({ result, attempts }) used by the grading endpoint and
 * the resume payload.
 */
class StudentGradingResult
{
    /**
     * Six-key public result. `reveal` is always present — null until earned.
     *
     * @param  array<string, mixed>  $internal
     * @param  array<string, mixed>|null  $reveal
     * @return array{
     *     score: int|float,
     *     max_score: int|float,
     *     percentage: int|float,
     *     passed: bool,
     *     requires_manual_review: bool,
     *     reveal: array<string, mixed>|null
     * }
     */
    public function mapResult(array $internal, ?array $reveal): array
    {
        return [
            'score' => $internal['score'],
            'max_score' => $internal['max_score'],
            'percentage' => $internal['percentage'],
            'passed' => (bool) $internal['passed'],
            'requires_manual_review' => (bool) ($internal['requires_manual_review'] ?? false),
            'reveal' => $reveal,
        ];
    }

    /**
     * @param  array{
     *     score: int|float,
     *     max_score: int|float,
     *     percentage: int|float,
     *     passed: bool,
     *     requires_manual_review: bool,
     *     reveal: array<string, mixed>|null
     * }  $result
     * @param  array{used: int, allowed: int|null, remaining: int|null}  $attempts
     * @return array{result: array<string, mixed>, attempts: array{used: int, allowed: int|null, remaining: int|null}}
     */
    public function envelope(array $result, array $attempts): array
    {
        return [
            'result' => $result,
            'attempts' => $attempts,
        ];
    }

    /**
     * Sticky reveal from a stamped submission. When reveal_trigger is set,
     * the object is always returned — never recomputed from current policy.
     *
     * @param  array<string, mixed>  $compiledConfig
     * @param  array<string, mixed>|null  $grading
     * @return array{trigger: string, items: list<array<string, mixed>>}|null
     */
    public function revealFromSubmission(
        BlockSubmission $submission,
        BlockType $type,
        array $compiledConfig,
        ?array $grading,
    ): ?array {
        $trigger = $submission->reveal_trigger;

        if (! is_string($trigger) || $trigger === '') {
            return null;
        }

        $internal = is_array($submission->grading_result) ? $submission->grading_result : [];

        return [
            'trigger' => $trigger,
            'items' => $type->revealItems(
                $internal,
                $compiledConfig,
                (bool) ($grading['reveal_answers'] ?? false)
            ),
        ];
    }
}
