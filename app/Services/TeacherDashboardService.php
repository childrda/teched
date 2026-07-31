<?php

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Enums\ClassRole;
use App\Models\BlockSubmission;
use App\Models\ClassMembership;
use App\Models\LessonAssignment;
use App\Models\LessonAttempt;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\DisplayTime;
use Illuminate\Support\Collection;

/**
 * Shaped, already-scoped data for the Filament teacher dashboard.
 * Widgets must not run aggregate SQL — call these methods only.
 */
class TeacherDashboardService
{
    /** @var array<string, mixed>|null */
    private ?array $scopeCache = null;

    private ?int $scopeUserId = null;

    public function __construct(
        private readonly PrimaryAttemptResolver $primaryAttempts,
        private readonly AttemptProgressService $progress,
        private readonly BlockedAttemptService $blocked,
    ) {}

    /**
     * @return array{
     *     active_classes: int,
     *     active_assignments: int,
     *     students_in_progress: int,
     *     students_needing_attention: int,
     *     awaiting_review_total: int
     * }
     */
    public function summary(User $actor): array
    {
        $scope = $this->scope($actor);

        return [
            'active_classes' => $scope['active_class_ids']->count(),
            'active_assignments' => $scope['active_assignments']->count(),
            'students_in_progress' => $this->studentsInProgressCount($scope),
            'students_needing_attention' => count($this->needsAttentionRows($scope)),
            'awaiting_review_total' => $this->awaitingReviewTotal($scope),
        ];
    }

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    public function reviewQueue(User $actor, int $limit = 10): array
    {
        $scope = $this->scope($actor);
        $assignmentIds = $scope['active_assignments']->pluck('id')->all();

        if ($assignmentIds === []) {
            return ['total' => 0, 'items' => []];
        }

        $attemptIds = LessonAttempt::query()
            ->whereIn('lesson_assignment_id', $assignmentIds)
            ->pluck('id')
            ->all();

        if ($attemptIds === []) {
            return ['total' => 0, 'items' => []];
        }

        $submissions = BlockSubmission::query()
            ->whereIn('lesson_attempt_id', $attemptIds)
            ->where('requires_manual_review', true)
            ->whereDoesntHave('reviews')
            ->with([
                'lessonAttempt.user',
                'lessonAttempt.lesson',
                'lessonAttempt.lessonVersion',
                'lessonAttempt.assignment.schoolClass',
            ])
            ->orderByDesc('submitted_at')
            ->get();

        $byType = $submissions->groupBy(fn (BlockSubmission $submission) => $this->blockTypeLabel($submission));

        $items = [];
        foreach ($submissions->take($limit) as $submission) {
            $attempt = $submission->lessonAttempt;
            if ($attempt === null) {
                continue;
            }

            $items[] = [
                'submission_id' => $submission->id,
                'block_id' => $submission->block_id,
                'block_type' => $this->blockTypeLabel($submission),
                'student_name' => $attempt->user?->name ?? '—',
                'lesson_title' => $attempt->lesson?->title ?? '—',
                'class_name' => $attempt->assignment?->schoolClass?->name ?? '—',
                'submitted_at' => $submission->submitted_at,
                'submitted_at_label' => DisplayTime::toDayDateTimeString($submission->submitted_at),
                'url' => route('staff.attempts.show', $attempt),
            ];
        }

        $typeCounts = [];
        foreach ($byType as $type => $group) {
            $typeCounts[] = [
                'block_type' => (string) $type,
                'count' => $group->count(),
            ];
        }

        return [
            'total' => $submissions->count(),
            'by_type' => $typeCounts,
            'items' => $items,
        ];
    }

