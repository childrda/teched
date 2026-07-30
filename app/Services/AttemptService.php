<?php

namespace App\Services;

use App\Blocks\BlockTypeRegistry;
use App\Enums\AttemptStatus;
use App\Models\AttemptRetryGrant;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\LessonAttempt;
use App\Models\LessonVersion;
use App\Models\User;
use App\Support\DatabaseErrors;
use App\Support\ManifestBlockLookup;
use App\Support\StudentGradingResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Resolves attempts for assigned and unassigned play. Scope is always
 * explicit: never "whichever attempt for this lesson turns up first."
 *
 * Selection order within a scope:
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
     * Unassigned attempts only (lesson_assignment_id IS NULL).
     *
     * @return array{attempt: LessonAttempt, read_only: bool}|null
     */
    public function existingUnassignedAttempt(User $user, Lesson $lesson): ?array
    {
        return $this->pickExisting(
            LessonAttempt::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->whereNull('lesson_assignment_id')
        );
    }

    /**
     * @deprecated Use existingUnassignedAttempt — kept as the unassigned alias
     *             for call sites that mean "direct lesson play."
     *
     * @return array{attempt: LessonAttempt, read_only: bool}|null
     */
    public function existingAttempt(User $user, Lesson $lesson): ?array
    {
        return $this->existingUnassignedAttempt($user, $lesson);
    }

    /**
     * @return array{attempt: LessonAttempt, read_only: bool}|null
     */
    public function existingAssignmentAttempt(User $user, LessonAssignment $assignment): ?array
    {
        return $this->pickExisting(
            LessonAttempt::query()
                ->where('user_id', $user->id)
                ->where('lesson_assignment_id', $assignment->id)
        );
    }

    /**
     * Direct /lessons/{code} play: create or resume an unassigned attempt
     * pinned to the currently available version.
     *
     * @return array{attempt: LessonAttempt, read_only: bool}|null
     */
    public function resolveForPlayer(User $user, Lesson $lesson): ?array
    {
        if ($this->studentManifest->availableVersion($lesson) === null) {
            return null;
        }

        return DB::transaction(function () use ($user, $lesson) {
            LessonAttempt::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->whereNull('lesson_assignment_id')
                ->where('status', AttemptStatus::InProgress)
                ->lockForUpdate()
                ->first();

            $existing = $this->existingUnassignedAttempt($user, $lesson);

            if ($existing !== null) {
                return $existing;
            }

            return [
                'attempt' => $this->createUnassignedAttempt($user, $lesson),
                'read_only' => false,
            ];
        });
    }

    /**
     * Assignment play: pin to the assignment's version. Caller must already
     * have authorized play and checked available_at / active membership.
     *
     * @return array{attempt: LessonAttempt, read_only: bool}
     */
    public function resolveForAssignment(User $user, LessonAssignment $assignment): array
    {
        return DB::transaction(function () use ($user, $assignment) {
            LessonAttempt::query()
                ->where('user_id', $user->id)
                ->where('lesson_assignment_id', $assignment->id)
                ->where('status', AttemptStatus::InProgress)
                ->lockForUpdate()
                ->first();

            $existing = $this->existingAssignmentAttempt($user, $assignment);

            if ($existing !== null) {
                return $existing;
            }

            return [
                'attempt' => $this->createAssignedAttempt($user, $assignment),
                'read_only' => false,
            ];
        });
    }

    /**
     * In-progress unassigned attempt for a lesson, or null.
     */
    public function inProgressUnassigned(User $user, Lesson $lesson): ?LessonAttempt
    {
        return LessonAttempt::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->whereNull('lesson_assignment_id')
            ->where('status', AttemptStatus::InProgress)
            ->first();
    }

    /**
     * @deprecated Prefer inProgressMatchingVersion for grading — lesson-only
     *             lookup is ambiguous when a student has concurrent assigned attempts.
     */
    public function inProgressFor(User $user, Lesson $lesson): ?LessonAttempt
    {
        return LessonAttempt::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->where('status', AttemptStatus::InProgress)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Grading endpoint resolution: match the version_token's pin so concurrent
     * assignment attempts for the same lesson do not cross wires.
     */
    public function inProgressMatchingVersion(User $user, Lesson $lesson, int $versionId): ?LessonAttempt
    {
        return LessonAttempt::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->where('lesson_version_id', $versionId)
            ->where('status', AttemptStatus::InProgress)
            ->orderByDesc('id')
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
     * Staff: supersede and open a fresh attempt in the same scope (assignment
     * pin preserved, or unassigned available-version pin).
     */
    public function restartForStaff(LessonAttempt $attempt, User $actor): LessonAttempt
    {
        return DB::transaction(function () use ($attempt, $actor) {
            /** @var LessonAttempt $locked */
            $locked = LessonAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->loadMissing(['lesson', 'assignment', 'user']);

            if ($locked->status === AttemptStatus::InProgress
                || $locked->status === AttemptStatus::Completed) {
                $locked->forceFill([
                    'status' => AttemptStatus::Superseded,
                    'completed_at' => null,
                    'superseded_at' => now(),
                    'superseded_by_user_id' => $actor->id,
                ])->save();
            }

            if ($locked->lesson_assignment_id !== null && $locked->assignment !== null) {
                return $this->createAssignedAttempt($locked->user, $locked->assignment);
            }

            return $this->createUnassignedAttempt($locked->user, $locked->lesson);
        });
    }

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
     * @param  \Illuminate\Database\Eloquent\Builder<LessonAttempt>  $base
     * @return array{attempt: LessonAttempt, read_only: bool}|null
     */
    private function pickExisting($base): ?array
    {
        $inProgress = (clone $base)
            ->where('status', AttemptStatus::InProgress)
            ->first();

        if ($inProgress !== null) {
            return ['attempt' => $inProgress, 'read_only' => false];
        }

        $completed = (clone $base)
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

    private function createUnassignedAttempt(User $user, Lesson $lesson): LessonAttempt
    {
        $version = $this->studentManifest->availableVersion($lesson);

        if ($version === null) {
            throw new RuntimeException('Cannot create an attempt without an available version.');
        }

        return $this->insertAttempt($user, $lesson, $version, null);
    }

    private function createAssignedAttempt(User $user, LessonAssignment $assignment): LessonAttempt
    {
        $assignment->loadMissing(['lesson', 'lessonVersion']);

        $version = $assignment->lessonVersion;

        if ($version === null) {
            throw new RuntimeException('Assignment is missing its pinned lesson version.');
        }

        return $this->insertAttempt($user, $assignment->lesson, $version, $assignment->id);
    }

    private function insertAttempt(
        User $user,
        Lesson $lesson,
        LessonVersion $version,
        ?int $assignmentId,
    ): LessonAttempt {
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
                'lesson_assignment_id' => $assignmentId,
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
            // Two tabs raced the insert. Re-query the winner in the *same*
            // scope that was inserted — assignment id or unassigned lesson.
            if (! DatabaseErrors::isUniqueViolation($e)) {
                throw $e;
            }

            $winnerQuery = LessonAttempt::query()
                ->where('user_id', $user->id)
                ->where('status', AttemptStatus::InProgress);

            if ($assignmentId === null) {
                $winnerQuery
                    ->where('lesson_id', $lesson->id)
                    ->whereNull('lesson_assignment_id');
            } else {
                $winnerQuery->where('lesson_assignment_id', $assignmentId);
            }

            $winner = $winnerQuery->first();

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }
    }
}
