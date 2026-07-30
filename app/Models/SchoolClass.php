<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolClass extends Model
{
    protected $table = 'school_classes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ClassMembership::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LessonAssignment::class);
    }
}
