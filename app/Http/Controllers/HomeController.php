<?php

namespace App\Http\Controllers;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Services\AttemptService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Deliberately plain student landing — published lessons with Start/Resume.
 * Not assignment-aware; 4A/4B replace this with the real dashboard. Keep it
 * minimal so nobody mistakes it for finished product.
 */
class HomeController extends Controller
{
    public function __construct(private readonly AttemptService $attempts)
    {
    }

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        $lessons = Lesson::query()
            ->where('status', LessonStatus::Published)
            ->whereNotNull('current_version')
            ->orderBy('code')
            ->get();

        $rows = $lessons->map(function (Lesson $lesson) use ($user) {
            $resolved = $user !== null
                ? $this->attempts->existingAttempt($user, $lesson)
                : null;

            return [
                'lesson' => $lesson,
                'attempt' => $resolved['attempt'] ?? null,
                'read_only' => $resolved['read_only'] ?? false,
            ];
        });

        return view('home', [
            'rows' => $rows,
            'isStaff' => $user !== null && ($user->isTeacher() || $user->isAdmin()),
        ]);
    }
}
