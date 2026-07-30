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
 * has an existing attempt (in progress or completed), returns that attempt's
 * pinned version plus restore data — never a newly published version for a
 * student mid-run or reviewing a finished one. Never creates an attempt.
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

        $resolved = $this->attempts->existingAttempt(Auth::user(), $lesson);

        if ($resolved !== null) {
            $attempt = $resolved['attempt'];
            $attempt->loadMissing('lessonVersion');
            $manifest = $this->studentManifest->forVersion($attempt->lessonVersion);
            $manifest['attempt'] = $this->attempts->restorePayload(
                $attempt,
                $resolved['read_only']
            );

            return response()->json($manifest);
        }

        $manifest = $this->studentManifest->forLesson($lesson);

        if ($manifest === null) {
            abort(404);
        }

        return response()->json($manifest);
    }
}
