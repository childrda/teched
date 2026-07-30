<?php

namespace App\Services;

use App\Enums\ClassRole;
use App\Enums\LessonStatus;
use App\Models\ClassMembership;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\LessonAssignmentStatusChange;
use App\Models\LessonAssignmentVersionChange;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\DatabaseErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assignment persistence and the student access triad.
 *
 * mayViewAssignment / mayStartAttempt / mayResumeAttempt are separate on
 * purpose — collapsing them into one canAccess() is how "inactive class still
 * resumes" turns into "everything freezes."
 */
class LessonAssignmentService
{
    public function create(SchoolClass $schoolClass, User $actor, array $data): LessonAssignment
    {
        $lessonId = (int) ($data['lesson_id'] ?? 0);
        if ($lessonId < 1) {
            throw ValidationException::withMessages([
                'lesson_id' => 'A published lesson is required.',
            ]);
        }

        return DB::transaction(function () use ($schoolClass, $actor, $data, $lessonId) {
            SchoolClass::query()->whereKey($schoolClass->id)->lockForUpdate()->firstOrFail();

            // Lock the lesson and resolve the pin inside the transaction so a
            // republish between form load and save cannot pin a stale version.
            $lesson = Lesson::query()->whereKey($lessonId)->lockForUpdate()->first();

            if ($lesson === null) {
                throw ValidationException::withMessages([
                    'lesson_id' => 'A published lesson is required.',
                ]);
            }

            if ($lesson->status !== LessonStatus::Published) {
                throw ValidationException::withMessages([
                    'lesson_id' => 'Only published lessons can be assigned.',
                ]);
            }

            $version = $lesson->currentVersion();
            if ($version === null) {
                throw ValidationException::withMessages([
                    'lesson_id' => 'Only published lessons can be assigned.',
                ]);
            }

            $this->assertNoActiveAssignment($schoolClass->id, $lesson->id);

            try {
                return LessonAssignment::query()->create([
                    'school_class_id' => $schoolClass->id,
                    'lesson_id' => $lesson->id,
                    'lesson_version_id' => $version->id,
                    'assigned_by_user_id' => $actor->id,
                    'available_at' => $data['available_at'] ?? null,
                    'due_at' => $data['due_at'] ?? null,
                    'settings' => null,
                    'archived_at' => null,
                ])->fresh(['lesson', 'lessonVersion']);
            } catch (QueryException $e) {
                if (! DatabaseErrors::isUniqueViolation($e)) {
                    throw $e;
                }

                // Another request won the active_scope race — surface a clear
                // duplicate message rather than a database exception.
                throw ValidationException::withMessages([
                    'lesson_id' => 'This class already has an active assignment for that lesson. Archive the existing one before assigning it again.',
                ]);
            }
        });
    }

