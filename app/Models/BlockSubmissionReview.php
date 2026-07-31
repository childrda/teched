<?php

namespace App\Models;

use App\Exceptions\ImmutableBlockSubmissionReviewException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable teacher review of one short_response/cer submission.
 * Changing a review writes a new row — never an edit.
 *
 * Immutability is enforced for all ELOQUENT MODEL paths: update(), save() on
 * a dirty existing model, and delete() all throw via these events plus the
 * overrides below. Model events do NOT fire for query-builder mass updates
 * (BlockSubmissionReview::query()->where(...)->update(...)) or raw DB writes
 * — those bypass this guard, so never mutate block_submission_reviews through
 * the query builder. The guarantee holds through the service boundary and
 * code review, not through Eloquent alone.
 */
class BlockSubmissionReview extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'points_awarded' => 'integer',
            'points_possible' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw ImmutableBlockSubmissionReviewException::forOperation('update');
        });

        static::deleting(function () {
            throw ImmutableBlockSubmissionReviewException::forOperation('delete');
        });
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw ImmutableBlockSubmissionReviewException::forOperation('update');
        }

        return parent::update($attributes, $options);
    }

    public function delete(): ?bool
    {
        if ($this->exists) {
            throw ImmutableBlockSubmissionReviewException::forOperation('delete');
        }

        return parent::delete();
    }

    public function blockSubmission(): BelongsTo
    {
        return $this->belongsTo(BlockSubmission::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
