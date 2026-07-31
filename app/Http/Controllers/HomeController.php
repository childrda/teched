<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Services\StudentDashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Student home: class assignments and practice. Staff landing: classes.
 */
class HomeController extends Controller
{
    public function __construct(private readonly StudentDashboardService $dashboard)
    {
    }

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $isStaff = $user !== null && ($user->isTeacher() || $user->isAdmin());

        if ($isStaff) {
            $classes = SchoolClass::query()
                ->visibleTo($user)
                ->withCount('assignments')
                ->orderBy('name')
                ->paginate(30);

            return view('home', [
                'isStaff' => true,
                'classes' => $classes,
                'assignments' => [],
                'practice' => [],
                'show_completed_assignments' => false,
                'show_completed_practice' => false,
                'completed_assignment_count' => 0,
                'completed_practice_count' => 0,
            ]);
        }

        $data = $this->dashboard->forStudent($user);

        return view('home', [
            'isStaff' => false,
            'classes' => null,
            'assignments' => $data['assignments'],
            'practice' => $data['practice'],
            'show_completed_assignments' => $data['show_completed_assignments'],
            'show_completed_practice' => $data['show_completed_practice'],
            'completed_assignment_count' => $data['completed_assignment_count'],
            'completed_practice_count' => $data['completed_practice_count'],
        ]);
    }
}
