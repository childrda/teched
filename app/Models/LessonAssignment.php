<?php

namespace App\Models;

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
}
