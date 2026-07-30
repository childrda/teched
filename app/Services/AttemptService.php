<?php

namespace App\Services;

use App\Blocks\BlockTypeRegistry;
use App\Enums\AttemptStatus;
use App\Models\AttemptRetryGrant;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\User;
use App\Support\DatabaseErrors;
use App\Support\ManifestBlockLookup;
use App\Support\StudentGradingResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Resolves the attempt a student should see for a lesson: resume in-progress,
 * show the latest completed read-only, or create a new one pinned to the
 * currently available version.
 *
 * Selection order (existingAttempt):
 * 1. in_progress — active work, not read-only
 * 2. most recent completed — genuine read-only review
 * 3. null — never revive a superseded attempt as the student's view
 */
class AttemptService
{
    public function __construct(
        private readonly StudentManifest $studentManifest,
        private readonly StudentGradingResult $studentResults,
        private readonly RetryPolicy $retries,
        private readonly BlockTypeRegistry $registry,
        private readonly ManifestBlockLookup $blocks,
    ) {
    }

    /**
     * The attempt that already applies for this user and lesson, with no
     * creation side effect. Shared by the player and the manifest API so
     * the two cannot disagree about which pin applies.
     *
     * @return array{attempt: LessonAttempt, read_only: bool}|null
     */
    public function existingAttempt(User $user, Lesson $lesson): ?array
    {
        $inProgress = LessonAttempt::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->where('status', AttemptStatus::InProgress)
            ->first();

        if ($inProgress !== null) {
            return ['attempt' => $inProgress, 'read_only' => false];
        }

        $completed = LessonAttempt::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->where('status', AttemptStatus::Completed)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();

        if ($completed !== null) {
            return ['attempt' => $completed, 'read_only' => true];
        }

        return null;
    }

    /**
     * @return array{attempt: LessonAttempt, read_only: bool}|null
     */
    public function resolveForPlayer(User $user, Lesson $lesson): ?array
    {
        if ($this->studentManifest->availableVersion($lesson) === null) {
            return null;
        }

        return DB::transaction(function () use ($user, $lesson) {
            // Lock any in-progress row so concurrent first visits serialize
            // against the same attempt before falling through to create.
            LessonAttempt::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->where('status', AttemptStatus::InProgress)
                ->lockForUpdate()
                ->first();

            $existing = $this->existingAttempt($user, $lesson);

            if ($existing !== null) {
                return $existing;
            }

            return ['attempt' => $this->createAttempt($user, $lesson), 'read_only' => false];
        });
    }

    /**
     * In-progress attempt for grading, or null when none.
     */
    public function inProgressFor(User $user, Lesson $lesson): ?LessonAttempt
    {
        return LessonAttempt::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->where('status', AttemptStatus::InProgress)
            ->first();
    }

