<?php

namespace App\Http\Controllers\Authoring;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\LessonVersion;
use App\Services\LessonCompiler;
use App\Services\StudentManifest;
use App\Support\PlayerCapabilities;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Preview as Student: real player against a compiled draft that is not a
 * LessonVersion. Never creates a version or an attempt.
 */
class LessonPreviewController extends Controller
{
    public function __construct(
        private readonly LessonCompiler $compiler,
        private readonly StudentManifest $studentManifest,
    ) {
    }

    public function __invoke(Lesson $lesson): View|Response
    {
        Gate::authorize('preview', $lesson);

        $versionsBefore = LessonVersion::query()->where('lesson_id', $lesson->getKey())->count();
        $attemptsBefore = LessonAttempt::query()->where('lesson_id', $lesson->getKey())->count();

        $compiled = $this->compiler->compileManifest(
            $lesson,
            max(1, (int) $lesson->current_version),
        );
        $manifest = $this->studentManifest->redactCompiledManifest($compiled);
        // No grading_token — preview must never invent one.

        $attempt = [
            'id' => null,
            'status' => 'in_progress',
            'read_only' => false,
            'current_page_id' => $manifest['pages'][0]['page_id'] ?? null,
            'active_seconds' => 0,
            'revision' => 0,
            'shuffle_seed' => 'preview',
            'block_states' => [],
            'submissions' => [],
        ];

        $capabilities = PlayerCapabilities::forPreview();

        view()->share('playerAttempt', $attempt);
        view()->share('playerCapabilities', $capabilities);

        $response = response()->view('lesson-player.show', [
            'manifest' => $manifest,
            'attempt' => $attempt,
            'capabilities' => $capabilities,
            'preview' => true,
            'previewBanner' => 'Previewing your last saved draft. Grading, persistence, and completion-gate enforcement are not being tested.',
        ]);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        // Sanity for tests / operators: this action must not have written rows.
        if (LessonVersion::query()->where('lesson_id', $lesson->getKey())->count() !== $versionsBefore
            || LessonAttempt::query()->where('lesson_id', $lesson->getKey())->count() !== $attemptsBefore) {
            abort(500, 'Preview must not create versions or attempts.');
        }

        return $response;
    }
}
