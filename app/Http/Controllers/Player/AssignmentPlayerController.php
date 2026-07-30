<?php

namespace App\Http\Controllers\Player;

use App\Enums\AttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\LessonAssignment;
use App\Services\AttemptService;
use App\Services\StudentManifest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Plays a lesson through an assignment pin. Authorization and availability
 * are explicit on this named route — not a query string on /lessons/{code}.
 */
class AssignmentPlayerController extends Controller
{
    public function __construct(
        private readonly StudentManifest $studentManifest,
        private readonly AttemptService $attempts,
    ) {
    }

    public function __invoke(LessonAssignment $assignment): View|Response
    {
        $user = Auth::user();
        $assignment->loadMissing(['lesson', 'lessonVersion', 'schoolClass']);

        Gate::authorize('view', $assignment);

        if (! $assignment->isAvailable()) {
            return response()->view('player.assignment-unavailable', [
                'assignment' => $assignment,
            ], 403);
        }

        // Active membership required to start or resume. Withdrawn students
        // may still open completed history read-only.
        if (! Gate::allows('play', $assignment)) {
            $existing = $this->attempts->existingAssignmentAttempt($user, $assignment);

            if ($existing !== null && $existing['attempt']->status === AttemptStatus::Completed) {
                return $this->renderPlayer($existing['attempt'], readOnly: true);
            }

            return response()->view('player.assignment-withdrawn', [
                'assignment' => $assignment,
            ], 403);
        }

        // Staff with class access preview the pin without creating an attempt.
        if (! $user->isStudent()) {
            $manifest = $this->studentManifest->forVersion($assignment->lessonVersion);
            $preview = [
                'id' => null,
                'status' => AttemptStatus::InProgress->value,
                'read_only' => true,
                'current_page_id' => $manifest['pages'][0]['page_id'] ?? null,
                'active_seconds' => 0,
                'revision' => 0,
                'shuffle_seed' => '',
                'block_states' => [],
                'submissions' => [],
            ];
            view()->share('playerAttempt', $preview);

            return view('lesson-player.show', [
                'manifest' => $manifest,
                'attempt' => $preview,
            ]);
        }

        $resolved = $this->attempts->resolveForAssignment($user, $assignment);

        return $this->renderPlayer($resolved['attempt'], $resolved['read_only']);
    }

    private function renderPlayer($attempt, bool $readOnly): View
    {
        $attempt->loadMissing('lessonVersion');
        $manifest = $this->studentManifest->forVersion($attempt->lessonVersion);
        $restore = $this->attempts->restorePayload($attempt, $readOnly);

        view()->share('playerAttempt', $restore);

        return view('lesson-player.show', [
            'manifest' => $manifest,
            'attempt' => $restore,
        ]);
    }
}
