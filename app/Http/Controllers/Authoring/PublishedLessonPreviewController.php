<?php

namespace App\Http\Controllers\Authoring;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\LessonVersion;
use App\Services\StudentManifest;
use App\Support\PlayerCapabilities;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Preview the current published LessonVersion. Any teacher/admin.
 * Uses the immutable version — does not compile the draft. No attempt,
 * no grading token, no persistence.
 */
class PublishedLessonPreviewController extends Controller
{
    public function __construct(private readonly StudentManifest $studentManifest) {}

    public function __invoke(Lesson $lesson): View|Response
    {
        Gate::authorize('previewPublished', $lesson);

        $version = $lesson->currentVersion();
        abort_if($version === null, 404);

        $versionsBefore = LessonVersion::query()->where('lesson_id', $lesson->getKey())->count();
        $attemptsBefore = LessonAttempt::query()->where('lesson_id', $lesson->getKey())->count();

        // Redact the stored published manifest — never compile draft rows and
        // never issue a grading_token (grading requires a real student attempt).
        $manifest = $this->studentManifest->redactCompiledManifest(
            is_array($version->manifest) ? $version->manifest : []
        );

        $attempt = [
            'id' => null,
            'status' => 'in_progress',
            'read_only' => false,
            'current_page_id' => $manifest['pages'][0]['page_id'] ?? null,
            'active_seconds' => 0,
            'revision' => 0,
            'shuffle_seed' => 'preview-published',
            'block_states' => [],
            'submissions' => [],
            'reviews' => [],
        ];

        $capabilities = PlayerCapabilities::forPreview();

        view()->share('playerAttempt', $attempt);
        view()->share('playerCapabilities', $capabilities);

        $response = response()->view('lesson-player.show', [
            'manifest' => $manifest,
            'attempt' => $attempt,
            'capabilities' => $capabilities,
            'preview' => true,
            'previewBanner' => 'Previewing published version '.$version->version
                .'. This is the live student content — not your unpublished draft. '
                .'Grading and persistence are disabled.',
        ]);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        if (LessonVersion::query()->where('lesson_id', $lesson->getKey())->count() !== $versionsBefore
            || LessonAttempt::query()->where('lesson_id', $lesson->getKey())->count() !== $attemptsBefore) {
            abort(500, 'Published preview must not create versions or attempts.');
        }

        return $response;
    }
}
