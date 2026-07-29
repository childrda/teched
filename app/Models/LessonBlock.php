<?php

namespace App\Models;

use App\Enums\BlockType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LessonBlock extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => BlockType::class,
            'config' => 'array',
            'grading' => 'array',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LessonBlock $block) {
            if (blank($block->block_id)) {
                $block->block_id = (string) Str::ulid();
            }

            // Application-level JSON default.
            $block->config ??= [];
        });

        $flagLesson = function (LessonBlock $block) {
            $block->page?->lesson?->markUnpublishedChanges();
        };

        static::created($flagLesson);
        static::updated($flagLesson);
        static::deleted($flagLesson);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(LessonPage::class, 'lesson_page_id');
    }

    /**
     * Reorders a page's blocks to match $orderedBlockIds (a permutation of
     * the page's stable block_id values). Safe under the
     * (lesson_page_id, position) unique constraint via a two-phase write
     * inside one transaction: all positions are first offset beyond the
     * current max, then final 1..n positions are written. Any failure rolls
     * the whole reorder back. Stable block_ids are never touched.
     *
     * @param list<string> $orderedBlockIds
     */
    public static function reorderWithin(LessonPage $page, array $orderedBlockIds): void
    {
        DB::transaction(function () use ($page, $orderedBlockIds) {
            $blocks = static::query()
                ->where('lesson_page_id', $page->getKey())
                ->lockForUpdate()
                ->get()
                ->keyBy('block_id');

            if (count($orderedBlockIds) !== $blocks->count()
                || count(array_unique($orderedBlockIds)) !== count($orderedBlockIds)) {
                throw new InvalidArgumentException(
                    'orderedBlockIds must contain each of the page\'s block_ids exactly once.'
                );
            }

            // Phase 1: shift everything clear of the 1..n range phase 2
            // writes into, so phase 2 can never collide with a not-yet-moved
            // sibling. Clearing max + count + 1 holds whether the existing
            // positions are 0-based or 1-based.
            $offset = (int) $blocks->max('position') + $blocks->count() + 1;

            static::query()
                ->where('lesson_page_id', $page->getKey())
                ->update(['position' => DB::raw("position + {$offset}")]);

            // Phase 2: write final positions.
            foreach (array_values($orderedBlockIds) as $index => $blockId) {
                $block = $blocks[$blockId] ?? throw new InvalidArgumentException(
                    "Unknown block_id \"{$blockId}\" for page \"{$page->title}\"."
                );

                static::query()->whereKey($block->getKey())->update(['position' => $index + 1]);
            }

            $page->lesson?->markUnpublishedChanges();
        });
    }
}
