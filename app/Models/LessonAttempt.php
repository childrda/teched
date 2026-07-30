<?php

namespace App\Models;

use App\Enums\AttemptStatus;
use App\Enums\ClassRole;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'superseded_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'active_seconds' => 'integer',
            'revision' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function lessonVersion(): BelongsTo
    {
        return $this->belongsTo(LessonVersion::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(LessonAssignment::class, 'lesson_assignment_id');
    }

    public function blockStates(): HasMany
    {
        return $this->hasMany(BlockState::class);
    }

    public function blockSubmissions(): HasMany
    {
        return $this->hasMany(BlockSubmission::class);
    }

    public function retryGrants(): HasMany
    {
        return $this->hasMany(AttemptRetryGrant::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superseded_by_user_id');
    }

    public function isInProgress(): bool
    {
        return $this->status === AttemptStatus::InProgress;
    }

    public function isCompleted(): bool
    {
        return $this->status === AttemptStatus::Completed;
    }

    public function isSuperseded(): bool
    {
        return $this->status === AttemptStatus::Superseded;
    }

    /**
     * List-query companion to LessonAttemptPolicy. Every staff/student list
     * must go through this scope so unauthorized rows never leave SQL.
     *
     * - admin: all attempts
     * - teacher: assigned attempts whose assignment's class they actively teach
     * - student: their own attempts only
     * - unassigned attempts: owning student and admins only
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === UserRole::Admin) {
            return $query;
        }

        if ($user->role === UserRole::Teacher) {
            return $query
                ->whereNotNull('lesson_attempts.lesson_assignment_id')
                ->whereHas('assignment.schoolClass.memberships', function (Builder $memberships) use ($user) {
                    $memberships
                        ->where('user_id', $user->id)
                        ->where('role', ClassRole::Teacher->value)
                        ->whereNull('withdrawn_at');
                });
        }

        // Students (and any other non-staff role) see only their own.
        return $query->where('lesson_attempts.user_id', $user->id);
    }
}
