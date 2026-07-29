<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Services\StudentManifest;
use Illuminate\Contracts\View\View;

/**
 * Renders the self-paced student player for a published lesson.
 *
 * Availability comes from StudentManifest, the same service the JSON API
 * uses, so the player can never expose a lesson the API would hide. The
 * whole manifest is handed to the view and embedded once; the player never
 * fetches anything.
 *
 * There is no auth yet (Phase 6) and no response persistence (Phase 3).
 */
class LessonPlayerController extends Controller
{
    public function __construct(private readonly StudentManifest $studentManifest)
    {
    }

    public function __invoke(string $code): View
    {
        $lesson = Lesson::query()->where('code', $code)->first();

        $manifest = $lesson === null
            ? null
            : $this->studentManifest->forLesson($lesson);

        if ($manifest === null) {
            abort(404);
        }

        return view('lesson-player.show', ['manifest' => $manifest]);
    }
}
