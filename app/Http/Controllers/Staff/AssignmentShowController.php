<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\LessonAssignment;
use App\Services\AssignmentProgressService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AssignmentShowController extends Controller
{
    public function __construct(private readonly AssignmentProgressService $progress)
    {
    }

    public function __invoke(LessonAssignment $assignment): View
    {
        $this->authorize('view', $assignment);

        abort_unless(
            LessonAssignment::query()->visibleTo(Auth::user())->whereKey($assignment->id)->exists(),
            403
        );

        $data = $this->progress->forAssignment($assignment);

        return view('staff.assignments.show', $data);
    }
}
