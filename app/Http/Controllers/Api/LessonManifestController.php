<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Services\StudentManifest;
use Illuminate\Http\JsonResponse;

/**
 * Serves the published, redacted manifest students consume. Students never
 * see live authoring rows: only a compiled LessonVersion manifest built by
 * StudentManifest, which the web player shares so the two cannot drift.
 */
class LessonManifestController extends Controller
{
    public function __construct(private readonly StudentManifest $studentManifest)
    {
    }

    public function __invoke(string $code): JsonResponse
    {
        $lesson = Lesson::query()->where('code', $code)->first();

        $manifest = $lesson === null
            ? null
            : $this->studentManifest->forLesson($lesson);

        if ($manifest === null) {
            abort(404);
        }

        return response()->json($manifest);
    }
}
