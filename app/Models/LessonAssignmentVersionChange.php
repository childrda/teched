<?php

namespace App\Models;

use App\Exceptions\ImmutableLessonAssignmentAuditException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable repin audit for a lesson assignment.
 *
 * Model events do NOT fire for query-builder mass updates — never mutate
 * these rows through the query builder.
 */
class LessonAssignmentVersionChange extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw ImmutableLessonAssignmentAuditException::forOperation('update');
        });

        static::deleting(function () {
            throw ImmutableLessonAssignmentAuditException::forOperation('delete');
        });
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw ImmutableLessonAssignmentAuditException::forOperation('update');
        }

        return parent::update($attributes, $options);
    }

    public function delete(): ?bool
    {
        if ($this->exists) {
            throw ImmutableLessonAssignmentAuditException::forOperation('delete');
        }

        return parent::delete();
    }

    public function lessonAssignment(): BelongsTo
    {
        return $this->belongsTo(LessonAssignment::class);
    }

    public function previousLessonVersion(): BelongsTo
    {
        return $this->belongsTo(LessonVersion::class, 'previous_lesson_version_id');
    }

    public function newLessonVersion(): BelongsTo
    {
        return $this->belongsTo(LessonVersion::class, 'new_lesson_version_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
