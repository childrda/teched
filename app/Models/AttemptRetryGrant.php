<?php

namespace App\Models;

use App\Exceptions\ImmutableAttemptRetryGrantException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable grant of extra submissions for one block on one attempt.
 * A correction is another grant — never an edit.
 *
 * Immutability is enforced for all ELOQUENT MODEL paths: update(), save() on
 * a dirty existing model, and delete() all throw via these events plus the
 * overrides below. Model events do NOT fire for query-builder mass updates
 * (AttemptRetryGrant::where(...)->update(...)) or raw DB writes — those bypass
 * this guard, so never mutate attempt_retry_grants through the query builder.
 */
class AttemptRetryGrant extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'additional_attempts' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw ImmutableAttemptRetryGrantException::forOperation('update');
        });

        static::deleting(function () {
            throw ImmutableAttemptRetryGrantException::forOperation('delete');
        });
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw ImmutableAttemptRetryGrantException::forOperation('update');
        }

        return parent::update($attributes, $options);
    }

    public function delete(): ?bool
    {
        if ($this->exists) {
            throw ImmutableAttemptRetryGrantException::forOperation('delete');
        }

        return parent::delete();
    }

    public function lessonAttempt(): BelongsTo
    {
        return $this->belongsTo(LessonAttempt::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
