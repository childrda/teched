<?php

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\User;
use App\Support\StudentGradingResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Resolves the attempt a student should see for a lesson: resume in-progress,
 * show the latest completed read-only, or create a new one pinned to the
 * currently available version.
 */
class AttemptService
{
    public function __construct(
        private readonly StudentManifest $studentManifest,
        private readonly StudentGradingResult $studentResults,
    ) {
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
            $inProgress = LessonAttempt::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->where('status', AttemptStatus::InProgress)
                ->lockForUpdate()
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

            return ['attempt' => $this->createAttempt($user, $lesson), 'read_only' => false];
        });
    }

    /**
     * In-progress attempt for API/grading, or null when none (API then falls
     * back to the currently available version without restore data).
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
     * Student-facing restore payload embedded beside the manifest.
     *
     * @return array<string, mixed>
     */
    public function restorePayload(LessonAttempt $attempt, bool $readOnly): array
    {
        $attempt->loadMissing(['blockStates', 'blockSubmissions']);

        $latestByBlock = $attempt->blockSubmissions
            ->sortByDesc('attempt_number')
            ->unique('block_id');

        $submissions = [];

        foreach ($latestByBlock as $submission) {
            // Only gradable summaries reach the student — five-key ceiling,
            // never details[]. Response-only snapshots stay server-side.
            if (! is_array($submission->grading_result)) {
                continue;
            }

            $public = $this->studentResults->map($submission->grading_result);

            $submissions[$submission->block_id] = array_merge($public, [
                'attempt_number' => $submission->attempt_number,
            ]);
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
            if (! $this->isUniqueViolation($e)) {
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

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        // 23000 = integrity constraint violation (MySQL/SQLite/Postgres).
        return $sqlState === '23000' || $e->getCode() === '23000';
    }
}
