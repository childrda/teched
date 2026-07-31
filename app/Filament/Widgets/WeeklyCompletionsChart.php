<?php

namespace App\Filament\Widgets;

use App\Services\TeacherDashboardService;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

class WeeklyCompletionsChart extends ChartWidget
{
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Attempts completed per week';

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        $user = Auth::user();
        if ($user !== null && ($user->isTeacher() || $user->isAdmin())) {
            return app(TeacherDashboardService::class)->weeklyCompletions($user, 12)['axis_label'];
        }

        return $this->heading;
    }

    protected function getData(): array
    {
        $user = Auth::user();
        $labels = [];
        $counts = [];
        $axis = 'Attempts completed';

        if ($user !== null && ($user->isTeacher() || $user->isAdmin())) {
            $data = app(TeacherDashboardService::class)->weeklyCompletions($user, 12);
            $labels = $data['labels'];
            $counts = $data['counts'];
            $axis = 'Completed attempts';
        }

        return [
            'datasets' => [
                [
                    'label' => $axis,
                    'data' => $counts,
                    'borderColor' => '#d97706',
                    'backgroundColor' => 'rgba(217, 119, 6, 0.15)',
                    'fill' => true,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
