<?php

namespace App\Models;

use App\Enums\LessonStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Lesson extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Authoring-only lesson settings. These never appear in a compiled
     * manifest: default_allow_read_aloud seeds a new page's
     * settings.allow_read_aloud and is not consulted again afterwards.
     */
    public const DEFAULT_SETTINGS = [
        'default_allow_read_aloud' => true,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => LessonStatus::class,
            'success_criteria' => 'array',
            'standards' => 'array',
            'settings' => 'array',
            'current_version' => 'integer',
            'estimated_minutes' => 'integer',
            'has_unpublished_changes' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Lesson $lesson) {
            if (blank($lesson->uuid)) {
                $lesson->uuid = (string) Str::uuid();
            }

            // Application-level JSON default (MariaDB/MySQL JSON columns
            // cannot be relied on for expression defaults).
            $lesson->settings = array_merge(self::DEFAULT_SETTINGS, $lesson->settings ?? []);
        });
    }

    public function pages(): HasMany
    {
        return $this->hasMany(LessonPage::class)->orderBy('position');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LessonVersion::class);
    }

    /**
     * The published version currently served to students, or null when
     * current_version is 0 or the version row is missing.
     */
    public function currentVersion(): ?LessonVersion
    {
        if ($this->current_version < 1) {
            return null;
        }

        return $this->versions()->where('version', $this->current_version)->first();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Flag that authoring rows changed since the last publish. Uses a direct
     * query so no model events re-fire; never touches status.
     */
    public function markUnpublishedChanges(): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->update(['has_unpublished_changes' => true]);

        $this->has_unpublished_changes = true;
    }
}
