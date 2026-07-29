<?php

namespace App\Models;

use App\Enums\BlockType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
}