    /**
     * Blocked first, then stale in-progress.
     *
     * @return list<array<string, mixed>>
     */
    public function needsAttention(User $actor, int $limit = 10): array
    {
        $rows = $this->needsAttentionRows($this->scope($actor));

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return list<array<string, mixed>>
     */
    private function needsAttentionRows(array $scope): array
    {
        $assignmentIds = $scope['active_assignments']->pluck('id')->all();
        $studentIds = $scope['active_student_ids']->all();

        if ($assignmentIds === [] || $studentIds === []) {
            return [];
        }

        $staleDays = max(1, (int) config('teacher-dashboard.stale_days', 7));
        $staleBefore = now()->subDays($staleDays);

        $attempts = LessonAttempt::query()
            ->whereIn('lesson_assignment_id', $assignmentIds)
            ->whereIn('user_id', $studentIds)
            ->where('status', AttemptStatus::InProgress)
            ->with(['user', 'lesson', 'lessonVersion', 'assignment.schoolClass', 'blockSubmissions', 'retryGrants'])
            ->orderByDesc('last_activity_at')
            ->get();

        $rows = [];
        $seen = [];

        foreach ($attempts as $attempt) {
            $key = $attempt->lesson_assignment_id.'-'.$attempt->user_id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $isBlocked = $this->blocked->isBlocked($attempt);
            $isStale = $attempt->last_activity_at !== null
                && $attempt->last_activity_at->lt($staleBefore);

            if (! $isBlocked && ! $isStale) {
                continue;
            }

            $rows[] = [
                'attempt_id' => $attempt->id,
                'student_name' => $attempt->user?->name ?? '—',
                'lesson_title' => $attempt->lesson?->title ?? '—',
                'class_name' => $attempt->assignment?->schoolClass?->name ?? '—',
                'reason' => $isBlocked ? 'blocked' : 'inactive',
                'reason_label' => $isBlocked ? 'Blocked' : "No activity ({$staleDays}+ days)",
                'last_activity_at' => $attempt->last_activity_at,
                'last_activity_label' => DisplayTime::toDayDateTimeString($attempt->last_activity_at),
                'url' => route('staff.attempts.show', $attempt),
                'sort_blocked' => $isBlocked ? 0 : 1,
            ];
        }

        usort($rows, function (array $a, array $b) {
            return [$a['sort_blocked'], -($a['last_activity_at']?->timestamp ?? 0)]
                <=> [$b['sort_blocked'], -($b['last_activity_at']?->timestamp ?? 0)];
        });

        return $rows;
    }

    /**
     * New response submission, quiz submission, or attempt completion — never autosaves.
     *
     * @return list<array<string, mixed>>
     */
    public function recentActivity(User $actor, int $limit = 15): array
    {
        $scope = $this->scope($actor);
        $assignmentIds = $scope['active_assignments']->pluck('id')->all();

        if ($assignmentIds === []) {
            return [];
        }

        $attemptQuery = LessonAttempt::query()->whereIn('lesson_assignment_id', $assignmentIds);

        $completions = (clone $attemptQuery)
            ->where('status', AttemptStatus::Completed)
            ->whereNotNull('completed_at')
            ->with(['user', 'lesson', 'assignment.schoolClass'])
            ->orderByDesc('completed_at')
            ->limit($limit)
            ->get()
            ->map(fn (LessonAttempt $attempt) => [
                'type' => 'completion',
                'type_label' => 'Completed attempt',
                'at' => $attempt->completed_at,
                'at_label' => DisplayTime::toDayDateTimeString($attempt->completed_at),
                'student_name' => $attempt->user?->name ?? '—',
                'lesson_title' => $attempt->lesson?->title ?? '—',
                'class_name' => $attempt->assignment?->schoolClass?->name ?? '—',
                'url' => route('staff.attempts.show', $attempt),
            ]);

        $attemptIds = (clone $attemptQuery)->pluck('id')->all();
        $submissions = BlockSubmission::query()
            ->whereIn('lesson_attempt_id', $attemptIds === [] ? [0] : $attemptIds)
            ->with(['lessonAttempt.user', 'lessonAttempt.lesson', 'lessonAttempt.assignment.schoolClass', 'lessonAttempt.lessonVersion'])
            ->orderByDesc('submitted_at')
            ->limit($limit * 3)
            ->get()
            ->map(function (BlockSubmission $submission) {
                $type = $this->blockTypeKey($submission);
                $isQuiz = $type === 'quiz';
                $attempt = $submission->lessonAttempt;

                return [
                    'type' => $isQuiz ? 'quiz_submission' : 'response_submission',
                    'type_label' => $isQuiz ? 'Quiz submission' : 'Response submitted',
                    'at' => $submission->submitted_at,
                    'at_label' => DisplayTime::toDayDateTimeString($submission->submitted_at),
                    'student_name' => $attempt?->user?->name ?? '—',
                    'lesson_title' => $attempt?->lesson?->title ?? '—',
                    'class_name' => $attempt?->assignment?->schoolClass?->name ?? '—',
                    'url' => $attempt ? route('staff.attempts.show', $attempt) : null,
                ];
            });

        return $completions->concat($submissions)
            ->filter(fn (array $row) => $row['at'] !== null)
            ->sortByDesc(fn (array $row) => $row['at']?->timestamp ?? 0)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function upcomingAssignments(User $actor, int $limit = 10): array
    {
        $scope = $this->scope($actor);
        $now = now();

        $upcoming = $scope['active_assignments']
            ->filter(fn (LessonAssignment $a) => $a->due_at !== null && $a->due_at->gte($now))
            ->sortBy(fn (LessonAssignment $a) => $a->due_at?->timestamp ?? PHP_INT_MAX)
            ->take($limit)
            ->values();

        $studentIdsByClass = $scope['students_by_class'];
        $upcomingIds = $upcoming->pluck('id')->all();

        $startedByAssignment = LessonAttempt::query()
            ->whereIn('lesson_assignment_id', $upcomingIds ?: [0])
            ->selectRaw('lesson_assignment_id, user_id')
            ->distinct()
            ->get()
            ->groupBy('lesson_assignment_id')
            ->map(fn (Collection $rows) => $rows->pluck('user_id')->all());

        $rows = [];
        foreach ($upcoming as $assignment) {
            $studentIds = $studentIdsByClass->get($assignment->school_class_id, collect())->all();
            $startedUserIds = $startedByAssignment->get($assignment->id, []);
            $notStarted = count(array_diff($studentIds, $startedUserIds));

            $rows[] = [
                'assignment_id' => $assignment->id,
                'lesson_title' => $assignment->lesson?->title ?? '—',
                'lesson_code' => $assignment->lesson?->code ?? '—',
                'class_name' => $assignment->schoolClass?->name ?? '—',
                'due_at' => $assignment->due_at,
                'due_at_label' => DisplayTime::toDayDateTimeString($assignment->due_at),
                'not_started' => $notStarted,
                'student_total' => count($studentIds),
                'url' => route('staff.assignments.show', $assignment),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function assignmentProgress(User $actor): array
    {
        $scope = $this->scope($actor);
        $studentIdsByClass = $scope['students_by_class'];
        $assignmentIds = $scope['active_assignments']->pluck('id')->all();
        $allStudentIds = $scope['active_student_ids']->all();

        $attemptsByAssignmentUser = LessonAttempt::query()
            ->whereIn('lesson_assignment_id', $assignmentIds ?: [0])
            ->whereIn('user_id', $allStudentIds ?: [0])
            ->with(['lessonVersion', 'blockSubmissions'])
            ->get()
            ->groupBy(fn (LessonAttempt $a) => $a->lesson_assignment_id.'|'.$a->user_id);

        $rows = [];

        foreach ($scope['active_assignments'] as $assignment) {
            $studentIds = $studentIdsByClass->get($assignment->school_class_id, collect())->all();

            $notStarted = 0;
            $inProgress = 0;
            $completed = 0;
            $mastered = 0;
            $completionSum = 0;
            $completionN = 0;

            foreach ($studentIds as $studentId) {
                $key = $assignment->id.'|'.$studentId;
                $primary = $this->primaryAttempts->resolve($attemptsByAssignmentUser->get($key, collect()));

                if ($primary === null) {
                    $notStarted++;
                    $completionSum += 0;
                    $completionN++;

                    continue;
                }

                if ($primary->status === AttemptStatus::InProgress) {
                    $inProgress++;
                } elseif ($primary->status === AttemptStatus::Completed) {
                    $completed++;
                    if ($this->progress->isMastered($primary)) {
                        $mastered++;
                    }
                } else {
                    $notStarted++;
                }

                $pct = $this->progress->completion($primary)['percentage'];
                $completionSum += $pct;
                $completionN++;
            }

            $rows[] = [
                'assignment_id' => $assignment->id,
                'lesson_title' => $assignment->lesson?->title ?? '—',
                'lesson_code' => $assignment->lesson?->code ?? '—',
                'class_name' => $assignment->schoolClass?->name ?? '—',
                'not_started' => $notStarted,
                'in_progress' => $inProgress,
                'completed' => $completed,
                'mastered' => $mastered,
                'avg_completion' => $completionN > 0 ? (int) floor($completionSum / $completionN) : 0,
                'url' => route('staff.assignments.show', $assignment),
            ];
        }

        return $rows;
    }

    /**
     * Weekly attempt completions (completed_at), buckets in display timezone.
     *
     * @return array{labels: list<string>, counts: list<int>, axis_label: string}
     */
    public function weeklyCompletions(User $actor, int $weeks = 12): array
    {
        $scope = $this->scope($actor);
        $assignmentIds = $scope['active_assignments']->pluck('id')
            ->merge(
                // Include archived assignments in classes the teacher teaches so
                // historical completions still chart — but only for visible classes.
                LessonAssignment::query()
                    ->whereIn('school_class_id', $scope['visible_class_ids']->all() ?: [0])
                    ->pluck('id')
            )
            ->unique()
            ->values()
            ->all();

        $buckets = $this->progress->weekBuckets($weeks);
        $labels = [];
        $counts = [];

        if ($assignmentIds === []) {
            foreach ($buckets as $bucket) {
                $labels[] = $bucket['label'];
                $counts[] = 0;
            }

            return [
                'labels' => $labels,
                'counts' => $counts,
                'axis_label' => 'Attempts completed per week ('.$this->weekAxisZoneLabel().')',
            ];
        }

        foreach ($buckets as $bucket) {
            $labels[] = $bucket['label'];
            $counts[] = LessonAttempt::query()
                ->whereIn('lesson_assignment_id', $assignmentIds)
                ->where('status', AttemptStatus::Completed)
                ->whereNotNull('completed_at')
                ->where('completed_at', '>=', $bucket['start_utc'])
                ->where('completed_at', '<=', $bucket['end_utc'])
                ->count();
        }

        return [
            'labels' => $labels,
            'counts' => $counts,
            'axis_label' => 'Attempts completed per week ('.$this->weekAxisZoneLabel().')',
        ];
    }

    /**
     * Shared scope loader — one place for visible/active class and assignment ids.
     *
     * @return array{
     *     visible_class_ids: Collection<int, int>,
     *     active_class_ids: Collection<int, int>,
     *     active_assignments: Collection<int, LessonAssignment>,
     *     active_student_ids: Collection<int, int>,
     *     students_by_class: Collection<int, Collection<int, int>>
     * }
     */
    public function scope(User $actor): array
    {
        if ($this->scopeCache !== null && $this->scopeUserId === (int) $actor->id) {
            return $this->scopeCache;
        }

        $visibleClasses = SchoolClass::query()
            ->visibleTo($actor)
            ->get(['id', 'active']);

        $visibleClassIds = $visibleClasses->pluck('id');
        $activeClassIds = $visibleClasses->where('active', true)->pluck('id');

        $activeAssignments = LessonAssignment::query()
            ->whereIn('school_class_id', $activeClassIds->all() ?: [0])
            ->whereNull('archived_at')
            ->with(['lesson', 'schoolClass'])
            // available_at DESC, lessons.code ASC — code is a sequence *signal*, not a guarantee.
            ->leftJoin('lessons', 'lessons.id', '=', 'lesson_assignments.lesson_id')
            ->orderByDesc('lesson_assignments.available_at')
            ->orderBy('lessons.code')
            ->select('lesson_assignments.*')
            ->get();

        $memberships = ClassMembership::query()
            ->whereIn('school_class_id', $activeClassIds->all() ?: [0])
            ->where('role', ClassRole::Student->value)
            ->whereNull('withdrawn_at')
            ->get(['school_class_id', 'user_id']);

        $studentsByClass = $memberships->groupBy('school_class_id')
            ->map(fn (Collection $rows) => $rows->pluck('user_id'));

        $this->scopeCache = [
            'visible_class_ids' => $visibleClassIds,
            'active_class_ids' => $activeClassIds,
            'active_assignments' => $activeAssignments,
            'active_student_ids' => $memberships->pluck('user_id')->unique()->values(),
            'students_by_class' => $studentsByClass,
        ];
        $this->scopeUserId = (int) $actor->id;

        return $this->scopeCache;
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function studentsInProgressCount(array $scope): int
    {
        $assignmentIds = $scope['active_assignments']->pluck('id')->all();
        $studentIds = $scope['active_student_ids']->all();

        if ($assignmentIds === [] || $studentIds === []) {
            return 0;
        }

        return (int) LessonAttempt::query()
            ->whereIn('lesson_assignment_id', $assignmentIds)
            ->whereIn('user_id', $studentIds)
            ->where('status', AttemptStatus::InProgress)
            ->distinct()
            ->count('user_id');
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function awaitingReviewTotal(array $scope): int
    {
        $assignmentIds = $scope['active_assignments']->pluck('id')->all();
        if ($assignmentIds === []) {
            return 0;
        }

        $attemptIds = LessonAttempt::query()
            ->whereIn('lesson_assignment_id', $assignmentIds)
            ->pluck('id');

        if ($attemptIds->isEmpty()) {
            return 0;
        }

        return BlockSubmission::query()
            ->whereIn('lesson_attempt_id', $attemptIds)
            ->where('requires_manual_review', true)
            ->whereDoesntHave('reviews')
            ->count();
    }

    private function blockTypeKey(BlockSubmission $submission): string
    {
        $manifest = $submission->lessonAttempt?->lessonVersion?->manifest;
        if (! is_array($manifest)) {
            return 'response';
        }

        foreach ($manifest['pages'] ?? [] as $page) {
            foreach ($page['blocks'] ?? [] as $block) {
                $id = (string) ($block['block_id'] ?? $block['id'] ?? '');
                if ($id === $submission->block_id) {
                    return (string) ($block['type'] ?? 'response');
                }
            }
        }

        return 'response';
    }

    private function blockTypeLabel(BlockSubmission $submission): string
    {
        $key = $this->blockTypeKey($submission);

        return str_replace('_', ' ', ucfirst($key));
    }

    private function weekAxisZoneLabel(): string
    {
        return str_replace('_', ' ', DisplayTime::zone());
    }
}
