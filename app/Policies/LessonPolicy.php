<?php

namespace App\Policies;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;

class LessonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isTeacher() || $user->isAdmin();
    }

    public function view(User $user, Lesson $lesson): bool
    {
        return $this->ownsOrAdmin($user, $lesson);
    }

    public function create(User $user): bool
    {
        return $user->isTeacher() || $user->isAdmin();
    }

    public function update(User $user, Lesson $lesson): bool
    {
        // Archived lessons remain editable; publishing is gated separately.
        return $this->ownsOrAdmin($user, $lesson);
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->ownsOrAdmin($user, $lesson);
    }

    public function publish(User $user, Lesson $lesson): bool
    {
        if (! $this->ownsOrAdmin($user, $lesson)) {
            return false;
        }

        return $lesson->status !== LessonStatus::Archived;
    }

    public function archive(User $user, Lesson $lesson): bool
    {
        if (! $this->ownsOrAdmin($user, $lesson)) {
            return false;
        }

        return $lesson->status === LessonStatus::Published;
    }

    public function unarchive(User $user, Lesson $lesson): bool
    {
        if (! $this->ownsOrAdmin($user, $lesson)) {
            return false;
        }

        return $lesson->status === LessonStatus::Archived;
    }

    private function ownsOrAdmin(User $user, Lesson $lesson): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isTeacher()) {
            return false;
        }

        return (int) $lesson->created_by_user_id === (int) $user->getKey();
    }
}
