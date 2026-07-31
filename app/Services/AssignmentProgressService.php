<?php

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Enums\ClassRole;
use App\Models\ClassMembership;
use App\Models\LessonAssignment;
use App\Models\LessonAttempt;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Roster rows for one assignment: primary attempt, counts, blocked / needs-review
 * flags. Returns shaped data — Blade must not traverse relationships for this.
 */
class AssignmentProgressService
{
    public function __construct(private readonly BlockedAttemptService $blocked)
    {
    }

    /**
     * @return array{
     *     assignment: LessonAssignment,
     *     active: LengthAwarePaginator,
     *     withdrawn: list<array<string, mixed>>,
     *     active_total: int,
     *     blocked_count: int,
     *     needs_review_count: int
     * }
     */
    public function forAssignment(LessonAssignment $assignment, int $perPage = 30): array
    {
        $assignment->loadMissing(['lesson', 'schoolClass', 'lessonVersion']);

        $memberships = ClassMembership::query()
            ->where('school_class_id', $assignment->school_class_id)
            ->where('role', ClassRole::Student->value)
            ->with('user')
            ->orderBy('user_id')
            ->get();

        $userIds = $memberships->pluck('user_id')->all();

        $attemptsByUser = LessonAttempt::query()
            ->where('lesson_assignment_id', $assignment->id)
            ->whereIn('user_id', $userIds === [] ? [0] : $userIds)
            ->with([
                'blockSubmissions' => fn ($q) => $q->select([
                    'id',
                    'lesson_attempt_id',
                    'block_id',
                    'attempt_number',
                    'passed',
                    'requires_manual_review',
                    'submitted_at',
                ])->with('latestReview'),
                'retryGrants',
                'lessonVersion',
            ])
            ->orderByDesc('id')
            ->get()
            ->groupBy('user_id');

        $activeRows = [];
        $withdrawnRows = [];
        $blockedCount = 0;
        $needsReviewCount = 0;

        foreach ($memberships as $membership) {
            $user = $membership->user;
            $attempts = $attemptsByUser->get($user->id, collect());
            $row = $this->shapeRow($user, $attempts, $membership->isActive());

            if ($membership->isActive()) {
                $activeRows[] = $row;

                if ($row['blocked']) {
                    $blockedCount++;
                }

                // Count every unreviewed submission, not students.
                $needsReviewCount += $row['unreviewed_submission_count'];
            } elseif ($row['attempt_count'] > 0) {
                $withdrawnRows[] = $row;
            }
        }

        $page = max(1, (int) request()->integer('page', 1));
        $total = count($activeRows);
        $slice = array_slice($activeRows, ($page - 1) * $perPage, $perPage);

        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return [
            'assignment' => $assignment,
            'active' => $paginator,
            'withdrawn' => $withdrawnRows,
            'active_total' => $total,
            'blocked_count' => $blockedCount,
            'needs_review_count' => $needsReviewCount,
        ];
    }

    /**
     * Bounded-query helper for tests: load progress for the given user ids
     * without pagination side effects.
     *
     * @param  list<int>  $userIds
     * @return list<array<string, mixed>>
     */
    public function rowsForUsers(LessonAssignment $assignment, array $userIds): array
    {
        $assignment->loadMissing('lessonVersion');

        $attemptsByUser = LessonAttempt::query()
            ->where('lesson_assignment_id', $assignment->id)
            ->whereIn('user_id', $userIds === [] ? [0] : $userIds)
            ->with([
                'blockSubmissions' => fn ($q) => $q->select([
                    'id',
                    'lesson_attempt_id',
                    'block_id',
                    'attempt_number',
                    'passed',
                    'requires_manual_review',
                    'submitted_at',
                ])->with('latestReview'),
                'retryGrants',
                'lessonVersion',
            ])
            ->orderByDesc('id')
            ->get()
            ->groupBy('user_id');

        $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');
        $rows = [];

        foreach ($userIds as $userId) {
            $user = $users->get($userId);

            if ($user === null) {
                continue;
            }

            $rows[] = $this->shapeRow(
                $user,
                $attemptsByUser->get($userId, collect()),
                true
            );
        }

        return $rows;
    }

