<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AssignmentProgressWidget;
use App\Filament\Widgets\NeedsAttentionWidget;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\ReviewQueueWidget;
use App\Filament\Widgets\TeacherSummaryStats;
use App\Filament\Widgets\UpcomingAssignmentsWidget;
use App\Filament\Widgets\WeeklyCompletionsChart;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            TeacherSummaryStats::class,
            ReviewQueueWidget::class,
            NeedsAttentionWidget::class,
            UpcomingAssignmentsWidget::class,
            RecentActivityWidget::class,
            AssignmentProgressWidget::class,
            WeeklyCompletionsChart::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
