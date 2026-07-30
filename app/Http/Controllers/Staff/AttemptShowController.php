<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\LessonAttempt;
use App\Services\AttemptDetailPresenter;
use Illuminate\View\View;

class AttemptShowController extends Controller
{
    public function __construct(private readonly AttemptDetailPresenter $presenter)
    {
    }

    public function __invoke(LessonAttempt $attempt): View
    {
        $this->authorize('view', $attempt);

        return view('staff.attempts.show', [
            'detail' => $this->presenter->present($attempt),
        ]);
    }
}
