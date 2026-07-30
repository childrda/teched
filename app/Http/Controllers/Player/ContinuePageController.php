<?php

namespace App\Http\Controllers\Player;

use App\Enums\AttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\BlockSubmission;
use App\Models\LessonAttempt;
use App\Services\AttemptService;
use App\Services\PageCompletionEvaluator;
use App\Support\ManifestBlockLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContinuePageController extends Controller
{
    public function __construct(
        private readonly AttemptService $attempts,
        private readonly ManifestBlockLookup $lookup,
        private readonly PageCompletionEvaluator $completion,
    ) {
    }

    public function __invoke(Request $request, int $attempt, string $pageId): JsonResponse
    {
        $owned = $this->attempts->ownedAttempt(Auth::user(), $attempt);

        if ($owned === null) {
            abort(404);
        }

        if ($owned->status !== AttemptStatus::InProgress) {
            return response()->json([
                'message' => 'This attempt is closed.',
            ], 409);
        }

        $payload = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
        ]);

        return DB::transaction(function () use ($owned, $pageId, $payload) {
            /** @var LessonAttempt $locked */
            $locked = LessonAttempt::query()
                ->whereKey($owned->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== AttemptStatus::InProgress) {
                return response()->json([
                    'message' => 'This attempt is closed.',
                ], 409);
            }

            $locked->loadMissing('lessonVersion');
            $manifest = $locked->lessonVersion->manifest;
            $page = $this->lookup->findPage($manifest, $pageId);

            if ($page === null) {
                abort(404);
            }

            $evaluation = $this->completion->evaluatePage($locked, $page);

            if (! $evaluation['satisfied']) {
                throw ValidationException::withMessages([
                    'page' => $evaluation['message'] ?? __('player.page_incomplete'),
                ]);
            }

            $this->snapshotResponseBlocks($locked, $page);

            $pages = array_values(array_filter(
                $manifest['pages'] ?? [],
                fn ($candidate) => is_array($candidate)
            ));
            $index = null;

            foreach ($pages as $i => $candidate) {
                if (($candidate['page_id'] ?? null) === $pageId) {
                    $index = $i;
                    break;
                }
            }

            if ($index === null) {
                abort(404);
            }

            $isLast = $index >= count($pages) - 1;
            $nextPageId = $isLast
                ? $pageId
                : (string) ($pages[$index + 1]['page_id'] ?? $pageId);

            $clientRevision = (int) $payload['revision'];
            $now = now();

            $updates = [
                'current_page_id' => $nextPageId,
                'revision' => $clientRevision + 1,
                'last_activity_at' => $now,
            ];

            if ($isLast) {
                $updates['status'] = AttemptStatus::Completed;
                $updates['completed_at'] = $now;
            }

            $affected = LessonAttempt::query()
                ->whereKey($locked->id)
                ->where('revision', $clientRevision)
                ->update($updates);

            if ($affected === 0) {
                $fresh = $locked->fresh();

                return response()->json([
                    'message' => 'The lesson is open somewhere else. Reload to continue.',
                    'revision' => $fresh->revision,
                    'current_page_id' => $fresh->current_page_id,
                ], 409);
            }

            return response()->json([
                'revision' => $clientRevision + 1,
                'current_page_id' => $nextPageId,
                'status' => $isLast
                    ? AttemptStatus::Completed->value
                    : AttemptStatus::InProgress->value,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function snapshotResponseBlocks(LessonAttempt $attempt, array $page): void
    {
        foreach ($page['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;

            if ($type !== 'short_response' && $type !== 'cer') {
                continue;
            }

            $blockId = $block['block_id'] ?? null;

            if (! is_string($blockId)) {
                continue;
            }

            $stateRow = $attempt->blockStates()->where('block_id', $blockId)->first();
            $state = is_array($stateRow?->state) ? $stateRow->state : null;

            if ($state === null) {
                continue;
            }

            $latest = $attempt->blockSubmissions()
                ->where('block_id', $blockId)
                ->orderByDesc('attempt_number')
                ->first();

            if ($latest !== null && $latest->response == $state) {
                continue;
            }

            $nextNumber = (int) $attempt->blockSubmissions()
                ->where('block_id', $blockId)
                ->max('attempt_number') + 1;

            if ($attempt->lesson_version_id !== $attempt->lessonVersion->id) {
                // Defensive: denormalized version must match the pin.
                abort(500);
            }

            BlockSubmission::query()->create([
                'lesson_attempt_id' => $attempt->id,
                'lesson_version_id' => $attempt->lesson_version_id,
                'block_id' => $blockId,
                'block_type' => $type,
                'attempt_number' => max(1, $nextNumber),
                'response' => $state,
                'grading_result' => null,
                'score' => null,
                'max_score' => null,
                'percentage' => null,
                'passed' => null,
                'requires_manual_review' => true,
                'active_seconds_at_submission' => $attempt->active_seconds,
                'submitted_at' => now(),
            ]);
        }
    }
}
