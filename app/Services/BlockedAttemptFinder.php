<?php

namespace App\Services;

use App\Models\User;

/**
 * Thin alias kept for existing call sites. BlockedAttemptService owns the
 * single blocked definition used by every staff surface.
 */
class BlockedAttemptFinder
{
    public function __construct(private readonly BlockedAttemptService $blocked)
    {
    }

    /**
     * @return list<array{
     *     attempt: \App\Models\LessonAttempt,
     *     block_id: string,
     *     block_type: string,
     *     used: int,
     *     allowed: int|null,
     *     remaining: int|null
     * }>
     */
    public function forUser(User $user): array
    {
        return $this->blocked->forUser($user);
    }
}
