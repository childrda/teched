<?php

namespace App\Models;

use App\Exceptions\ImmutableLessonOwnerChangeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit row for lesson ownership changes.
 * Migration repairs use source=migration with null changed_by_user_id;
 * manual reassignment always records a real actor.
 */
class LessonOwnerChange extends Model
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
            throw ImmutableLessonOwnerChangeException::forOperation('update');
        });

        static::deleting(function () {
            throw ImmutableLessonOwnerChangeException::forOperation('delete');
        });
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw ImmutableLessonOwnerChangeException::forOperation('update');
        }

        return parent::update($attributes, $options);
    }

    public function delete(): ?bool
    {
        if ($this->exists) {
            throw ImmutableLessonOwnerChangeException::forOperation('delete');
        }

        return parent::delete();
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
