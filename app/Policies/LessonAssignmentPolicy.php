<?php

namespace App\Policies;

use App\Enums\ClassRole;
use App\Models\ClassMembership;
use App\Models\LessonAssignment;
use App\Models\User;

class LessonAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher() || $user->isStudent();
    }

    /**
     * See the assignment. Active or withdrawn membership is enough — a
     * withdrawn student must still reach completed history.
     */
    public function view(User $user, LessonAssignment $assignment): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $membership = $this->membership($user, $assignment->school_class_id);

        if ($membership === null) {
            return false;
        }

        if ($membership->role === ClassRole::Teacher) {
            return $membership->isActive();
        }

        return $membership->role === ClassRole::Student;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    /**
     * Edit dates / repin. Archived assignments are historical — only Unarchive.
     */
    public function update(User $user, LessonAssignment $assignment): bool
    {
        if (! $this->managesClass($user, $assignment->school_class_id)) {
            return false;
        }

        return ! $assignment->isArchived();
    }

    public function delete(User $user, LessonAssignment $assignment): bool
    {
        return $this->managesClass($user, $assignment->school_class_id);
    }

    public function archive(User $user, LessonAssignment $assignment): bool
    {
        return $this->managesClass($user, $assignment->school_class_id);
    }

    /**
     * Start or resume work. Active student membership, or active teacher /
     * admin access to the class. Fine-grained inactive-class / archived
     * rules live on LessonAssignmentService's access triad.
     */
    public function play(User $user, LessonAssignment $assignment): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $membership = $this->membership($user, $assignment->school_class_id);

        if ($membership === null || ! $membership->isActive()) {
            return false;
        }

        if ($user->isTeacher()) {
            return $membership->role === ClassRole::Teacher;
        }

        return $membership->role === ClassRole::Student;
    }

    private function managesClass(User $user, int $schoolClassId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isTeacher()) {
            return false;
        }

        $membership = $this->membership($user, $schoolClassId);

        return $membership !== null
            && $membership->isActive()
            && $membership->role === ClassRole::Teacher;
    }

    private function membership(User $user, int $schoolClassId): ?ClassMembership
    {
        return ClassMembership::query()
            ->where('school_class_id', $schoolClassId)
            ->where('user_id', $user->id)
            ->first();
    }
}
