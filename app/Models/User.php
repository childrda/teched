<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Mass-assignable attributes. role, google_id, deactivated_at, and
     * preferences are deliberately absent — set via services (forceFill).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'preferences' => 'array',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function isStudent(): bool
    {
        return $this->role === UserRole::Student;
    }

    public function isTeacher(): bool
    {
        return $this->role === UserRole::Teacher;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->isDeactivated()) {
            return false;
        }

        return $this->isTeacher() || $this->isAdmin();
    }

    public function lessonAttempts(): HasMany
    {
        return $this->hasMany(LessonAttempt::class);
    }

    public function accountChanges(): HasMany
    {
        return $this->hasMany(UserAccountChange::class);
    }
}
