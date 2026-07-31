<?php

namespace App\Services;

use App\Blocks\BlockTypeRegistry;
use App\Enums\AttemptStatus;
use App\Models\LessonAttempt;
use App\Support\DisplayTime;
use Carbon\Carbon;

/**
 * Completion percentage and mastery from a pinned attempt + its manifest.
 * Shared by the teacher dashboard and student-facing progress surfaces.
 */
class AttemptProgressService
{
    public function __construct(private readonly BlockTypeRegistry $registry) {}

    /**
     * Pages completed *before* current_page_id over total pages in the pinned
     * manifest. First page is 0%. Completed attempts are always 100%.
     *
     * @return array{percentage: int, completed_pages: int, total_pages: int, malformed: bool, superseded: bool}
     */
    public function completion(LessonAttempt $attempt): array
    {
        $manifest = $attempt->lessonVersion?->manifest;
        $pages = is_array($manifest) ? ($manifest['pages'] ?? []) : [];
        $total = is_array($pages) ? count($pages) : 0;
        $superseded = $attempt->status === AttemptStatus::Superseded;

        if ($total === 0) {
            return [
                'percentage' => 0,
                'completed_pages' => 0,
                'total_pages' => 0,
                'malformed' => true,
                'superseded' => $superseded,
            ];
        }

        if ($attempt->status === AttemptStatus::Completed) {
            return [
                'percentage' => 100,
                'completed_pages' => $total,
                'total_pages' => $total,
                'malformed' => false,
                'superseded' => $superseded,
            ];
        }

        $pageIds = array_values(array_map(
            fn ($page) => is_array($page) ? (string) ($page['page_id'] ?? '') : '',
            $pages,
        ));

        $current = (string) ($attempt->current_page_id ?? '');
        $index = $current === '' ? false : array_search($current, $pageIds, true);

        if ($index === false) {
            return [
                'percentage' => 0,
                'completed_pages' => 0,
                'total_pages' => $total,
                'malformed' => false,
                'superseded' => $superseded,
            ];
        }

        $completedPages = (int) $index;
        $percentage = (int) floor(($completedPages / $total) * 100);

        return [
            'percentage' => $percentage,
            'completed_pages' => $completedPages,
            'total_pages' => $total,
            'malformed' => false,
            'superseded' => $superseded,
        ];
    }

    /**
     * A completed attempt is mastered when every auto-gradable block in the
     * pinned manifest has at least one passing submission. Manual-review
     * blocks (short_response, CER) are not mastery gates. A lesson with no
     * auto-gradable blocks is mastered (stated deliberately).
     */
    public function isMastered(LessonAttempt $attempt): bool
    {
        if ($attempt->status !== AttemptStatus::Completed) {
            return false;
        }

        $gradableIds = $this->gradableBlockIds($attempt);

        if ($gradableIds === []) {
            return true;
        }

        $attempt->loadMissing('blockSubmissions');

        $passedBlocks = $attempt->blockSubmissions
            ->filter(fn ($submission) => (bool) $submission->passed)
            ->pluck('block_id')
            ->unique()
            ->all();

        foreach ($gradableIds as $blockId) {
            if (! in_array($blockId, $passedBlocks, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function gradableBlockIds(LessonAttempt $attempt): array
    {
        $manifest = $attempt->lessonVersion?->manifest;
        $pages = is_array($manifest) ? ($manifest['pages'] ?? []) : [];
        $ids = [];

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            foreach ($page['blocks'] ?? [] as $block) {
                if (! is_array($block)) {
                    continue;
                }

                $type = (string) ($block['type'] ?? '');
                $blockId = (string) ($block['block_id'] ?? $block['id'] ?? '');

                if ($type === '' || $blockId === '') {
                    continue;
                }

                if (! $this->registry->has($type)) {
                    continue;
                }

                if ($this->registry->get($type)->isAutoGradable()) {
                    $ids[] = $blockId;
                }
            }
        }

        return $ids;
    }

    /**
     * Weekly buckets in the display timezone, returned with UTC bounds for querying.
     *
     * @return list<array{label: string, start_utc: Carbon, end_utc: Carbon}>
     */
    public function weekBuckets(int $weeks = 12): array
    {
        $zone = DisplayTime::zone();
        $nowLocal = Carbon::now($zone)->startOfDay();
        // Sunday-start weeks match typical US school calendars and keep
        // Sunday-evening Eastern completions in the Eastern week (not UTC Monday).
        $currentWeekStart = $nowLocal->copy()->startOfWeek(Carbon::SUNDAY);

        $buckets = [];

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $startLocal = $currentWeekStart->copy()->subWeeks($i);
            $endLocal = $startLocal->copy()->endOfWeek(Carbon::SATURDAY);

            $buckets[] = [
                'label' => $startLocal->format('M j'),
                'start_utc' => $startLocal->copy()->utc(),
                'end_utc' => $endLocal->copy()->utc(),
            ];
        }

        return $buckets;
    }
}
