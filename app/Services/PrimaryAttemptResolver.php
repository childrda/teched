<?php

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Models\LessonAttempt;
use Illuminate\Support\Collection;

/**
 * Single definition of the "primary" attempt for an assignment (or practice
 * lesson). Restart preserves history, so completed / superseded / current can
 * coexist — every dashboard metric must resolve through here.
 *
 * Prefer current in_progress; else newest completed; else newest superseded;
 * else not started (null).
 */
class PrimaryAttemptResolver
{
    /**
     * @param  Collection<int, LessonAttempt>|iterable<LessonAttempt>  $attempts
     */
    public function resolve(iterable $attempts): ?LessonAttempt
    {
        $collection = $attempts instanceof Collection
            ? $attempts
            : collect($attempts);

        $inProgress = $collection->first(
            fn (LessonAttempt $attempt) => $attempt->status === AttemptStatus::InProgress
        );

        if ($inProgress !== null) {
            return $inProgress;
        }

        $completed = $collection
            ->filter(fn (LessonAttempt $attempt) => $attempt->status === AttemptStatus::Completed)
            ->sortByDesc(fn (LessonAttempt $attempt) => [
                $attempt->completed_at?->timestamp ?? 0,
                $attempt->id,
            ])
            ->first();

        if ($completed !== null) {
            return $completed;
        }

        return $collection
            ->filter(fn (LessonAttempt $attempt) => $attempt->status === AttemptStatus::Superseded)
            ->sortByDesc(fn (LessonAttempt $attempt) => [
                $attempt->superseded_at?->timestamp ?? 0,
                $attempt->id,
            ])
            ->first();
    }
}
