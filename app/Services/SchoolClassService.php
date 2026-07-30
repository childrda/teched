<?php

namespace App\Services;

use App\Enums\ClassRole;
use App\Models\ClassMembership;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Thin class create/update. Creating a class writes teacher_id (creator
 * provenance only) and an active ClassRole::Teacher membership in one
 * transaction — memberships are the sole authorization source.
 */
class SchoolClassService
{
    public function create(User $actor, array $data): SchoolClass
    {
        $name = trim((string) ($data['name'] ?? ''));
        $schoolYear = trim((string) ($data['school_year'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'A class name is required.',
            ]);
        }

        if ($schoolYear === '') {
            throw ValidationException::withMessages([
                'school_year' => 'A school year is required.',
            ]);
        }

        return DB::transaction(function () use ($actor, $name, $schoolYear, $data) {
            $class = SchoolClass::query()->create([
                'name' => $name,
                'school_year' => $schoolYear,
                'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
                'teacher_id' => $actor->id,
                // Manual classes leave both external columns null. The unique
                // index permits many null pairs; Phase 7 populates imports.
                'external_provider' => null,
                'external_id' => null,
            ]);

            ClassMembership::query()->create([
                'school_class_id' => $class->id,
                'user_id' => $actor->id,
                'role' => ClassRole::Teacher,
                'joined_at' => now(),
                'withdrawn_at' => null,
            ]);

            return $class->fresh(['memberships']);
        });
    }

    /**
     * Update name, school_year, and/or active. Deactivation changes no
     * memberships, assignments, or attempts — students keep view + resume.
     *
     * @see LessonAssignmentService::mayStartAttempt() for inactive-class start refusal
     * @see LessonAssignmentService::mayResumeAttempt() for inactive-class resume allowance
     */
    public function update(SchoolClass $schoolClass, array $data): SchoolClass
    {
        return DB::transaction(function () use ($schoolClass, $data) {
            $locked = SchoolClass::query()->whereKey($schoolClass->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('name', $data)) {
                $name = trim((string) $data['name']);
                if ($name === '') {
                    throw ValidationException::withMessages([
                        'name' => 'A class name is required.',
                    ]);
                }
                $locked->name = $name;
            }

            if (array_key_exists('school_year', $data)) {
                $schoolYear = trim((string) $data['school_year']);
                if ($schoolYear === '') {
                    throw ValidationException::withMessages([
                        'school_year' => 'A school year is required.',
                    ]);
                }
                $locked->school_year = $schoolYear;
            }

            if (array_key_exists('active', $data)) {
                $locked->active = (bool) $data['active'];
            }

            $locked->save();

            return $locked->fresh();
        });
    }
}
