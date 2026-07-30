<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\LessonAttempt;
use App\Services\AttemptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Staff-only lesson restart: supersede the old attempt and open a new one.
 *
 * AUTHORIZATION HOLE (intentional until 4A): any teacher/admin may restart
 * any attempt — no roster scope yet. See BlockedAttemptsController.
 */
class RestartAttemptController extends Controller
{
    public function __construct(private readonly AttemptService $attempts)
    {
    }

    public function __invoke(LessonAttempt $attempt): RedirectResponse
    {
        $this->attempts->restartForStaff($attempt, Auth::user());

        return redirect()
            ->route('staff.blocked-attempts')
            ->with('status', 'Lesson restarted for the student.');
    }
}
