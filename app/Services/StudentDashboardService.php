<?php

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Enums\ClassRole;
use App\Models\ClassMembership;
use App\Models\LessonAssignment;
use App\Models\LessonAttempt;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Student home: assignments across memberships, plus unassigned practice.
 */
class StudentDashboardService
{
    public function __construct(
        private readonly LessonAssignmentService $assignmentAccess,
        private readonly PrimaryAttemptResolver $primaryAttempts,
        private readonly UserPreferenceService $preferences,
    ) {
    }

    /**
     * @return array{
     *     assignments: list<array<string, mixed>>,
     *     hidden_assignments: list<array<string, mixed>>,
     *     practice: list<array<string, mixed>>,
     *     hidden_practice: list<array<string, mixed>>,
     *     show_completed_assignments: bool,
     *     show_completed_practice: bool,
     *     completed_assignment_count: int,
     *     completed_practice_count: int
     * }
     */
    public function forStudent(User $user): array
    {
        $memberships = ClassMembership::query()
            ->where('user_id', $user->id)
            ->where('role', ClassRole::Student->value)
            ->with('schoolClass')
            ->get()
            ->keyBy('school_class_id');

        $classIds = $memberships->keys()->all();

        // available_at DESC, lessons.code ASC — code is a sequence *signal*, not a guarantee.
        $assignments = LessonAssignment::query()
            ->whereIn('school_class_id', $classIds === [] ? [0] : $classIds)
            ->with(['lesson', 'schoolClass', 'lessonVersion'])
            ->leftJoin('lessons', 'lessons.id', '=', 'lesson_assignments.lesson_id')
            ->orderByDesc('lesson_assignments.available_at')
            ->orderBy('lessons.code')
            ->select('lesson_assignments.*')
            ->get();

        $attemptGroups = LessonAttempt::query()
            ->where('user_id', $user->id)
            ->whereIn('lesson_assignment_id', $assignments->pluck('id')->all() ?: [0])
            ->orderByDesc('id')
            ->get()
            ->groupBy('lesson_assignment_id');

        $visibleAssignments = [];
        $hiddenAssignments = [];

        foreach ($assignments as $assignment) {
            $membership = $memberships->get($assignment->school_class_id);
            $attempts = $attemptGroups->get($assignment->id, collect());
            $row = $this->shapeAssignment($user, $assignment, $membership, $attempts);

            if ($this->isCompletedForToggle($row) && $assignment->isAvailable()) {
                $hiddenAssignments[] = $row;
            } else {
                // Never hide unavailable assignments — they are ahead, not behind.
                $visibleAssignments[] = $row;
            }
        }

        $showCompletedAssignments = $this->preferences->showCompletedAssignments($user);
        if ($showCompletedAssignments) {
            $assignmentRows = array_merge($visibleAssignments, $hiddenAssignments);
        } else {
            $assignmentRows = $visibleAssignments;
        }

        $practiceGroups = LessonAttempt::query()
            ->where('user_id', $user->id)
            ->whereNull('lesson_assignment_id')
            ->with('lesson')
            ->orderByDesc('last_activity_at')
            ->get()
            ->groupBy('lesson_id');

        $visiblePractice = [];
        $hiddenPractice = [];

        foreach ($practiceGroups as $attempts) {
            $row = $this->shapePractice($attempts);
            if ($row === null) {
                continue;
            }

            if (($row['status'] ?? null) === 'completed') {
                $hiddenPractice[] = $row;
            } else {
                $visiblePractice[] = $row;
            }
        }

        $showCompletedPractice = $this->preferences->showCompletedPractice($user);
        $practiceRows = $showCompletedPractice
            ? array_merge($visiblePractice, $hiddenPractice)
            : $visiblePractice;

        return [
            'assignments' => $assignmentRows,
            'hidden_assignments' => $hiddenAssignments,
            'practice' => $practiceRows,
            'hidden_practice' => $hiddenPractice,
            'show_completed_assignments' => $showCompletedAssignments,
            'show_completed_practice' => $showCompletedPractice,
            'completed_assignment_count' => count($hiddenAssignments),
            'completed_practice_count' => count($hiddenPractice),
        ];
    }

