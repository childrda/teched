<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\LessonAttempt;
use Illuminate\View\View;

class ConfirmReopenController extends Controller
{
    public function __invoke(LessonAttempt $attempt): View
    {
        $this->authorize('intervene', $attempt);

        $attempt->loadMissing(['user', 'lesson']);

        return view('staff.attempts.confirm-reopen', [
            'attempt' => $attempt,
        ]);
    }
}
