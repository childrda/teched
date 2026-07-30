<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\LessonAttempt;
use App\Services\AttemptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Adds attempts for one block on one existing lesson attempt.
 *
 * AUTHORIZATION HOLE (intentional until 4A): any teacher/admin may grant
 * retries on any attempt — no roster scope yet. See BlockedAttemptsController.
 */
class GrantRetriesController extends Controller
{
    public function __construct(private readonly AttemptService $attempts)
    {
    }

    public function __invoke(Request $request, LessonAttempt $attempt): RedirectResponse
    {
        $payload = $request->validate([
            'block_id' => ['required', 'string', 'max:26'],
            'additional_attempts' => ['required', 'integer', 'min:1', 'max:20'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->attempts->grantRetries(
            $attempt,
            $payload['block_id'],
            (int) $payload['additional_attempts'],
            Auth::user(),
            $payload['reason'] ?? null,
        );

        return redirect()
            ->route('staff.blocked-attempts')
            ->with('status', 'Retry grant recorded.');
    }
}
