<?php

namespace App\Services;

use App\Enums\ClassRole;
use App\Enums\UserRole;
use App\Models\ClassMembership;
use App\Models\User;
use App\Models\UserAccountChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sole owner of account provisioning mutations (role, email, password,
 * deactivation, Google identity). Filament and artisan call this — they never
 * call User::update() for those fields.
 */
class UserAccountService
{
    /**
     * Normalize email for storage and Google linking comparisons.
     * Trim + lowercase; uniqueness is enforced case-insensitively in app code.
     */
    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * An operational admin can sign in somehow. A provisioned (passwordless,
     * unlinked) admin does not count — otherwise the last usable admin can
     * demote themselves behind a second unusable row.
     */
    public function isOperationalAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin
            && $user->deactivated_at === null
            && ($user->password !== null || $user->google_id !== null);
    }

    public function createProvisionedUser(array $data, ?User $actor = null): User
    {
        $name = trim((string) ($data['name'] ?? ''));
        $email = $this->normalizeEmail((string) ($data['email'] ?? ''));
        $role = $this->parseRole($data['role'] ?? null);
        $password = $data['password'] ?? null;

        if ($password === '') {
            $password = null;
        }

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Name is required.',
            ]);
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'A valid email address is required.',
            ]);
        }

        $this->assertEmailAvailable($email);

        return DB::transaction(function () use ($name, $email, $role, $password, $actor) {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => $role,
                'google_id' => null,
                'deactivated_at' => null,
            ]);
            $user->save();

            $this->audit($user, 'created', $actor, [
                'role' => $role->value,
                'email' => $email,
                'local_password' => $password !== null,
                'awaiting_google' => $password === null,
            ]);

            return $user->fresh();
        });
    }

    public function updateName(User $user, string $name, ?User $actor = null): User
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Name is required.',
            ]);
        }

        $user->forceFill(['name' => $name])->save();

        return $user->fresh();
    }

    public function changeRole(User $user, UserRole|string $role, ?User $actor = null): User
    {
        $newRole = $this->parseRole($role);

        return DB::transaction(function () use ($user, $newRole, $actor) {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $oldRole = $fresh->role;

            if ($oldRole === $newRole) {
                return $fresh;
            }

            if ($this->isOperationalAdmin($fresh) && $newRole !== UserRole::Admin) {
                $this->assertNotLastOperationalAdmin($fresh, 'demote');
            }

            if (in_array($fresh->role, [UserRole::Teacher, UserRole::Admin], true)
                && ! in_array($newRole, [UserRole::Teacher, UserRole::Admin], true)
            ) {
                $this->assertNotSoleActiveClassTeacher($fresh, 'demote');
            }

            $fresh->forceFill(['role' => $newRole])->save();

            $this->audit($fresh, 'role_changed', $actor, [
                'old_role' => $oldRole->value,
                'new_role' => $newRole->value,
            ]);

            return $fresh->fresh();
        });
    }

    public function changeEmail(User $user, string $email, ?User $actor = null): User
    {
        $normalized = $this->normalizeEmail($email);

        if ($normalized === '' || ! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'A valid email address is required.',
            ]);
        }

        return DB::transaction(function () use ($user, $normalized, $actor) {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $old = $this->normalizeEmail((string) $fresh->email);

            if ($old === $normalized) {
                return $fresh;
            }

            $this->assertEmailAvailable($normalized, $fresh->id);

            $fresh->forceFill(['email' => $normalized])->save();

            $this->audit($fresh, 'email_changed', $actor, [
                'old_email' => $old,
                'new_email' => $normalized,
                'google_linked' => $fresh->google_id !== null,
            ]);

            return $fresh->fresh();
        });
    }

    public function setPassword(User $user, ?string $password, ?User $actor = null): User
    {
        if ($password === '') {
            $password = null;
        }

        return DB::transaction(function () use ($user, $password, $actor) {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Assign plain value; the hashed cast hashes non-null passwords.
            $fresh->forceFill(['password' => $password])->save();

            $this->audit($fresh, 'password_set', $actor, [
                'local_password' => $password !== null,
            ]);

            $this->revokeSessions($fresh);

            return $fresh->fresh();
        });
    }

    public function deactivate(User $user, ?User $actor = null): User
    {
        return DB::transaction(function () use ($user, $actor) {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($fresh->deactivated_at !== null) {
                return $fresh;
            }

            if ($this->isOperationalAdmin($fresh)) {
                $this->assertNotLastOperationalAdmin($fresh, 'deactivate');
            }

            if (in_array($fresh->role, [UserRole::Teacher, UserRole::Admin], true)) {
                $this->assertNotSoleActiveClassTeacher($fresh, 'deactivate');
            }

            $fresh->forceFill(['deactivated_at' => now()])->save();

            $this->audit($fresh, 'deactivated', $actor, [
                'email' => $this->normalizeEmail((string) $fresh->email),
                'role' => $fresh->role->value,
            ]);

            $this->revokeSessions($fresh);

            return $fresh->fresh();
        });
    }

    public function reactivate(User $user, ?User $actor = null): User
    {
        return DB::transaction(function () use ($user, $actor) {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($fresh->deactivated_at === null) {
                return $fresh;
            }

            $fresh->forceFill(['deactivated_at' => null])->save();

            $this->audit($fresh, 'reactivated', $actor, [
                'email' => $this->normalizeEmail((string) $fresh->email),
                'role' => $fresh->role->value,
            ]);

            // Sessions are not restored — they must sign in again.

            return $fresh->fresh();
        });
    }

    /**
     * Phase 6 Google identity linking contract.
     *
     * 2D settled that accounts match on google_id after the first link, never
     * silently by email alone. A provisioned account has no google_id yet, so
     * its first sign-in matches on email — that is provisioning, not takeover —
     * but only when every guard below holds:
     *
     * 1. The target account's google_id is null (never re-link).
     * 2. Str::lower(trim($googleEmail)) === Str::lower(trim($user->email)).
     * 3. The payload's email_verified is true.
     * 4. The hd (hosted domain) claim is in config('google.allowed_hosted_domains').
     *    This stops a personal Gmail claiming a district address.
     * 5. The account is not deactivated.
     * 6. A user_account_changes row is written with action google_linked and
     *    actor = the account holder (not a silent system actor).
     *
     * detail may include google_id_added, email, hosted_domain — never OAuth
     * tokens, never the full Google payload, never a password.
     *
     * Failing any guard refuses the sign-in. Never fall back to creating a new
     * account on a near-miss (that splits one teacher's students across two
     * accounts). After the first successful link, google_id is authoritative
     * forever and email is display data.
     *
     * @param  array{google_id: string, email: string, email_verified: bool, hosted_domain?: ?string}  $identity
     */
    public function linkGoogleIdentity(User $user, array $identity): User
    {
        $googleId = trim((string) ($identity['google_id'] ?? ''));
        $googleEmail = $this->normalizeEmail((string) ($identity['email'] ?? ''));
        $emailVerified = (bool) ($identity['email_verified'] ?? false);
        $hostedDomain = isset($identity['hosted_domain'])
            ? Str::lower(trim((string) $identity['hosted_domain']))
            : null;

        if ($googleId === '') {
            throw ValidationException::withMessages([
                'google' => 'Google identity is incomplete.',
            ]);
        }

        if (! $emailVerified) {
            throw ValidationException::withMessages([
                'google' => 'Google email is not verified.',
            ]);
        }

        $allowed = config('google.allowed_hosted_domains', []);
        if ($hostedDomain === null || $hostedDomain === '' || ! in_array($hostedDomain, $allowed, true)) {
            throw ValidationException::withMessages([
                'google' => 'Google account domain is not allowed.',
            ]);
        }

        return DB::transaction(function () use ($user, $googleId, $googleEmail, $hostedDomain) {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($fresh->deactivated_at !== null) {
                throw ValidationException::withMessages([
                    'google' => 'This account is deactivated.',
                ]);
            }

            if ($fresh->google_id !== null) {
                throw ValidationException::withMessages([
                    'google' => 'This account is already linked to Google.',
                ]);
            }

            if ($this->normalizeEmail((string) $fresh->email) !== $googleEmail) {
                throw ValidationException::withMessages([
                    'google' => 'Google email does not match the provisioned account.',
                ]);
            }

            if (User::query()->where('google_id', $googleId)->whereKeyNot($fresh->id)->exists()) {
                throw ValidationException::withMessages([
                    'google' => 'This Google identity is already linked to another account.',
                ]);
            }

            $fresh->forceFill(['google_id' => $googleId])->save();

            // Actor = the account holder (self-service Google link).
            $this->audit($fresh, 'google_linked', $fresh, [
                'google_id_added' => true,
                'email' => $googleEmail,
                'hosted_domain' => $hostedDomain,
            ]);

            return $fresh->fresh();
        });
    }

    public function awaitingGoogleSignIn(User $user): bool
    {
        return $user->password === null
            && $user->google_id === null
            && $user->deactivated_at === null;
    }

    private function parseRole(mixed $role): UserRole
    {
        if ($role instanceof UserRole) {
            return $role;
        }

        $value = is_string($role) ? Str::lower(trim($role)) : '';

        return UserRole::tryFrom($value)
            ?? throw ValidationException::withMessages([
                'role' => 'Role must be student, teacher, or admin.',
            ]);
    }

    private function assertEmailAvailable(string $normalizedEmail, ?int $ignoreUserId = null): void
    {
        $query = User::query()->whereRaw('LOWER(email) = ?', [$normalizedEmail]);
        if ($ignoreUserId !== null) {
            $query->whereKeyNot($ignoreUserId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'email' => 'An account with this email already exists.',
            ]);
        }
    }

    private function assertNotLastOperationalAdmin(User $user, string $action): void
    {
        $others = User::query()
            ->where('role', UserRole::Admin->value)
            ->whereNull('deactivated_at')
            ->where(function ($q) {
                $q->whereNotNull('password')->orWhereNotNull('google_id');
            })
            ->whereKeyNot($user->id)
            ->exists();

        if (! $others) {
            $message = $action === 'demote'
                ? 'Cannot demote the last operational admin. Add another sign-in-capable admin first.'
                : 'Cannot deactivate the last operational admin. Add another sign-in-capable admin first.';

            throw ValidationException::withMessages([
                'role' => $message,
            ]);
        }
    }

    /**
     * Refuse when this user is the only active, non-deactivated teacher on any class.
     * Never silently alters class memberships.
     */
    private function assertNotSoleActiveClassTeacher(User $user, string $action): void
    {
        $memberships = ClassMembership::query()
            ->with('schoolClass')
            ->where('user_id', $user->id)
            ->where('role', ClassRole::Teacher->value)
            ->whereNull('withdrawn_at')
            ->get();

        $blocked = [];

        foreach ($memberships as $membership) {
            $hasOther = ClassMembership::query()
                ->where('school_class_id', $membership->school_class_id)
                ->where('role', ClassRole::Teacher->value)
                ->whereNull('withdrawn_at')
                ->where('user_id', '!=', $user->id)
                ->whereHas('user', function ($q) {
                    $q->whereNull('deactivated_at')
                        ->whereIn('role', [UserRole::Teacher->value, UserRole::Admin->value]);
                })
                ->exists();

            if (! $hasOther) {
                $blocked[] = $membership->schoolClass?->name ?? ('class #'.$membership->school_class_id);
            }
        }

        if ($blocked !== []) {
            $list = implode(', ', $blocked);
            $verb = $action === 'demote' ? 'demote' : 'deactivate';

            throw ValidationException::withMessages([
                'role' => "Cannot {$verb} this user while they are the only active teacher of: {$list}. Add another teacher first.",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $detail
     */
    private function audit(User $user, string $action, ?User $actor, ?array $detail): void
    {
        // Never persist secrets.
        if (is_array($detail)) {
            unset($detail['password'], $detail['token'], $detail['access_token'], $detail['refresh_token'], $detail['id_token']);
        }

        UserAccountChange::query()->create([
            'user_id' => $user->id,
            'action' => $action,
            'changed_by_user_id' => $actor?->id,
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }

    private function revokeSessions(User $user): void
    {
        $user->forceFill(['remember_token' => null])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }
    }
}
