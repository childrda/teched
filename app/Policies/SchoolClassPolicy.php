<?php

namespace App\Policies;

use App\Enums\ClassRole;
use App\Models\ClassMembership;
use App\Models\SchoolClass;
use App\Models\User;

class SchoolClassPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher() || $user->isStudent();
    }

    public function view(User $user, SchoolClass $schoolClass): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->activeMembership($user, $schoolClass) !== null;
    }

    public function update(User $user, SchoolClass $schoolClass): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $membership = $this->activeMembership($user, $schoolClass);

        return $membership !== null && $membership->role === ClassRole::Teacher;
    }

    private function activeMembership(User $user, SchoolClass $schoolClass): ?ClassMembership
    {
        return ClassMembership::query()
            ->where('school_class_id', $schoolClass->id)
            ->where('user_id', $user->id)
            ->whereNull('withdrawn_at')
            ->first();
    }
}