    /**
     * Hidden when primary attempt is completed. Superseded-only stays visible.
     * In-progress (even with older completed) stays visible. Archived completed
     * belongs in the completed group. Unavailable is never hidden.
     *
     * @param  array<string, mixed>  $row
     */
    private function isCompletedForToggle(array $row): bool
    {
        return ($row['status'] ?? null) === 'completed';
    }

    /**
     * @param  Collection<int, LessonAttempt>  $attempts
     * @return array<string, mixed>
     */
    private function shapeAssignment(
        User $user,
        LessonAssignment $assignment,
        ?ClassMembership $membership,
        Collection $attempts,
    ): array {
        $active = $membership?->isActive() === true;
        $available = $assignment->isAvailable();
        $primary = $this->primaryAttempts->resolve($attempts);
        $mayStart = $this->assignmentAccess->mayStartAttempt($user, $assignment);
        $mayResume = $this->assignmentAccess->mayResumeAttempt($user, $assignment);
        $mayView = $this->assignmentAccess->mayViewAssignment($user, $assignment);

        $action = 'none';
        $url = null;
        $status = 'not_started';

        if ($primary !== null && $primary->status === AttemptStatus::InProgress) {
            $status = 'in_progress';
            if ($mayResume) {
                $action = 'resume';
                $url = route('player.assignments.show', $assignment);
            }
        } elseif ($primary !== null && $primary->status === AttemptStatus::Completed) {
            $status = 'completed';
            if ($mayView) {
                $action = 'view';
                $url = route('player.assignments.show', $assignment);
            }
        } elseif ($primary !== null && $primary->status === AttemptStatus::Superseded) {
            // Superseded-only history is not "completed" for the toggle.
            $status = 'not_started';
            if ($mayStart) {
                $action = 'start';
                $url = route('player.assignments.show', $assignment);
            } elseif ($mayView) {
                $action = 'view';
                $url = route('player.assignments.show', $assignment);
            }
        } elseif (! $available) {
            $status = 'unavailable';
        } elseif (! $active) {
            $status = 'withdrawn';
        } elseif ($mayStart) {
            $status = 'not_started';
            $action = 'start';
            $url = route('player.assignments.show', $assignment);
        } else {
            $status = 'not_started';
            $action = 'none';
        }

        return [
            'assignment' => $assignment,
            'lesson_title' => $assignment->lesson->title,
            'lesson_code' => $assignment->lesson->code,
            'class_name' => $assignment->schoolClass->name,
            'available_at' => $assignment->available_at,
            'due_at' => $assignment->due_at,
            'available' => $available,
            'active_membership' => $active,
            'status' => $status,
            'status_label' => match ($status) {
                'unavailable' => __('home.status_unavailable'),
                'not_started' => __('home.status_not_started'),
                'in_progress' => __('home.status_in_progress'),
                'completed' => __('home.status_completed'),
                'withdrawn' => __('home.status_withdrawn'),
                default => $status,
            },
            'action' => $action,
            'action_label' => match ($action) {
                'start' => __('home.action_start'),
                'resume' => __('home.action_resume'),
                'view' => __('home.action_view'),
                default => null,
            },
            'url' => $url,
            'withdrawn_reason' => $active ? null : __('home.withdrawn_reason'),
        ];
    }

    /**
     * @param  Collection<int, LessonAttempt>  $attempts
     * @return array<string, mixed>|null
     */
    private function shapePractice(Collection $attempts): ?array
    {
        $primary = $this->primaryAttempts->resolve($attempts);
        if ($primary === null || $primary->lesson === null) {
            return null;
        }

        return [
            'lesson' => $primary->lesson,
            'attempt' => $primary,
            'status' => $primary->status->value,
            'status_label' => $this->statusLabel($primary),
            'action' => $primary->status === AttemptStatus::InProgress ? 'resume' : 'view',
            'url' => route('lessons.play', $primary->lesson->code),
        ];
    }

    private function statusLabel(LessonAttempt $attempt): string
    {
        return match ($attempt->status) {
            AttemptStatus::InProgress => __('home.status_in_progress'),
            AttemptStatus::Completed => __('home.status_completed'),
            AttemptStatus::Superseded => __('home.status_superseded'),
        };
    }
}
