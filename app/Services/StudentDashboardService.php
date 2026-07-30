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
    /**
     * @return array{
     *     assignments: list<array<string, mixed>>,
     *     practice: list<array<string, mixed>>
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

        $assignments = LessonAssignment::query()
            ->whereIn('school_class_id', $classIds === [] ? [0] : $classIds)
            ->with(['lesson', 'schoolClass', 'lessonVersion'])
            ->orderByDesc('available_at')
            ->orderByDesc('id')
            ->get();

        $attemptGroups = LessonAttempt::query()
            ->where('user_id', $user->id)
            ->whereIn('lesson_assignment_id', $assignments->pluck('id')->all() ?: [0])
            ->orderByDesc('id')
            ->get()
            ->groupBy('lesson_assignment_id');

        $assignmentRows = [];

        foreach ($assignments as $assignment) {
            $membership = $memberships->get($assignment->school_class_id);
            $attempts = $attemptGroups->get($assignment->id, collect());
            $assignmentRows[] = $this->shapeAssignment($user, $assignment, $membership, $attempts);
        }

        $practice = LessonAttempt::query()
            ->where('user_id', $user->id)
            ->whereNull('lesson_assignment_id')
            ->with('lesson')
            ->orderByDesc('last_activity_at')
            ->get()
            ->map(fn (LessonAttempt $attempt) => [
                'lesson' => $attempt->lesson,
                'attempt' => $attempt,
                'status' => $attempt->status->value,
                'status_label' => $this->statusLabel($attempt),
                'action' => $attempt->status === AttemptStatus::InProgress ? 'resume' : 'view',
                'url' => route('lessons.play', $attempt->lesson->code),
            ])
            ->values()
            ->all();

        return [
            'assignments' => $assignmentRows,
            'practice' => $practice,
        ];
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
        $resolved = $this->pickPrimary($attempts);

        $action = 'start';
        $url = route('player.assignments.show', $assignment);

        if (! $available) {
            $status = 'unavailable';
            $action = 'none';
            $url = null;
        } elseif (! $active) {
            $status = $resolved['attempt']?->status->value ?? 'withdrawn';
            $action = $resolved['attempt'] !== null && $resolved['attempt']->status === AttemptStatus::Completed
                ? 'view'
                : 'none';
            $url = $action === 'view' ? route('player.assignments.show', $assignment) : null;
        } elseif ($resolved['attempt'] === null) {
            $status = 'not_started';
            $action = 'start';
        } elseif ($resolved['attempt']->status === AttemptStatus::InProgress) {
            $status = 'in_progress';
            $action = 'resume';
        } else {
            $status = 'completed';
            $action = 'view';
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
     * @return array{attempt: ?LessonAttempt, read_only: bool}
     */
    private function pickPrimary(Collection $attempts): array
    {
        $inProgress = $attempts->first(
            fn (LessonAttempt $attempt) => $attempt->status === AttemptStatus::InProgress
        );

        if ($inProgress !== null) {
            return ['attempt' => $inProgress, 'read_only' => false];
        }

        $completed = $attempts
            ->filter(fn (LessonAttempt $attempt) => $attempt->status === AttemptStatus::Completed)
            ->sortByDesc(fn (LessonAttempt $a) => [$a->completed_at?->timestamp ?? 0, $a->id])
            ->first();

        return ['attempt' => $completed, 'read_only' => $completed !== null];
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
