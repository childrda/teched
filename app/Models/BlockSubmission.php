<?php

namespace App\Models;

use App\Exceptions\ImmutableBlockSubmissionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An immutable record of something a student submitted. Once created, the
 * row never changes — a new attempt_number is a new row.
 *
 * Immutability is enforced for all ELOQUENT MODEL paths: update(), save() on
 * a dirty existing model, and delete() all throw via these events plus the
 * overrides below. Model events do NOT fire for query-builder mass updates
 * (BlockSubmission::where(...)->update(...)) or raw DB writes — those bypass
 * this guard, so never mutate block_submissions through the query builder.
 */
class BlockSubmission extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'grading_result' => 'array',
            'score' => 'float',
            'max_score' => 'float',
            'percentage' => 'float',
            'passed' => 'boolean',
            'requires_manual_review' => 'boolean',
            'attempt_number' => 'integer',
            'active_seconds_at_submission' => 'integer',
            'submitted_at' => 'datetime',
            'revealed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw ImmutableBlockSubmissionException::forOperation('update');
        });

        static::deleting(function () {
            throw ImmutableBlockSubmissionException::forOperation('delete');
        });
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw ImmutableBlockSubmissionException::forOperation('update');
        }

        return parent::update($attributes, $options);
    }

    public function delete(): ?bool
    {
        if ($this->exists) {
            throw ImmutableBlockSubmissionException::forOperation('delete');
        }

        return parent::delete();
    }

    public function lessonAttempt(): BelongsTo
    {
        return $this->belongsTo(LessonAttempt::class);
    }

    public function lessonVersion(): BelongsTo
    {
        return $this->belongsTo(LessonVersion::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(BlockSubmissionReview::class);
    }

    /**
     * Deterministic latest review: created_at DESC, id DESC (not timestamp alone).
     */
    public function latestReview(): HasOne
    {
        return $this->hasOne(BlockSubmissionReview::class)
            ->ofMany(['created_at' => 'max', 'id' => 'max']);
    }
}
