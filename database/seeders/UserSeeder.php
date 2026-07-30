<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Development accounts for local login. Refuses to run in production —
 * these passwords are documented in the README on purpose.
 */
class UserSeeder extends Seeder
{
    /** Documented development password — never a real-looking secret. */
    public const DEV_PASSWORD = 'password';

    public const TEACHER_EMAIL = 'teacher@teched.test';

    public const ADMIN_EMAIL = 'admin@teched.test';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'UserSeeder refuses to run in production: it creates accounts with a documented development password.'
            );
        }

        $this->seedUser('Seed Admin', self::ADMIN_EMAIL, UserRole::Admin);
        $this->seedUser('Seed Teacher', self::TEACHER_EMAIL, UserRole::Teacher);
        $this->seedUser('Seed Student One', 'student1@teched.test', UserRole::Student);
        $this->seedUser('Seed Student Two', 'student2@teched.test', UserRole::Student);
    }

    private function seedUser(string $name, string $email, UserRole $role): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::DEV_PASSWORD),
            ]
        );

        // role is not fillable — set it explicitly every seed so a prior
        // incomplete row cannot linger as the wrong privilege.
        $user->forceFill([
            'name' => $name,
            'role' => $role,
            'password' => Hash::make(self::DEV_PASSWORD),
        ])->save();
    }
}
