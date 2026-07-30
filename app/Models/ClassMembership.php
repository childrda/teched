<?php

namespace App\Models;

use App\Enums\ClassRole;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ClassMembership extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'role' => ClassRole::class,
            'joined_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ClassMembership $membership) {
            $membership->assertTeacherRoleAllowed();
        });
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->withdrawn_at === null;
    }

    /**
     * Only global teachers/admins may hold ClassRole::Teacher. Enforced at
     * save time so neither UI nor a forgotten staff endpoint can invent a
     * student-as-teacher roster row.
     */
    private function assertTeacherRoleAllowed(): void
    {
        if ($this->role !== ClassRole::Teacher) {
            return;
        }

        $user = $this->relationLoaded('user')
            ? $this->user
            : User::query()->find($this->user_id);

        if ($user === null) {
            throw ValidationException::withMessages([
                'user_id' => 'Membership requires a valid user.',
            ]);
        }

        if ($user->role !== UserRole::Teacher && $user->role !== UserRole::Admin) {
            throw ValidationException::withMessages([
                'role' => 'Only teachers and admins may hold a class teacher membership.',
            ]);
        }
    }
}
