<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\LessonAttempt;
use App\Services\AttemptService;
use App\Support\ManifestBlockLookup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Adds attempts for one block on one existing lesson attempt.
 * Authorization: LessonAttemptPolicy::intervene — exact assignment class.
 */
class GrantRetriesController extends Controller
{
    public function __construct(
        private readonly AttemptService $attempts,
        private readonly ManifestBlockLookup $blocks,
    ) {
    }

    public function __invoke(Request $request, LessonAttempt $attempt): RedirectResponse
    {
        Gate::authorize('intervene', $attempt);

        $payload = $request->validate([
            'block_id' => ['required', 'string', 'max:26'],
            'additional_attempts' => ['required', 'integer', 'min:1', 'max:20'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->attempts->grantRetries(
            $attempt,
            $payload['block_id'],
            (int) $payload['additional_attempts'],
            Auth::user(),
            $payload['reason'] ?? null,
        );

        return redirect()
            ->route('staff.attempts.show', $attempt)
            ->with('status', __('staff.grant_recorded', [
                'block' => $this->blockLabel($attempt, $payload['block_id']),
            ]));
    }

    /**
     * The same block-type label the grant button carries, so a teacher who
     * grants the wrong block sees it in the confirmation rather than from the
     * student still being stuck. Falls back to the id if the pinned manifest
     * no longer carries the block.
     */
    private function blockLabel(LessonAttempt $attempt, string $blockId): string
    {
        $attempt->loadMissing('lessonVersion');

        $block = $this->blocks->findBlock($attempt->lessonVersion?->manifest, $blockId);
        $type = $block['type'] ?? null;

        return is_string($type) && $type !== '' ? $type : $blockId;
    }
}
