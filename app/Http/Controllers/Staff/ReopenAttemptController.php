<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\LessonAttempt;
use App\Services\AttemptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ReopenAttemptController extends Controller
{
    public function __construct(private readonly AttemptService $attempts)
    {
    }

    public function __invoke(Request $request, LessonAttempt $attempt): RedirectResponse
    {
        Gate::authorize('intervene', $attempt);

        $payload = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->attempts->reopenForStaff(
            $attempt,
            Auth::user(),
            $payload['reason'] ?? null,
        );

        return redirect()
            ->route('staff.attempts.show', $attempt)
            ->with('status', __('staff.reopen_recorded'));
    }
}
