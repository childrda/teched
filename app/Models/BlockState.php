<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'revision' => 'integer',
        ];
    }

    public function lessonAttempt(): BelongsTo
    {
        return $this->belongsTo(LessonAttempt::class);
    }
}
