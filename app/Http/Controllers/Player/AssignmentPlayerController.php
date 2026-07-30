<?php

namespace App\Http\Controllers\Player;

use App\Enums\AttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\LessonAssignment;
use App\Services\AttemptService;
use App\Services\LessonAssignmentService;
use App\Services\StudentManifest;
use App\Support\PlayerCapabilities;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Plays a lesson through an assignment pin. Authorization and availability
 * are explicit on this named route — not a query string on /lessons/{code}.
 *
 * Student start vs resume uses LessonAssignmentService's triad so inactive
 * classes and archived assignments refuse new starts while still allowing
 * in-progress work to resume.
 */
class AssignmentPlayerController extends Controller
{
    public function __construct(
        private readonly StudentManifest $studentManifest,
        private readonly AttemptService $attempts,
        private readonly LessonAssignmentService $assignments,
    ) {
    }

    public function __invoke(LessonAssignment $assignment): View|Response
    {
        $user = Auth::user();
        $assignment->loadMissing(['lesson', 'lessonVersion', 'schoolClass']);

        Gate::authorize('view', $assignment);

        if (! $this->assignments->mayViewAssignment($user, $assignment)) {
            abort(403);
        }

        $existing = $this->attempts->existingAssignmentAttempt($user, $assignment);
        $inProgress = $existing !== null && $existing['attempt']->status === AttemptStatus::InProgress
            ? $existing['attempt']
            : null;
        $completed = $existing !== null && $existing['attempt']->status === AttemptStatus::Completed
            ? $existing['attempt']
            : null;

        // Resume first: inactive class / archived assignment still permit it.
        if ($inProgress !== null && $this->assignments->mayResumeAttempt($user, $assignment)) {
            return $this->renderPlayer($inProgress, readOnly: false);
        }

        // Staff with class access preview the pin without creating an attempt.
        if (! $user->isStudent() && Gate::allows('play', $assignment)) {
            return $this->renderStaffPreview($assignment);
        }

        if ($this->assignments->mayStartAttempt($user, $assignment)) {
            $resolved = $this->attempts->resolveForAssignment($user, $assignment);

            return $this->renderPlayer($resolved['attempt'], $resolved['read_only']);
        }

        // Completed history remains viewable when start/resume are refused
        // (withdrawn, inactive class, archived) — mayView already passed.
        if ($completed !== null) {
            return $this->renderPlayer($completed, readOnly: true);
        }

        if (! $assignment->isAvailable()) {
            return response()->view('player.assignment-unavailable', [
                'assignment' => $assignment,
            ], 403);
        }

        return response()->view('player.assignment-withdrawn', [
            'assignment' => $assignment,
        ], 403);
    }

    private function renderStaffPreview(LessonAssignment $assignment): View
    {
        $manifest = $this->studentManifest->forVersion($assignment->lessonVersion);
        $preview = [
            'id' => null,
            'status' => AttemptStatus::InProgress->value,
            'read_only' => false,
            'current_page_id' => $manifest['pages'][0]['page_id'] ?? null,
            'active_seconds' => 0,
            'revision' => 0,
            'shuffle_seed' => '',
            'block_states' => [],
            'submissions' => [],
        ];
        $capabilities = PlayerCapabilities::forPreview();
        view()->share('playerAttempt', $preview);
        view()->share('playerCapabilities', $capabilities);

        return view('lesson-player.show', [
            'manifest' => $manifest,
            'attempt' => $preview,
            'capabilities' => $capabilities,
            'preview' => true,
            'previewBanner' => 'Staff preview of the assignment pin. Grading, persistence, and completion-gate enforcement are not being tested.',
        ]);
    }

    private function renderPlayer($attempt, bool $readOnly): View
    {
        $attempt->loadMissing('lessonVersion');
        $manifest = $this->studentManifest->forVersion($attempt->lessonVersion);
        $restore = $this->attempts->restorePayload($attempt, $readOnly);
        $capabilities = $readOnly
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
