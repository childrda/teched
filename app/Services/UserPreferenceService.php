<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Narrow writer for users.preferences. Only the authenticated account may
 * update its own preferences; unknown keys are rejected.
 */
class UserPreferenceService
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'student_dashboard.show_completed_assignments',
        'student_dashboard.show_completed_practice',
    ];

    public function get(User $user, string $key, mixed $default = null): mixed
    {
        $this->assertKnownKey($key);

        $preferences = is_array($user->preferences) ? $user->preferences : [];

        return data_get($preferences, $key, $default);
    }

    public function showCompletedAssignments(User $user): bool
    {
        return (bool) $this->get($user, 'student_dashboard.show_completed_assignments', false);
    }

    public function showCompletedPractice(User $user): bool
    {
        return (bool) $this->get($user, 'student_dashboard.show_completed_practice', false);
    }

    /**
     * @param  array<string, mixed>  $patch  Dot-keys or nested student_dashboard array
     */
    public function updateOwn(User $actor, array $patch): User
    {
        $normalized = $this->normalizePatch($patch);

        foreach (array_keys($normalized) as $key) {
            $this->assertKnownKey($key);
        }

        $preferences = is_array($actor->preferences) ? $actor->preferences : [];

        foreach ($normalized as $key => $value) {
            data_set($preferences, $key, $value);
        }

        // Explicit forceFill — preferences is not mass-assignable.
        $actor->forceFill(['preferences' => $preferences])->save();

        return $actor->fresh();
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function normalizePatch(array $patch): array
    {
        $out = [];

        foreach ($patch as $key => $value) {
            if ($key === 'student_dashboard' && is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    $out['student_dashboard.'.$subKey] = $subValue;
                }

                continue;
            }

            $out[(string) $key] = $value;
        }

        return $out;
    }

    private function assertKnownKey(string $key): void
    {
        if (! in_array($key, self::ALLOWED_KEYS, true)) {
            throw ValidationException::withMessages([
                'preferences' => "Unknown preference key: {$key}",
            ]);
        }
    }
}
