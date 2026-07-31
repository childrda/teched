<?php

namespace App\Filament\Widgets;

use App\Services\TeacherDashboardService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class ReviewQueueWidget extends Widget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.review-queue';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Auth::user();
        $queue = ['total' => 0, 'by_type' => [], 'items' => []];

        if ($user !== null && ($user->isTeacher() || $user->isAdmin())) {
            $queue = app(TeacherDashboardService::class)->reviewQueue($user, 10);
        }

        return [
            'queue' => $queue,
            'viewAllUrl' => route('staff.review-queue'),
        ];
    }
}
