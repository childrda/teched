<?php

namespace App\Services;

use App\Blocks\BlockTypeRegistry;
use App\Enums\AttemptStatus;
use App\Models\LessonAttempt;
use App\Models\User;

/**
 * Minimal staff listing: in-progress attempts with no remaining submissions
 * on a gradable block that has not been passed. Scoped via visibleTo().
 */
class BlockedAttemptFinder
{
    public function __construct(
        private readonly RetryPolicy $retries,
        private readonly BlockTypeRegistry $registry,
    ) {
    }

    /**
     * @return list<array{
     *     attempt: LessonAttempt,
     *     block_id: string,
     *     block_type: string,
     *     used: int,
     *     allowed: int|null,
     *     remaining: int|null
     * }>
     */
    public function forUser(User $user): array
    {
        $attempts = LessonAttempt::query()
            ->visibleTo($user)
            ->with(['user', 'lesson', 'lessonVersion', 'blockSubmissions', 'assignment'])
            ->where('status', AttemptStatus::InProgress)
            ->orderByDesc('last_activity_at')
            ->get();

        $rows = [];

        foreach ($attempts as $attempt) {
            $manifest = $attempt->lessonVersion?->manifest;

            if (! is_array($manifest)) {
                continue;
            }

            foreach ($manifest['pages'] ?? [] as $page) {
                if (! is_array($page)) {
                    continue;
                }

                foreach ($page['blocks'] ?? [] as $block) {
                    if (! is_array($block) || ! is_string($block['block_id'] ?? null)) {
                        continue;
                    }

                    $type = $this->registry->get((string) ($block['type'] ?? ''));

                    if (! $type->isAutoGradable() || $type->gradingResponseShape() === null) {
                        continue;
                    }

                    $grading = is_array($block['grading'] ?? null) ? $block['grading'] : null;
                    $counts = $this->retries->counts($attempt, $block['block_id'], $grading);

                    if ($counts['allowed'] === null || $counts['remaining'] > 0) {
                        continue;
                    }

                    $latest = $attempt->blockSubmissions
                        ->where('block_id', $block['block_id'])
                        ->sortByDesc('attempt_number')
                        ->first();

                    if ($latest !== null && $latest->passed === true) {
                        continue;
                    }

                    $rows[] = [
                        'attempt' => $attempt,
                        'block_id' => $block['block_id'],
                        'block_type' => (string) $block['type'],
                        'used' => $counts['used'],
                        'allowed' => $counts['allowed'],
                        'remaining' => $counts['remaining'],
                    ];
                }
            }
        }

        return $rows;
    }
}
