<?php

namespace App\Policies;

use App\Enums\ClassRole;
use App\Models\ClassMembership;
use App\Models\LessonAssignment;
use App\Models\User;

class LessonAssignmentPolicy
{
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

    /**
     * Start or resume work. Active student membership, or active teacher /
     * admin access to the class.
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

    private function membership(User $user, int $schoolClassId): ?ClassMembership
    {
        return ClassMembership::query()
            ->where('school_class_id', $schoolClassId)
            ->where('user_id', $user->id)
            ->first();
    }
}
