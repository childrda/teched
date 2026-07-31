<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\TeacherDashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class ReviewQueueController extends Controller
{
    public function __construct(private readonly TeacherDashboardService $dashboard) {}

    public function __invoke(): View
    {
        $queue = $this->dashboard->reviewQueue(Auth::user(), 100);

        return view('staff.review-queue', [
            'queue' => $queue,
        ]);
    }
}
