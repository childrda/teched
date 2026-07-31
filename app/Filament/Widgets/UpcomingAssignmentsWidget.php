<?php

namespace App\Filament\Widgets;

use App\Services\TeacherDashboardService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class UpcomingAssignmentsWidget extends Widget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.upcoming-assignments';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Auth::user();
        $items = [];

        if ($user !== null && ($user->isTeacher() || $user->isAdmin())) {
            $items = app(TeacherDashboardService::class)->upcomingAssignments($user, 10);
        }

        return ['items' => $items];
    }
}
