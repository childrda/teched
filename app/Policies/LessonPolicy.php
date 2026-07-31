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
        if ($this->ownsOrAdmin($user, $lesson)) {
            return true;
        }

        // District library: any teacher may view another owner's published lesson.
        return $user->isTeacher() && $this->isPublishedLibraryLesson($lesson);
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

    /**
     * Preview the saved authoring draft (in-memory compile). Owner/admin only.
     */
    public function previewDraft(User $user, Lesson $lesson): bool
    {
        return $this->update($user, $lesson);
    }

    /**
     * Preview the current published LessonVersion. Any teacher or admin;
     * lesson must be published (not archived) with a version.
     */
    public function previewPublished(User $user, Lesson $lesson): bool
    {
        if (! ($user->isTeacher() || $user->isAdmin())) {
            return false;
        }

        return $this->isPublishedLibraryLesson($lesson);
    }

    /**
     * @deprecated Use previewDraft — kept only for callers that still name preview.
     */
    public function preview(User $user, Lesson $lesson): bool
    {
        return $this->previewDraft($user, $lesson);
    }

    public function duplicate(User $user, Lesson $lesson): bool
    {
        // Duplicating another owner's library lesson is not allowed here —
        // ownership of the source is required (library view alone is not enough).
        return $this->ownsOrAdmin($user, $lesson) && $this->create($user);
    }

    /** Admins only — never the current owner acting alone. */
    public function reassignOwner(User $user, Lesson $lesson): bool
    {
        return $user->isAdmin();
    }

    private function isPublishedLibraryLesson(Lesson $lesson): bool
    {
        return $lesson->status === LessonStatus::Published
            && (int) $lesson->current_version > 0;
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
