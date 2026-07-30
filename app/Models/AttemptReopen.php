<?php

namespace App\Models;

use App\Exceptions\ImmutableAttemptReopenException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable audit row for reopening a completed attempt. A second reopen
 * is another row — never an edit.
 *
 * Model events do not fire for query-builder mass updates; never mutate
 * attempt_reopens through the query builder.
 */
class AttemptReopen extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'previous_completed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw ImmutableAttemptReopenException::forOperation('update');
        });

        static::deleting(function () {
            throw ImmutableAttemptReopenException::forOperation('delete');
        });
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw ImmutableAttemptReopenException::forOperation('update');
        }

        return parent::update($attributes, $options);
    }

    public function delete(): ?bool
    {
        if ($this->exists) {
            throw ImmutableAttemptReopenException::forOperation('delete');
        }

        return parent::delete();
    }

    public function lessonAttempt(): BelongsTo
    {
        return $this->belongsTo(LessonAttempt::class);
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_user_id');
    }
}
