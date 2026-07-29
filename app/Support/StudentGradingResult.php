<?php

namespace App\Support;

/**
 * Maps the internal buildGradingResult() shape to the five-key payload the
 * student grading endpoint returns. details[], per-item correctness, and
 * authored feedback stay server-side — Phase 3 reveals them under controlled
 * conditions.
 */
class StudentGradingResult
{
    /**
     * @param  array<string, mixed>  $internal
     * @return array{
     *     score: int|float,
     *     max_score: int|float,
     *     percentage: int|float,
     *     passed: bool,
     *     requires_manual_review: bool
     * }
     */
    public function map(array $internal): array
    {
        return [
            'score' => $internal['score'],
            'max_score' => $internal['max_score'],
            'percentage' => $internal['percentage'],
            'passed' => (bool) $internal['passed'],
            'requires_manual_review' => (bool) ($internal['requires_manual_review'] ?? false),
        ];
    }
}
