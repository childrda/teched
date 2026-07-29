<?php

namespace App\Models;

use App\Enums\PageCompletionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

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

    /**
     * Reorders a lesson's pages to match $orderedPageIds (a permutation of
     * the lesson's stable page_id values). Safe under the
     * (lesson_id, position) unique constraint via a two-phase write inside
     * one transaction: all positions are first offset beyond the current
     * max, then final 1..n positions are written. Any failure rolls the
     * whole reorder back. Stable page_ids are never touched.
     *
     * @param list<string> $orderedPageIds
     */
    public static function reorderWithin(Lesson $lesson, array $orderedPageIds): void
    {
        DB::transaction(function () use ($lesson, $orderedPageIds) {
            $pages = static::query()
                ->where('lesson_id', $lesson->getKey())
                ->lockForUpdate()
                ->get()
                ->keyBy('page_id');

            if (count($orderedPageIds) !== $pages->count()
                || count(array_unique($orderedPageIds)) !== count($orderedPageIds)) {
                throw new InvalidArgumentException(
                    'orderedPageIds must contain each of the lesson\'s page_ids exactly once.'
                );
            }

            // Phase 1: shift everything beyond the current max position so
            // phase 2 can never collide with a not-yet-moved sibling.
            $offset = (int) $pages->max('position');

            static::query()
                ->where('lesson_id', $lesson->getKey())
                ->update(['position' => DB::raw("position + {$offset}")]);

            // Phase 2: write final positions.
            foreach (array_values($orderedPageIds) as $index => $pageId) {
                $page = $pages[$pageId] ?? throw new InvalidArgumentException(
                    "Unknown page_id \"{$pageId}\" for lesson {$lesson->code}."
                );

                static::query()->whereKey($page->getKey())->update(['position' => $index + 1]);
            }

            $lesson->markUnpublishedChanges();
        });
    }
}
