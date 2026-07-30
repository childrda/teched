<?php

namespace App\Policies;

use App\Enums\AttemptStatus;
use App\Enums\ClassRole;
use App\Models\ClassMembership;
use App\Models\LessonAttempt;
use App\Models\User;

/**
 * Single place that answers "may this user see or act on this attempt."
 *
 * Unassigned attempts (null lesson_assignment_id) sit outside the roster
 * graph by construction: only the owning student and admins reach them —
 * no teacher can grant retries or restart practice that was never assigned.
 *
 * Withdrawn student memberships: teachers of *this assignment's class* still
 * see past attempts for historical reporting. Do not add whereNull('withdrawn_at')
 * on the *student* side of teacher view checks — that would hide history when
 * a student leaves. Active membership is required for the *teacher* actor,
 * and for a student to start/resume assigned work.
 */
class LessonAttemptPolicy
{
    public function view(User $user, LessonAttempt $attempt): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($attempt->user_id === $user->id) {
            return true;
        }

        return $this->teachesAssignmentClass($user, $attempt);
    }

    /**
     * Staff intervention: grant retries / restart. Admins always; teachers
     * only when they actively teach the exact class named by the attempt's
     * assignment. Unassigned attempts: admin only.
     */
    public function intervene(User $user, LessonAttempt $attempt): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->teachesAssignmentClass($user, $attempt);
    }

    /**
     * Student may continue working (autosave, grade, continue). Own in-progress
     * attempt only; assigned attempts also require an active student membership.
     */
    public function work(User $user, LessonAttempt $attempt): bool
    {
        if ($attempt->user_id !== $user->id) {
            return false;
        }

        if ($attempt->status !== AttemptStatus::InProgress) {
            return false;
        }

        if ($attempt->lesson_assignment_id === null) {
            return true;
        }

        return $this->activeStudentMembership($user, $attempt);
    }

    private function teachesAssignmentClass(User $user, LessonAttempt $attempt): bool
    {
        if (! $user->isTeacher() || $attempt->lesson_assignment_id === null) {
            return false;
        }

        $attempt->loadMissing('assignment');

        if ($attempt->assignment === null) {
            return false;
        }

        return ClassMembership::query()
            ->where('school_class_id', $attempt->assignment->school_class_id)
            ->where('user_id', $user->id)
            ->where('role', ClassRole::Teacher->value)
            ->whereNull('withdrawn_at')
            ->exists();
    }

    private function activeStudentMembership(User $user, LessonAttempt $attempt): bool
    {
        $attempt->loadMissing('assignment');

        if ($attempt->assignment === null) {
            return false;
        }

        return ClassMembership::query()
            ->where('school_class_id', $attempt->assignment->school_class_id)
            ->where('user_id', $user->id)
            ->where('role', ClassRole::Student->value)
            ->whereNull('withdrawn_at')
            ->exists();
    }
}
