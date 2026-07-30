<?php

namespace App\Models;

use App\Enums\ClassRole;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * List-query companion to SchoolClassPolicy. Staff lists use active
     * teacher membership; admins see every class.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === UserRole::Admin) {
            return $query;
        }

        if ($user->role === UserRole::Teacher) {
            return $query->whereHas('memberships', function (Builder $memberships) use ($user) {
                $memberships
                    ->where('user_id', $user->id)
                    ->where('role', ClassRole::Teacher->value)
                    ->whereNull('withdrawn_at');
            });
        }

        return $query->whereHas('memberships', function (Builder $memberships) use ($user) {
            $memberships->where('user_id', $user->id);
        });
    }
}
