<?php

namespace App\Filament\Widgets;

use App\Services\TeacherDashboardService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class TeacherSummaryStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $user = Auth::user();
        if ($user === null || (! $user->isTeacher() && ! $user->isAdmin())) {
            return [];
        }

        $summary = app(TeacherDashboardService::class)->summary($user);

        return [
            Stat::make('Active classes', (string) $summary['active_classes'])
                ->url(route('staff.classes.index')),
            Stat::make('Active assignments', (string) $summary['active_assignments'])
                ->url(route('staff.classes.index')),
            Stat::make('Students in progress', (string) $summary['students_in_progress']),
            Stat::make('Needs attention', (string) $summary['students_needing_attention'])
                ->description($summary['awaiting_review_total'].' awaiting review')
                ->url(route('staff.blocked-attempts')),
        ];
    }
}
