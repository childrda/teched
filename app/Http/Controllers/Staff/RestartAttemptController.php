<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\LessonAttempt;
use App\Services\AttemptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Staff-only lesson restart. Authorization: LessonAttemptPolicy::intervene.
 */
class RestartAttemptController extends Controller
{
    public function __construct(private readonly AttemptService $attempts)
    {
    }

    public function __invoke(LessonAttempt $attempt): RedirectResponse
    {
        Gate::authorize('intervene', $attempt);

        $fresh = $this->attempts->restartForStaff($attempt, Auth::user());

        return redirect()
            ->route('staff.attempts.show', $fresh)
            ->with('status', __('staff.restart_recorded'));
    }
}
