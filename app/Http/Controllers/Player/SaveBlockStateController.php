<?php

namespace App\Http\Controllers\Player;

use App\Blocks\BlockTypeRegistry;
use App\Enums\AttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\BlockState;
use App\Models\LessonAttempt;
use App\Services\AttemptService;
use App\Support\DatabaseErrors;
use App\Support\ManifestBlockLookup;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SaveBlockStateController extends Controller
{
    public function __construct(
        private readonly AttemptService $attempts,
        private readonly ManifestBlockLookup $blocks,
        private readonly BlockTypeRegistry $registry,
    ) {
    }

    public function __invoke(Request $request, int $attempt, string $blockId): JsonResponse
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

        $owned->loadMissing('lessonVersion');
        $block = $this->blocks->findBlock($owned->lessonVersion->manifest, $blockId);

        if ($block === null) {
            abort(404);
        }

        $type = $this->registry->get($block['type']);

        if (! $type->holdsStudentState()) {
            throw ValidationException::withMessages([
                'state' => 'This block does not keep student working state.',
            ]);
        }

        $payload = $request->validate([
            'state' => ['required', 'array'],
            'revision' => ['required', 'integer', 'min:0'],
        ]);

        $config = is_array($block['config'] ?? null) ? $block['config'] : [];
        $normalized = $type->validateState($payload['state'], $config);
        $clientRevision = (int) $payload['revision'];

        $existing = BlockState::query()
            ->where('lesson_attempt_id', $owned->id)
            ->where('block_id', $blockId)
            ->first();

        if ($clientRevision === 0) {
            if ($existing !== null) {
                return $this->conflict($existing);
            }

            return $this->createInitialState(
                $owned,
                $blockId,
                (string) $block['type'],
                $normalized
            );
        }

        if ($existing === null) {
            return response()->json([
                'message' => 'The saved state is out of date.',
                'revision' => 0,
                'state' => null,
            ], 409);
        }

        $affected = BlockState::query()
            ->where('lesson_attempt_id', $owned->id)
            ->where('block_id', $blockId)
            ->where('revision', $clientRevision)
            ->update([
                // Query-builder updates bypass casts — encode JSON explicitly.
                'state' => json_encode($normalized, JSON_THROW_ON_ERROR),
                'revision' => $clientRevision + 1,
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return $this->conflict($existing->fresh());
        }

        $this->touchLastActivity($owned);

        return response()->json(['revision' => $clientRevision + 1]);
    }

    /**
     * First save for a block (client revision 0, no row yet). On a unique
     * violation from a concurrent first save, return the same 409 conflict
     * body a stale revision produces — someone else got there first.
     *
     * @param  array<string, mixed>  $normalized
     */
    public function createInitialState(
        LessonAttempt $attempt,
        string $blockId,
        string $blockType,
        array $normalized,
    ): JsonResponse {
        try {
            $row = BlockState::query()->create([
                'lesson_attempt_id' => $attempt->id,
                'block_id' => $blockId,
                'block_type' => $blockType,
                'state' => $normalized,
                'revision' => 1,
            ]);

            $this->touchLastActivity($attempt);

            return response()->json(['revision' => $row->revision]);
        } catch (QueryException $e) {
            if (! DatabaseErrors::isUniqueViolation($e)) {
                throw $e;
            }

            $winner = BlockState::query()
                ->where('lesson_attempt_id', $attempt->id)
                ->where('block_id', $blockId)
                ->first();

            if ($winner === null) {
                throw $e;
            }

            return $this->conflict($winner);
        }
    }

    private function conflict(BlockState $row): JsonResponse
    {
        return response()->json([
            'message' => 'The lesson is open somewhere else. Reload to continue.',
            'revision' => $row->revision,
            'state' => $row->state,
        ], 409);
    }

    /**
     * Coarsen last_activity_at updates: the column answers "when was this
     * student last doing something," which does not need second-level
     * precision. Skipping writes already within 30s keeps the attempt row
     * from becoming a hot write target under chatty autosave.
     */
    private function touchLastActivity(LessonAttempt $attempt): void
    {
        $last = $attempt->last_activity_at;

        if ($last !== null && $last->gt(now()->subSeconds(30))) {
            return;
        }

        $attempt->forceFill(['last_activity_at' => now()])->save();
        $attempt->refresh();
    }
}