    /**
     * Update availability / due dates. Archived assignments are historical
     * and read-only except for Unarchive.
     */
    public function update(LessonAssignment $assignment, array $data): LessonAssignment
    {
        return DB::transaction(function () use ($assignment, $data) {
            $locked = LessonAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isArchived()) {
                throw ValidationException::withMessages([
                    'assignment' => 'An archived assignment cannot be edited. Unarchive it first, or leave it as historical record.',
                ]);
            }

            if (array_key_exists('available_at', $data)) {
                $locked->available_at = $data['available_at'];
            }

            if (array_key_exists('due_at', $data)) {
                $locked->due_at = $data['due_at'];
            }

            $locked->save();

            return $locked->fresh(['lesson', 'lessonVersion']);
        });
    }

    public function archive(LessonAssignment $assignment, User $actor): LessonAssignment
    {
        return DB::transaction(function () use ($assignment, $actor) {
            $locked = LessonAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isArchived()) {
                return $locked;
            }

            $locked->archived_at = now();
            $locked->save();

            LessonAssignmentStatusChange::query()->create([
                'lesson_assignment_id' => $locked->id,
                'action' => 'archived',
                'changed_by_user_id' => $actor->id,
                'created_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    public function unarchive(LessonAssignment $assignment, User $actor): LessonAssignment
    {
        return DB::transaction(function () use ($assignment, $actor) {
            $locked = LessonAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isArchived()) {
                return $locked;
            }

            $this->assertNoActiveAssignment($locked->school_class_id, $locked->lesson_id, excludingId: $locked->id);

            $locked->archived_at = null;

            try {
                $locked->save();
            } catch (QueryException $e) {
                if (! DatabaseErrors::isUniqueViolation($e)) {
                    throw $e;
                }

                throw ValidationException::withMessages([
                    'assignment' => 'Another active assignment for this lesson already exists in the class. Archive that one before unarchiving.',
                ]);
            }

            LessonAssignmentStatusChange::query()->create([
                'lesson_assignment_id' => $locked->id,
                'action' => 'unarchived',
                'changed_by_user_id' => $actor->id,
                'created_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Repin to the lesson's currently published version. Existing attempts
     * stay on their own pin; only future attempts use the new version.
     */
    public function repinToCurrentVersion(LessonAssignment $assignment, User $actor): LessonAssignment
    {
        return DB::transaction(function () use ($assignment, $actor) {
            $locked = LessonAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isArchived()) {
                throw ValidationException::withMessages([
                    'assignment' => 'An archived assignment cannot be repinned. Unarchive it first.',
                ]);
            }

            $lesson = Lesson::query()->whereKey($locked->lesson_id)->lockForUpdate()->firstOrFail();

            if ($lesson->status === LessonStatus::Archived) {
                throw ValidationException::withMessages([
                    'assignment' => 'Cannot repin: the lesson is archived.',
                ]);
            }

            if ($lesson->status !== LessonStatus::Published) {
                throw ValidationException::withMessages([
                    'assignment' => 'Cannot repin: the lesson has no published version available.',
                ]);
            }

            $newVersion = $lesson->currentVersion();
            if ($newVersion === null) {
                throw ValidationException::withMessages([
                    'assignment' => 'Cannot repin: the lesson has no published version available.',
                ]);
            }

            if ((int) $locked->lesson_version_id === (int) $newVersion->id) {
                // Already pinned to the latest — no-op, no audit row.
                return $locked->fresh(['lessonVersion']);
            }

            $previousVersionId = (int) $locked->lesson_version_id;
            $locked->lesson_version_id = $newVersion->id;
            $locked->save();

            LessonAssignmentVersionChange::query()->create([
                'lesson_assignment_id' => $locked->id,
                'previous_lesson_version_id' => $previousVersionId,
                'new_lesson_version_id' => $newVersion->id,
                'changed_by_user_id' => $actor->id,
                'created_at' => now(),
            ]);

            return $locked->fresh(['lessonVersion']);
        });
    }

    /**
     * Delete only when no attempts exist. Lock first so a concurrent start
     * cannot race into an orphan. Assignments with status/version audit rows
     * also refuse (restrictOnDelete) — archive is the supported retirement path.
     */
    public function delete(LessonAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $locked = LessonAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if ($locked->attempts()->exists()) {
                throw ValidationException::withMessages([
                    'assignment' => 'This assignment has attempts and cannot be deleted. Archive it instead.',
                ]);
            }

            if ($locked->statusChanges()->exists() || $locked->versionChanges()->exists()) {
                throw ValidationException::withMessages([
                    'assignment' => 'This assignment has archive or repin history and cannot be deleted. Archive it instead so the audit trail remains.',
                ]);
            }

            try {
                $locked->delete();
            } catch (QueryException $e) {
                throw ValidationException::withMessages([
                    'assignment' => 'This assignment cannot be deleted. Archive it instead.',
                ]);
            }
        });
    }

    /**
     * See the assignment (dashboard / history). Withdrawn students may still
     * view completed history; inactive class and archived assignment remain
     * viewable.
     */
    public function mayViewAssignment(User $user, LessonAssignment $assignment): bool
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
     * Start a new assigned attempt. Refused for withdrawn membership,
     * inactive class, archived assignment, or future available_at.
     */
    public function mayStartAttempt(User $user, LessonAssignment $assignment): bool
    {
        if (! $this->mayActOnAssignment($user, $assignment)) {
            return false;
        }

        $assignment->loadMissing('schoolClass');

        if (! $assignment->schoolClass->active) {
            return false;
        }

        if ($assignment->isArchived()) {
            return false;
        }

        return $assignment->isAvailable();
    }

    /**
     * Resume an in-progress assigned attempt. Inactive class and archived
     * assignment still allow resume; withdrawn membership does not.
     */
    public function mayResumeAttempt(User $user, LessonAssignment $assignment): bool
    {
        return $this->mayActOnAssignment($user, $assignment);
    }

    /**
     * Active membership (or admin) that can start or resume play — not view.
     */
    private function mayActOnAssignment(User $user, LessonAssignment $assignment): bool
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

    private function assertNoActiveAssignment(int $schoolClassId, int $lessonId, ?int $excludingId = null): void
    {
        $query = LessonAssignment::query()
            ->where('school_class_id', $schoolClassId)
            ->where('lesson_id', $lessonId)
            ->whereNull('archived_at');

        if ($excludingId !== null) {
            $query->whereKeyNot($excludingId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'lesson_id' => 'This class already has an active assignment for that lesson. Archive the existing one before assigning it again.',
            ]);
        }
    }
}
