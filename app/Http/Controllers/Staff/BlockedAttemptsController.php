<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\BlockedAttemptFinder;
use Illuminate\View\View;

/**
 * Minimal staff surface for students stuck on a gradable block.
 *
 * AUTHORIZATION HOLE (intentional until 4A): there is no roster yet, so any
 * teacher or admin who reaches this page can act on any student's attempt.
 * That is acceptable only because 4A introduces classes, memberships, and
 * visibility rules that scope staff actions properly. Do not widen this
 * surface before those rules exist.
 */
class BlockedAttemptsController extends Controller
{
    public function __construct(private readonly BlockedAttemptFinder $finder)
    {
    }

    public function __invoke(): View
    {
        return view('staff.blocked-attempts', [
            'rows' => $this->finder->all(),
        ]);
    }
}
