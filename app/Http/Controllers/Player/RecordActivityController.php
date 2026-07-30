<?php

namespace App\Http\Controllers\Player;

use App\Enums\AttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\LessonAttempt;
use App\Services\AttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Accumulates active-time deltas. The client never sends a total — addition
 * is atomic so concurrent flushes commute.
 *
 * Delta limits prevent accidental and single-request inflation, but nothing
 * here stops a student sending many individually plausible deltas. Active
 * time is approximate and is not tamper-proof in Phase 3A.
 */
class RecordActivityController extends Controller
{
    public function __construct(private readonly AttemptService $attempts)
    {
    }

    public function __invoke(Request $request, int $attempt): JsonResponse
    {
        $owned = $this->attempts->ownedAttempt(Auth::user(), $attempt);

        if ($owned === null) {
            abort(404);
        }

        if ($owned->status !== AttemptStatus::InProgress) {
            return response()->json([
                'message' => 'This attempt is closed.',
            ], 409);
        }

        $delta = $request->input('active_seconds_delta');

        if (! is_int($delta) && ! (is_string($delta) && ctype_digit($delta))) {
            throw ValidationException::withMessages([
                'active_seconds_delta' => 'Active seconds delta must be a non-negative integer.',
            ]);
        }

        $delta = (int) $delta;

        if ($delta < 0 || $delta > 300) {
            throw ValidationException::withMessages([
                'active_seconds_delta' => 'Active seconds delta must be between 0 and 300.',
            ]);
        }

        if ($delta > 0) {
            LessonAttempt::query()
                ->whereKey($owned->id)
                ->increment('active_seconds', $delta, ['last_activity_at' => now()]);
        } else {
            LessonAttempt::query()
                ->whereKey($owned->id)
                ->update(['last_activity_at' => now()]);
        }

        return response()->json([
            'active_seconds' => (int) $owned->fresh()->active_seconds,
        ]);
    }
}
