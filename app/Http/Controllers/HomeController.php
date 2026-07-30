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
            ]);
        }

        $data = $this->dashboard->forStudent($user);

        return view('home', [
            'isStaff' => false,
            'classes' => null,
            'assignments' => $data['assignments'],
            'practice' => $data['practice'],
        ]);
    }
}
