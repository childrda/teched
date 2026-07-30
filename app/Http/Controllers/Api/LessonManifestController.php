<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Services\AttemptService;
use App\Services\StudentManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Serves the published, redacted manifest students consume. When the student
 * has an in_progress attempt, returns that attempt's pinned version plus
 * restore data — never a newly published version mid-attempt.
 */
class LessonManifestController extends Controller
{
    public function __construct(
        private readonly StudentManifest $studentManifest,
        private readonly AttemptService $attempts,
    ) {
    }

    public function __invoke(string $code): JsonResponse
    {
        $lesson = Lesson::query()->where('code', $code)->first();

        if ($lesson === null || $this->studentManifest->availableVersion($lesson) === null) {
            abort(404);
        }

        $inProgress = $this->attempts->inProgressFor(Auth::user(), $lesson);

        if ($inProgress !== null) {
            $inProgress->loadMissing('lessonVersion');
            $manifest = $this->studentManifest->forVersion($inProgress->lessonVersion);
            $manifest['attempt'] = $this->attempts->restorePayload($inProgress, readOnly: false);

            return response()->json($manifest);
        }

        $manifest = $this->studentManifest->forLesson($lesson);

        if ($manifest === null) {
            abort(404);
        }

        return response()->json($manifest);
    }
}
