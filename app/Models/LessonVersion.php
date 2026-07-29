<?php

namespace App\Models;

use App\Exceptions\ImmutableLessonVersionException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A published, compiled lesson manifest. Immutable once created:
 * any update or delete attempt throws.
 */
class LessonVersion extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'version' => 'integer',
            'schema_version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Catch every mutation path (save(), update(), mass update via
        // model events, delete(), etc.) on existing rows.
        static::updating(function () {
            throw ImmutableLessonVersionException::forOperation('update');
        });

        static::deleting(function () {
            throw ImmutableLessonVersionException::forOperation('delete');
        });
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw ImmutableLessonVersionException::forOperation('update');
        }

        return parent::update($attributes, $options);
    }

    public function delete(): ?bool
    {
        if ($this->exists) {
            throw ImmutableLessonVersionException::forOperation('delete');
        }

        return parent::delete();
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
