<?php

namespace App\Filament\Widgets;

use App\Services\TeacherDashboardService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class AssignmentProgressWidget extends Widget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.assignment-progress';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Auth::user();
        $rows = [];

        if ($user !== null && ($user->isTeacher() || $user->isAdmin())) {
            $rows = app(TeacherDashboardService::class)->assignmentProgress($user);
        }

        return ['rows' => $rows];
    }
}
