<?php

namespace App\Http\Controllers;

use App\Services\UserPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Students update only their own dashboard preferences.
 */
class StudentPreferencesController extends Controller
{
    public function __construct(private readonly UserPreferenceService $preferences) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $patch = ['student_dashboard' => []];

        if ($request->has('show_completed_assignments')) {
            $patch['student_dashboard']['show_completed_assignments'] = $request->boolean('show_completed_assignments');
        }

        if ($request->has('show_completed_practice')) {
            $patch['student_dashboard']['show_completed_practice'] = $request->boolean('show_completed_practice');
        }

        if ($patch['student_dashboard'] === []) {
            return redirect()->route('home');
        }

        $this->preferences->updateOwn($request->user(), $patch);

        return redirect()->route('home');
    }
}
