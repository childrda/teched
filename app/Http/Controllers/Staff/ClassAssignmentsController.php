<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\LessonAssignment;
use App\Models\SchoolClass;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ClassAssignmentsController extends Controller
{
    public function __invoke(SchoolClass $schoolClass): View
    {
        $this->authorize('view', $schoolClass);

        abort_unless(
            SchoolClass::query()->visibleTo(Auth::user())->whereKey($schoolClass->id)->exists(),
            403
        );

        $assignments = LessonAssignment::query()
            ->visibleTo(Auth::user())
            ->where('school_class_id', $schoolClass->id)
            ->with(['lesson', 'lessonVersion'])
            ->orderByDesc('available_at')
            ->orderByDesc('id')
            ->paginate(30);

        return view('staff.classes.assignments', [
            'schoolClass' => $schoolClass,
            'assignments' => $assignments,
        ]);
    }
}
