<?php

namespace App\Services;

use App\Enums\ClassRole;
use App\Enums\UserRole;
use App\Models\ClassMembership;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Roster mutations for a class. Soft withdrawal only — never deletes rows.
 * A class must always keep at least one active ClassRole::Teacher membership.
 */
class ClassMembershipService
{
    public function addOrReactivateStudent(SchoolClass $schoolClass, User $member, User $actor): ClassMembership
    {
        return $this->addOrReactivate($schoolClass, $member, ClassRole::Student);
    }

    public function addOrReactivateTeacher(SchoolClass $schoolClass, User $member, User $actor): ClassMembership
    {
        return $this->addOrReactivate($schoolClass, $member, ClassRole::Teacher);
    }

    public function changeRole(ClassMembership $membership, ClassRole $role, User $actor): ClassMembership
    {
        return DB::transaction(function () use ($membership, $role) {
            $locked = ClassMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($locked->role === $role && $locked->isActive()) {
                return $locked;
            }

            if ($locked->role === ClassRole::Teacher && $role !== ClassRole::Teacher && $locked->isActive()) {
                $this->assertNotLastActiveTeacher($locked);
            }

            if ($role === ClassRole::Teacher) {
                $this->assertUserMayBeClassTeacher($locked->user ?? User::query()->findOrFail($locked->user_id));
            }

            $locked->role = $role;
            $locked->withdrawn_at = null;
            $locked->joined_at ??= now();
            $locked->save();

            return $locked->fresh(['user']);
        });
    }

    public function withdraw(ClassMembership $membership, User $actor): ClassMembership
    {
        return DB::transaction(function () use ($membership) {
            $locked = ClassMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                return $locked;
            }

            if ($locked->role === ClassRole::Teacher) {
                $this->assertNotLastActiveTeacher($locked);
            }

            $locked->withdrawn_at = now();
            $locked->save();

            return $locked->fresh(['user']);
        });
    }

    private function addOrReactivate(SchoolClass $schoolClass, User $member, ClassRole $role): ClassMembership
    {
        return DB::transaction(function () use ($schoolClass, $member, $role) {
            SchoolClass::query()->whereKey($schoolClass->id)->lockForUpdate()->firstOrFail();

            if ($role === ClassRole::Teacher) {
                $this->assertUserMayBeClassTeacher($member);
            }

            $existing = ClassMembership::query()
                ->where('school_class_id', $schoolClass->id)
                ->where('user_id', $member->id)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                return ClassMembership::query()->create([
                    'school_class_id' => $schoolClass->id,
                    'user_id' => $member->id,
                    'role' => $role,
                    'joined_at' => now(),
                    'withdrawn_at' => null,
                ])->fresh(['user']);
            }

            if ($existing->isActive() && $existing->role === $role) {
                return $existing->fresh(['user']);
            }

            if ($existing->isActive()
                && $existing->role === ClassRole::Teacher
                && $role !== ClassRole::Teacher
            ) {
                $this->assertNotLastActiveTeacher($existing);
            }

            $existing->role = $role;
            $existing->withdrawn_at = null;
            $existing->joined_at ??= now();
            $existing->save();

            return $existing->fresh(['user']);
        });
    }

    private function assertUserMayBeClassTeacher(User $user): void
    {
        if ($user->role !== UserRole::Teacher && $user->role !== UserRole::Admin) {
            throw ValidationException::withMessages([
                'user_id' => 'Only teachers and admins may hold a class teacher membership.',
            ]);
        }
    }

    private function assertNotLastActiveTeacher(ClassMembership $membership): void
    {
        $activeTeachers = ClassMembership::query()
            ->where('school_class_id', $membership->school_class_id)
            ->where('role', ClassRole::Teacher->value)
            ->whereNull('withdrawn_at')
            ->count();

        if ($activeTeachers <= 1) {
            throw ValidationException::withMessages([
                'membership' => 'A class must keep at least one active teacher. Add another teacher before withdrawing or demoting this one.',
            ]);
        }
    }
}