    /**
     * The authenticated user's attempt by id, or null. Never reveals whether
     * another user's attempt exists — callers abort 404 on null.
     */
    public function ownedAttempt(User $user, int|string $attemptId): ?LessonAttempt
    {
        return LessonAttempt::query()
            ->whereKey($attemptId)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Staff-only: supersede the current in_progress (or completed) attempt
     * and open a fresh one on the currently available version. History on
     * the old attempt is untouched.
     */
    public function restartForStaff(LessonAttempt $attempt, User $actor): LessonAttempt
    {
        return DB::transaction(function () use ($attempt, $actor) {
            /** @var LessonAttempt $locked */
            $locked = LessonAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->loadMissing('lesson');

            if ($locked->status === AttemptStatus::InProgress
                || $locked->status === AttemptStatus::Completed) {
                $locked->forceFill([
                    'status' => AttemptStatus::Superseded,
                    'completed_at' => null,
                    'superseded_at' => now(),
                    'superseded_by_user_id' => $actor->id,
                ])->save();
            }

            return $this->createAttempt($locked->user, $locked->lesson);
        });
    }

    /**
     * Staff-only: add attempts for one block. History is untouched.
     */
    public function grantRetries(
        LessonAttempt $attempt,
        string $blockId,
        int $additionalAttempts,
        User $actor,
        ?string $reason = null,
    ): AttemptRetryGrant {
        return AttemptRetryGrant::query()->create([
            'lesson_attempt_id' => $attempt->id,
            'block_id' => $blockId,
            'granted_by_user_id' => $actor->id,
            'additional_attempts' => max(1, $additionalAttempts),
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * Student-facing restore payload embedded beside the manifest.
     *
     * @return array<string, mixed>
     */
    public function restorePayload(LessonAttempt $attempt, bool $readOnly): array
    {
        $attempt->loadMissing(['blockStates', 'blockSubmissions', 'lessonVersion']);

        $byBlock = $attempt->blockSubmissions
            ->filter(fn ($row) => is_array($row->grading_result))
            ->groupBy('block_id');

        $submissions = [];

        foreach ($byBlock as $blockId => $rows) {
            $ordered = $rows->sortBy('attempt_number')->values();
            $first = $ordered->first();
            $latest = $ordered->last();

            $block = $this->blocks->findBlock($attempt->lessonVersion->manifest, (string) $blockId);

            if ($block === null) {
                continue;
            }

            $type = $this->registry->get($block['type']);
            $config = is_array($block['config'] ?? null) ? $block['config'] : [];
            $grading = is_array($block['grading'] ?? null) ? $block['grading'] : null;

            $latestEnvelope = $this->submissionEnvelope($latest, $type, $config, $grading, $attempt);

            $recordFirst = (bool) ($grading['record_first_attempt'] ?? false);
            $firstEnvelope = null;

            if ($recordFirst && $first !== null) {
                $firstEnvelope = $this->submissionEnvelope($first, $type, $config, $grading, $attempt);
            }

            $submissions[$blockId] = [
                'first_result' => $firstEnvelope,
                'latest_result' => $latestEnvelope,
            ];
        }

        return [
            'id' => $attempt->id,
            'status' => $attempt->status->value,
            'read_only' => $readOnly,
            'current_page_id' => $attempt->current_page_id,
            'active_seconds' => $attempt->active_seconds,
            'revision' => $attempt->revision,
            'shuffle_seed' => $attempt->shuffle_seed,
            'block_states' => $attempt->blockStates->map(fn ($row) => [
                'block_id' => $row->block_id,
                'state' => $row->state,
                'revision' => $row->revision,
            ])->values()->all(),
            'submissions' => $submissions,
        ];
    }

    /**
     * @param  array<string, mixed>  $compiledConfig
     * @param  array<string, mixed>|null  $grading
     * @return array{result: array<string, mixed>, attempts: array{used: int, allowed: int|null, remaining: int|null}}
     */
    private function submissionEnvelope(
        $submission,
        $type,
        array $compiledConfig,
        ?array $grading,
        LessonAttempt $attempt,
    ): array {
        $internal = $submission->grading_result;
        $reveal = $this->studentResults->revealFromSubmission(
            $submission,
            $type,
            $compiledConfig,
            $grading
        );

        return $this->studentResults->envelope(
            $this->studentResults->mapResult($internal, $reveal),
            $this->retries->counts($attempt, $submission->block_id, $grading)
        );
    }

    private function createAttempt(User $user, Lesson $lesson): LessonAttempt
    {
        $version = $this->studentManifest->availableVersion($lesson);

        if ($version === null) {
            throw new RuntimeException('Cannot create an attempt without an available version.');
        }

        $manifest = $version->manifest;
        $firstPageId = $manifest['pages'][0]['page_id'] ?? null;

        if (! is_string($firstPageId) || $firstPageId === '') {
            throw new RuntimeException('Published manifest has no first page.');
        }

        $now = now();

        try {
            return LessonAttempt::query()->create([
                'user_id' => $user->id,
                'lesson_id' => $lesson->id,
                'lesson_version_id' => $version->id,
                'current_page_id' => $firstPageId,
                'status' => AttemptStatus::InProgress,
                'started_at' => $now,
                'completed_at' => null,
                'last_activity_at' => $now,
                'active_seconds' => 0,
                'shuffle_seed' => Str::lower((string) Str::ulid()),
                'revision' => 0,
            ]);
        } catch (QueryException $e) {
            // Two tabs raced the insert; the uniqueness mechanism (MySQL
            // generated column, or a future unique constraint) rejected the
            // loser. Re-query the winner — never surface the exception.
            if (! DatabaseErrors::isUniqueViolation($e)) {
                throw $e;
            }

            $winner = LessonAttempt::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->where('status', AttemptStatus::InProgress)
                ->first();

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }
    }
}
