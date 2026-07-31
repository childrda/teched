<?php

namespace App\Filament\Widgets;

use App\Services\TeacherDashboardService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class NeedsAttentionWidget extends Widget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.needs-attention';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Auth::user();
        $items = [];
        $total = 0;

        if ($user !== null && ($user->isTeacher() || $user->isAdmin())) {
            $dashboard = app(TeacherDashboardService::class);
            $items = $dashboard->needsAttention($user, 10);
            $total = $dashboard->summary($user)['students_needing_attention'];
        }

        return [
            'items' => $items,
            'total' => $total,
            'viewAllUrl' => route('staff.blocked-attempts'),
        ];
    }
}
