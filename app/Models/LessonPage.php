<?php

namespace App\Models;

use App\Enums\PageCompletionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LessonPage extends Model
{
    use HasFactory;

    public const DEFAULT_SETTINGS = [
        'minimum_score' => null,
        'require_all_blocks' => false,
        'allow_back_navigation' => true,
        'allow_skip' => false,
        'show_in_nav' => true,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'completion_type' => PageCompletionType::class,
            'settings' => 'array',
            'position' => 'integer',
            'estimated_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LessonPage $page) {
            if (blank($page->page_id)) {
                $page->page_id = (string) Str::ulid();
            }

            // Application-level JSON default (MariaDB/MySQL JSON columns
            // cannot be relied on for expression defaults).
            $page->settings = array_merge(self::DEFAULT_SETTINGS, $page->settings ?? []);
        });

        $flagLesson = function (LessonPage $page) {
            $page->lesson?->markUnpublishedChanges();
        };

        static::created($flagLesson);
        static::updated($flagLesson);
        static::deleted($flagLesson);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(LessonBlock::class)->orderBy('position');
    }
}