    /**
     * @param  Collection<int, LessonAttempt>  $attempts
     * @return array<string, mixed>
     */
    private function shapeRow(User $user, Collection $attempts, bool $active): array
    {
        $primary = $this->primaryAttempt($attempts);
        $unreviewedCount = 0;

        if ($primary !== null) {
            // Assignment overview receives counts/flags only — never review
            // comments or private notes (those stay on attempt detail).
            $unreviewedCount = $primary->blockSubmissions
                ->filter(fn ($row) => $row->requires_manual_review === true
                    && $row->latestReview === null)
                ->count();
        }

        $needsReview = $unreviewedCount > 0;

        $blocked = $primary !== null
            && $primary->status === AttemptStatus::InProgress
            && $this->blocked->isBlocked($primary);

        $pageLabel = null;

        if ($primary !== null) {
            $manifest = $primary->lessonVersion?->manifest;
            $pages = is_array($manifest) ? ($manifest['pages'] ?? []) : [];
            $index = 0;

            foreach ($pages as $i => $page) {
                if (is_array($page) && ($page['page_id'] ?? null) === $primary->current_page_id) {
                    $index = (int) $i + 1;
                    $pageLabel = is_string($page['title'] ?? null) ? $page['title'] : null;
                    break;
                }
            }

            $pagePosition = $pages === [] ? null : $index.'/'.count($pages);
        } else {
            $pagePosition = null;
        }

        return [
            'user' => $user,
            'user_id' => $user->id,
            'name' => $user->name,
            'active_membership' => $active,
            'status' => $primary?->status?->value ?? 'not_started',
            'status_label' => $this->statusLabel($primary),
            'attempt_count' => $attempts->count(),
            'primary_attempt_id' => $primary?->id,
            'current_page' => $pagePosition,
            'current_page_title' => $pageLabel,
            'started_at' => $primary?->started_at,
            'completed_at' => $primary?->completed_at,
            'active_seconds' => $primary?->active_seconds ?? 0,
            'blocked' => $blocked,
            'needs_review' => $needsReview,
            'unreviewed_submission_count' => $unreviewedCount,
        ];
    }

    /**
     * Prefer in_progress, else newest completed, else newest superseded.
     *
     * @param  Collection<int, LessonAttempt>  $attempts
     */
    public function primaryAttempt(Collection $attempts): ?LessonAttempt
    {
        $inProgress = $attempts->first(
            fn (LessonAttempt $attempt) => $attempt->status === AttemptStatus::InProgress
        );

        if ($inProgress !== null) {
            return $inProgress;
        }

        $completed = $attempts
            ->filter(fn (LessonAttempt $attempt) => $attempt->status === AttemptStatus::Completed)
            ->sortByDesc(fn (LessonAttempt $attempt) => [
                $attempt->completed_at?->timestamp ?? 0,
                $attempt->id,
            ])
            ->first();

        if ($completed !== null) {
            return $completed;
        }

        return $attempts
            ->filter(fn (LessonAttempt $attempt) => $attempt->status === AttemptStatus::Superseded)
            ->sortByDesc(fn (LessonAttempt $attempt) => [
                $attempt->superseded_at?->timestamp ?? 0,
                $attempt->id,
            ])
            ->first();
    }

    private function statusLabel(?LessonAttempt $attempt): string
    {
        if ($attempt === null) {
            return __('staff.status_not_started');
        }

        return match ($attempt->status) {
            AttemptStatus::InProgress => __('staff.status_in_progress'),
            AttemptStatus::Completed => __('staff.status_completed'),
            AttemptStatus::Superseded => __('staff.status_superseded'),
        };
    }
}
