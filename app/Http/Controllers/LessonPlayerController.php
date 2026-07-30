<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Services\AttemptService;
use App\Services\StudentManifest;
use App\Support\PlayerCapabilities;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * Renders the self-paced student player for a published lesson.
 *
 * Availability comes from StudentManifest; the attempt pin decides which
 * version's redacted manifest is embedded. The player never fetches.
 */
class LessonPlayerController extends Controller
{
    public function __construct(
        private readonly StudentManifest $studentManifest,
        private readonly AttemptService $attempts,
    ) {
    }

    public function __invoke(string $code): View
    {
        $lesson = Lesson::query()->where('code', $code)->first();

        if ($lesson === null || $this->studentManifest->availableVersion($lesson) === null) {
            abort(404);
        }

        $resolved = $this->attempts->resolveForPlayer(Auth::user(), $lesson);

        if ($resolved === null) {
            abort(404);
        }

        /** @var \App\Models\LessonAttempt $attempt */
        $attempt = $resolved['attempt'];
        $attempt->loadMissing('lessonVersion');

        $manifest = $this->studentManifest->forVersion($attempt->lessonVersion);
        $restore = $this->attempts->restorePayload($attempt, $resolved['read_only']);
        $capabilities = $resolved['read_only']
            ? PlayerCapabilities::forReadOnly()
            : PlayerCapabilities::forPlay();

        view()->share('playerAttempt', $restore);
        view()->share('playerCapabilities', $capabilities);

        return view('lesson-player.show', [
            'manifest' => $manifest,
            'attempt' => $restore,
            'capabilities' => $capabilities,
            'preview' => false,
        ]);
    }
}
