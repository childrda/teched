<?php

namespace App\Filament\Widgets;

use App\Services\TeacherDashboardService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class RecentActivityWidget extends Widget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.recent-activity';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Auth::user();
        $items = [];

        if ($user !== null && ($user->isTeacher() || $user->isAdmin())) {
            $items = app(TeacherDashboardService::class)->recentActivity($user, 15);
        }

        return ['items' => $items];
    }
}
