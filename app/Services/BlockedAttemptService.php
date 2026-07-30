<?php

namespace App\Services;

use App\Blocks\BlockTypeRegistry;
use App\Enums\AttemptStatus;
use App\Enums\PageCompletionType;
use App\Models\LessonAttempt;
use App\Models\User;

/**
 * Single definition of "blocked" for the assignment overview, attempt detail,
 * and the staff blocked-attempts list.
 *
 * A student is blocked when their current page uses pass_activity, a relevant
 * gradable block has no passing submission, its allowance is finite and
 * exhausted, and no grant currently extends it. Failed with retries left is
 * mid-task, not blocked.
 */
class BlockedAttemptService
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
            ->with(['user', 'lesson', 'lessonVersion', 'blockSubmissions', 'retryGrants', 'assignment'])
            ->where('status', AttemptStatus::InProgress)
            ->orderByDesc('last_activity_at')
            ->get();

        $rows = [];

        foreach ($attempts as $attempt) {
            foreach ($this->blockedBlocks($attempt) as $block) {
                $rows[] = array_merge(['attempt' => $attempt], $block);
            }
        }

        return $rows;
    }

    public function isBlocked(LessonAttempt $attempt): bool
    {
        return $this->blockedBlocks($attempt) !== [];
    }

    /**
     * @return list<array{
     *     block_id: string,
     *     block_type: string,
     *     used: int,
     *     allowed: int|null,
     *     remaining: int|null
     * }>
     */
    public function blockedBlocks(LessonAttempt $attempt): array
    {
        if ($attempt->status !== AttemptStatus::InProgress) {
            return [];
        }

        $manifest = $attempt->lessonVersion?->manifest;

        if (! is_array($manifest)) {
            $attempt->loadMissing('lessonVersion');
            $manifest = $attempt->lessonVersion?->manifest;
        }

        if (! is_array($manifest)) {
            return [];
        }

        $page = $this->currentPage($manifest, $attempt->current_page_id);

        if ($page === null || ($page['completion_type'] ?? null) !== PageCompletionType::PassActivity->value) {
            return [];
        }

        $attempt->loadMissing(['blockSubmissions', 'retryGrants']);

        $rows = [];

        foreach ($page['blocks'] ?? [] as $block) {
            if (! is_array($block) || ! is_string($block['block_id'] ?? null)) {
                continue;
            }

            $typeKey = (string) ($block['type'] ?? '');

            try {
                $type = $this->registry->get($typeKey);
            } catch (\Throwable) {
                continue;
            }

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
                'block_id' => $block['block_id'],
                'block_type' => $typeKey,
                'used' => $counts['used'],
                'allowed' => $counts['allowed'],
                'remaining' => $counts['remaining'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function currentPage(array $manifest, ?string $pageId): ?array
    {
        $pages = $manifest['pages'] ?? [];

        if (! is_array($pages) || $pages === []) {
            return null;
        }

        if (is_string($pageId) && $pageId !== '') {
            foreach ($pages as $page) {
                if (is_array($page) && ($page['page_id'] ?? null) === $pageId) {
                    return $page;
                }
            }
        }

        return is_array($pages[0] ?? null) ? $pages[0] : null;
    }
}
