<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Services\UserAccountService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Bootstrap / provision a staff (or student) account in any environment,
 * including production — the deliberate exception to UserSeeder's guard.
 *
 * Prefer interactive secret() for passwords; a --password value enters shell
 * history. You may also pass the password via TECHED_STAFF_PASSWORD.
 *
 * --force only permits non-interactive execution. It never overwrites an
 * existing account.
 */
class CreateStaffUserCommand extends Command
{
    protected $signature = 'teched:create-staff-user
        {--name= : Display name}
        {--email= : Email (normalized to lowercase)}
        {--role= : student, teacher, or admin}
        {--password= : Local password (enters shell history — prefer interactive secret() or TECHED_STAFF_PASSWORD)}
        {--force : Permit non-interactive execution (does not overwrite)}';

    protected $description = 'Create a provisioned user account (works in production; never overwrites)';

    public function handle(UserAccountService $accounts): int
    {
        try {
            if (! $this->input->isInteractive() && ! $this->option('force')) {
                $this->error('Non-interactive execution requires --force.');

                return self::FAILURE;
            }

            $name = $this->option('name') ?: $this->ask('Name');
            $email = $this->option('email') ?: $this->ask('Email');
            $role = $this->option('role') ?: $this->choice('Role', ['teacher', 'admin', 'student'], 0);

            $roleEnum = UserRole::tryFrom(strtolower(trim((string) $role)));
            if ($roleEnum === null) {
                $this->error('Role must be student, teacher, or admin.');

                return self::FAILURE;
            }

            if ($roleEnum === UserRole::Admin && app()->environment('production')) {
                if ($this->option('force')) {
                    // Non-interactive production admin creation permitted by --force.
                } elseif (! $this->confirm('Create an admin account in production?', false)) {
                    $this->warn('Aborted.');

                    return self::FAILURE;
                }
            }

            $password = $this->resolvePassword();

            $user = $accounts->createProvisionedUser([
                'name' => $name,
                'email' => $email,
                'role' => $roleEnum,
                'password' => $password,
            ], actor: null);

            $this->info("Created user #{$user->id} <{$user->email}> as {$user->role->value}"
                .($accounts->awaitingGoogleSignIn($user) ? ' (awaiting Google sign-in)' : ''));

            return self::SUCCESS;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function resolvePassword(): ?string
    {
        if ($this->option('password') !== null && $this->option('password') !== '') {
            $this->warn('A command-line password enters shell history; prefer interactive secret() or TECHED_STAFF_PASSWORD.');

            return (string) $this->option('password');
        }

        $fromEnv = env('TECHED_STAFF_PASSWORD');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $password = $this->secret('Password (blank = Google-provisioned account)');

        return $password === null || $password === '' ? null : $password;
    }
}
