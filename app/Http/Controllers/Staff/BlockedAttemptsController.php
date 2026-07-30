<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\BlockedAttemptFinder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Minimal staff surface for students stuck on a gradable block.
 * Rows come from LessonAttempt::visibleTo() so unauthorized attempts never
 * leave SQL — a teacher must not learn that another teacher's student is stuck.
 */
class BlockedAttemptsController extends Controller
{
    public function __construct(private readonly BlockedAttemptFinder $finder)
    {
    }

    public function __invoke(): View
    {
        return view('staff.blocked-attempts', [
            'rows' => $this->finder->forUser(Auth::user()),
        ]);
    }
}
