<?php

namespace App\Services;

use App\Blocks\BlockTypeRegistry;
use App\Models\LessonAttempt;
use App\Support\CanonicalJson;
use App\Support\TeacherGradingResult;

/**
 * Shapes one attempt for the teacher detail screen: attempt context, per-block
 * current work vs submitted history, teacher grading data, and retry grants.
 */
class AttemptDetailPresenter
{
    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly TeacherGradingResult $teacherResults,
        private readonly RetryPolicy $retries,
        private readonly BlockedAttemptService $blocked,
        private readonly SubmissionReviewService $reviews,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(LessonAttempt $attempt): array
    {
        $attempt->loadMissing([
            'user',
            'lesson',
            'lessonVersion',
            'assignment.schoolClass',
            'blockStates',
            'blockSubmissions.latestReview.reviewedBy',
            'blockSubmissions.reviews.reviewedBy',
            'retryGrants.grantedBy',
            'reopens.reopenedBy',
            'supersededBy',
        ]);

        $manifest = is_array($attempt->lessonVersion?->manifest)
            ? $attempt->lessonVersion->manifest
            : ['pages' => []];

        $statesByBlock = $attempt->blockStates->keyBy('block_id');
        $subsByBlock = $attempt->blockSubmissions
            ->sortByDesc('attempt_number')
            ->groupBy('block_id');

        $blocks = [];

        foreach ($manifest['pages'] ?? [] as $page) {
            if (! is_array($page)) {
                continue;
            }

            foreach ($page['blocks'] ?? [] as $block) {
                if (! is_array($block) || ! is_string($block['block_id'] ?? null)) {
                    continue;
                }

                $blocks[] = $this->presentBlock(
                    $attempt,
                    $page,
                    $block,
                    $statesByBlock->get($block['block_id']),
                    $subsByBlock->get($block['block_id'], collect()),
                );
            }
        }

        $pageTitle = null;
        $pageIndex = null;
        $pages = $manifest['pages'] ?? [];

        foreach ($pages as $i => $page) {
            if (is_array($page) && ($page['page_id'] ?? null) === $attempt->current_page_id) {
                $pageTitle = $page['title'] ?? null;
                $pageIndex = (int) $i + 1;
                break;
            }
        }

        return [
            'attempt' => $attempt,
            'student_name' => $attempt->user->name,
            'lesson_title' => $attempt->lesson->title,
            'lesson_code' => $attempt->lesson->code,
            'class_name' => $attempt->assignment?->schoolClass?->name,
            'version_number' => $attempt->lessonVersion?->version,
            'status' => $attempt->status->value,
            'status_label' => match ($attempt->status->value) {
                'in_progress' => __('staff.status_in_progress'),
                'completed' => __('staff.status_completed'),
                'superseded' => __('staff.status_superseded'),
                default => $attempt->status->value,
            },
            'current_page_id' => $attempt->current_page_id,
            'current_page_title' => $pageTitle,
            'current_page_position' => $pageIndex === null || $pages === []
                ? null
                : $pageIndex.'/'.count($pages),
            'started_at' => $attempt->started_at,
            'completed_at' => $attempt->completed_at,
            'superseded_at' => $attempt->superseded_at,
            'superseded_by' => $attempt->supersededBy?->name,
            'active_seconds' => $attempt->active_seconds,
            'time_to_complete_seconds' => $attempt->completed_at !== null && $attempt->started_at !== null
                ? $attempt->started_at->diffInSeconds($attempt->completed_at)
                : null,
            'blocked' => $this->blocked->isBlocked($attempt),
            'blocked_blocks' => $this->blocked->blockedBlocks($attempt),
            'blocks' => $blocks,
            'retry_grants' => $attempt->retryGrants->map(fn ($grant) => [
                'block_id' => $grant->block_id,
                'additional_attempts' => $grant->additional_attempts,
                'reason' => $grant->reason,
                'granted_by' => $grant->grantedBy?->name,
                'created_at' => $grant->created_at,
            ])->values()->all(),
            'reopens' => $attempt->reopens->map(fn ($reopen) => [
                'previous_completed_at' => $reopen->previous_completed_at,
                'reason' => $reopen->reason,
                'reopened_by' => $reopen->reopenedBy?->name,
                'created_at' => $reopen->created_at,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $block
     * @param  \Illuminate\Support\Collection<int, \App\Models\BlockSubmission>  $submissions
     * @return array<string, mixed>
     */
    private function presentBlock(
        LessonAttempt $attempt,
        array $page,
        array $block,
        $stateRow,
        $submissions,
    ): array {
        $blockId = $block['block_id'];
        $typeKey = (string) ($block['type'] ?? '');
        $config = is_array($block['config'] ?? null) ? $block['config'] : [];
        $grading = is_array($block['grading'] ?? null) ? $block['grading'] : null;

        try {
            $type = $this->registry->get($typeKey);
        } catch (\Throwable) {
            $type = null;
        }

        $latest = $submissions->sortByDesc('attempt_number')->first();
        $state = is_array($stateRow?->state) ? $stateRow->state : null;

        $draftDiffers = false;

        if ($state !== null && $latest !== null && is_array($latest->response)) {
            $draftDiffers = ! CanonicalJson::equal($latest->response, $state);
        } elseif ($state !== null && $latest === null) {
            $draftDiffers = true;
        }

        $history = [];
        $teacherResults = [];
        $isLatestSubmission = true;

        foreach ($submissions->sortByDesc('attempt_number') as $submission) {
            $needsReview = $this->reviews->submissionNeedsReview($submission);
            $reviewHistory = $submission->requires_manual_review
                ? $this->reviews->teacherHistory($submission)
                : [];
            $latestReview = $reviewHistory[0] ?? null;

            if ($latestReview !== null) {
                $latestReview['review_count'] = count($reviewHistory);
            }

            $history[] = [
                'id' => $submission->id,
                'attempt_number' => $submission->attempt_number,
                'submitted_at' => $submission->submitted_at,
                'response' => $submission->response,
                'requires_manual_review' => (bool) $submission->requires_manual_review,
                'needs_review' => $needsReview,
                'is_latest_submission' => $isLatestSubmission,
                'passed' => $submission->passed,
                'score' => $submission->score,
                'max_score' => $submission->max_score,
                'points_possible' => $submission->requires_manual_review
                    ? $this->reviews->pointsPossible($typeKey, $config)
                    : null,
                'latest_review' => $latestReview,
                'review_history' => $reviewHistory,
            ];

            $isLatestSubmission = false;

            if ($type !== null && (is_array($submission->grading_result) || $submission->requires_manual_review)) {
                $mapped = $this->teacherResults->map($submission, $type, $config, $grading);

                if ($mapped !== null) {
                    $teacherResults[] = $mapped;
                }
            }
        }

        $counts = $type !== null && $type->isAutoGradable()
            ? $this->retries->counts($attempt, $blockId, $grading)
            : null;

        $ordered = $submissions->sortBy('attempt_number')->values();
        $firstMapped = null;

        if ($type !== null && (bool) ($grading['record_first_attempt'] ?? false) && $ordered->isNotEmpty()) {
            $firstMapped = $this->teacherResults->map($ordered->first(), $type, $config, $grading);
        }

        $latestMapped = $teacherResults[0] ?? null;

        return [
            'block_id' => $blockId,
            'block_type' => $typeKey,
            'page_id' => $page['page_id'] ?? null,
            'page_title' => $page['title'] ?? null,
            'auto_gradable' => $type?->isAutoGradable() === true,
            'holds_state' => $type?->holdsStudentState() === true,
            'current_work' => [
                'state' => $state,
                'updated_at' => $stateRow?->updated_at,
                'differs_from_latest_submission' => $draftDiffers,
                'has_state' => $state !== null,
            ],
            'submitted_history' => $history,
            'attempts' => $counts,
            'first_result' => $firstMapped,
            'latest_result' => $latestMapped,
            'all_results' => $teacherResults,
            // Per submission, not "any historical review on the block".
            'needs_review' => $submissions->contains(
                fn ($row) => $this->reviews->submissionNeedsReview($row)
            ),
            'emphasize_first' => (bool) ($grading['record_first_attempt'] ?? false),
        ];
    }
}
