<?php

namespace App\Models;

use App\Enums\ClassRole;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'available_at' => 'datetime',
            // Informational only in 4A — deliberately unenforced.
            'due_at' => 'datetime',
            // Reserved and unread in this phase.
            'settings' => 'array',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function lessonVersion(): BelongsTo
    {
        return $this->belongsTo(LessonVersion::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(LessonAttempt::class);
    }

    public function isAvailable(?\DateTimeInterface $at = null): bool
    {
        if ($this->available_at === null) {
            return true;
        }

        $at = $at ?? now();

        return $this->available_at->lte($at);
    }

    /**
     * List-query companion to LessonAssignmentPolicy.
     * Teachers: assignments in classes they actively teach.
     * Students: assignments in classes they belong to (including withdrawn).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === UserRole::Admin) {
            return $query;
        }

        if ($user->role === UserRole::Teacher) {
            return $query->whereHas('schoolClass.memberships', function (Builder $memberships) use ($user) {
                $memberships
                    ->where('user_id', $user->id)
                    ->where('role', ClassRole::Teacher->value)
                    ->whereNull('withdrawn_at');
            });
        }

        return $query->whereHas('schoolClass.memberships', function (Builder $memberships) use ($user) {
            $memberships
                ->where('user_id', $user->id)
                ->where('role', ClassRole::Student->value);
        });
    }
}
