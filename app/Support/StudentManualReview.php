<?php

namespace App\Support;

/**
 * Explicit student-safe review payload. Never pass a BlockSubmissionReview
 * model here — only already-sanitized primitives from SubmissionReviewService.
 */
final class StudentManualReview
{
    /**
     * @param  array{awarded: int, possible: int, percentage: int}|null  $score
     * @return array{reviewed: bool, score: array{awarded: int, possible: int, percentage: int}|null, comment: string|null}
     */
    public static function map(bool $reviewed, ?array $score, ?string $comment): array
    {
        return [
            'reviewed' => $reviewed,
            'score' => $score,
            'comment' => $comment,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedKeys(): array
    {
        return ['reviewed', 'score', 'comment'];
    }
}
