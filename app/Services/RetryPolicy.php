<?php

namespace App\Services;

use App\Models\AttemptRetryGrant;
use App\Models\BlockSubmission;
use App\Models\LessonAttempt;

/**
 * Server-side retry eligibility. Never stored as a remaining-count — always
 * recomputed from max_attempts + grants versus submission count.
 */
class RetryPolicy
{
    /**
     * @param  array<string, mixed>|null  $grading
     * @return array{used: int, allowed: int|null, remaining: int|null}
     */
    public function counts(LessonAttempt $attempt, string $blockId, ?array $grading): array
    {
        $used = (int) BlockSubmission::query()
            ->where('lesson_attempt_id', $attempt->id)
            ->where('block_id', $blockId)
            ->count();

        $allowed = $this->allowed($attempt, $blockId, $grading);

        if ($allowed === null) {
            return [
                'used' => $used,
                'allowed' => null,
                'remaining' => null,
            ];
        }

        return [
            'used' => $used,
            'allowed' => $allowed,
            'remaining' => max(0, $allowed - $used),
        ];
    }

    /**
     * Whether another submission is permitted right now.
     *
     * @param  array<string, mixed>|null  $grading
     */
    public function canSubmit(LessonAttempt $attempt, string $blockId, ?array $grading): bool
    {
        $counts = $this->counts($attempt, $blockId, $grading);

        if ($counts['allowed'] === null) {
            return true;
        }

        return $counts['used'] < $counts['allowed'];
    }

    /**
     * Effective allowance, or null when unlimited.
     *
     * `allow_retry: false` is treated as max_attempts = 1 at evaluation time.
     * When max_attempts is null (and retries are allowed), grants are irrelevant.
     *
     * @param  array<string, mixed>|null  $grading
     */
    public function allowed(LessonAttempt $attempt, string $blockId, ?array $grading): ?int
    {
        $allowRetry = (bool) ($grading['allow_retry'] ?? true);

        if (! $allowRetry) {
            return 1;
        }

        $maxAttempts = $grading['max_attempts'] ?? null;

        if ($maxAttempts === null) {
            return null;
        }

        $base = max(1, (int) $maxAttempts);

        $grants = (int) AttemptRetryGrant::query()
            ->where('lesson_attempt_id', $attempt->id)
            ->where('block_id', $blockId)
            ->sum('additional_attempts');

        return $base + $grants;
    }
}
