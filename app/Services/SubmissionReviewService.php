<?php

namespace App\Services;

use App\Models\BlockSubmission;
use App\Models\BlockSubmissionReview;
use App\Models\LessonAttempt;
use App\Models\User;
use App\Support\ManualReviewScore;
use App\Support\ManifestBlockLookup;
use App\Support\StudentManualReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Owns manual review of short_response and cer submissions.
 *
 * Controllers and Blade must not derive points_possible, resolve the pinned
 * manifest, or write review rows themselves.
 */
class SubmissionReviewService
{
    public const MODE_REVIEW_ONLY = 'review_only';

    public const MODE_SCORED = 'scored';

    public function __construct(private readonly ManifestBlockLookup $blocks) {}

    /**
     * @return array<string, mixed> teacher-facing latest review representation
     */
    public function review(
        LessonAttempt $attempt,
        BlockSubmission $submission,
        User $actor,
        string $mode,
        ?int $pointsAwarded,
        ?string $comment,
        ?string $privateNote,
    ): array {
        Gate::forUser($actor)->authorize('intervene', $attempt);

        if ((int) $submission->lesson_attempt_id !== (int) $attempt->getKey()) {
            throw ValidationException::withMessages([
                'submission' => 'That submission does not belong to this attempt.',
            ]);
        }

        return DB::transaction(function () use (
            $attempt,
            $submission,
            $actor,
            $mode,
            $pointsAwarded,
            $comment,
            $privateNote,
        ) {
            /** @var LessonAttempt $lockedAttempt */
            $lockedAttempt = LessonAttempt::query()
                ->lockForUpdate()
                ->findOrFail($attempt->getKey());

            /** @var BlockSubmission $locked */
            $locked = BlockSubmission::query()
                ->whereKey($submission->getKey())
                ->where('lesson_attempt_id', $lockedAttempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedAttempt->loadMissing('lessonVersion');
            $block = $this->resolveReviewableBlock($lockedAttempt, $locked);
            $possible = $this->pointsPossible((string) $block['type'], is_array($block['config'] ?? null) ? $block['config'] : []);

            [$awarded, $storedPossible] = $this->validateMode($mode, $pointsAwarded, $possible);
            $normalizedComment = $this->normalizeText($comment, 'comment');
            $normalizedPrivate = $this->normalizeText($privateNote, 'private_note');

            BlockSubmissionReview::query()->create([
                'block_submission_id' => $locked->getKey(),
                'reviewed_by_user_id' => $actor->getKey(),
                'points_awarded' => $awarded,
                'points_possible' => $storedPossible,
                'comment' => $normalizedComment,
                'private_note' => $normalizedPrivate,
                'created_at' => now(),
            ]);

            $locked->load('latestReview.reviewedBy');

            return $this->teacherPayload($locked->latestReview);
        });
    }

    /**
     * Student-safe review for a block, derived from the latest submission only.
     * Never carries an older submission's review forward.
     *
     * @return array{reviewed: bool, score: array{awarded: int, possible: int, percentage: int}|null, comment: string|null}|null
     */
    public function studentSafeForBlock(LessonAttempt $attempt, string $blockId): ?array
    {
        $attempt->loadMissing(['blockSubmissions.latestReview']);

        $latest = $attempt->blockSubmissions
            ->where('block_id', $blockId)
            ->sortByDesc(fn (BlockSubmission $row) => [$row->attempt_number, $row->id])
            ->first();

        if ($latest === null || ! $latest->requires_manual_review) {
            return null;
        }

        return $this->studentSafeFromSubmission($latest);
    }

    /**
     * @return array{reviewed: bool, score: array{awarded: int, possible: int, percentage: int}|null, comment: string|null}
     */
    public function studentSafeFromSubmission(BlockSubmission $submission): array
    {
        $submission->loadMissing('latestReview');
        $review = $submission->latestReview;

        if ($review === null) {
            return StudentManualReview::map(false, null, null);
        }

        return $this->mapStudentSafePrimitives($review->points_awarded, $review->points_possible, $review->comment);
    }

    /**
     * Builds the student mapper input from primitives — never from a review model
     * at the call site that serves students.
     *
     * @return array{reviewed: bool, score: array{awarded: int, possible: int, percentage: int}|null, comment: string|null}
     */
    public function mapStudentSafePrimitives(?int $awarded, ?int $possible, ?string $comment): array
    {
        $score = null;

        if ($awarded !== null && $possible !== null) {
            $score = ManualReviewScore::fromAwardedAndPossible($awarded, $possible)->toArray();
        }

        return StudentManualReview::map(true, $score, $comment);
    }

    /**
     * @return array<string, mixed>
     */
    public function teacherPayload(?BlockSubmissionReview $review): array
    {
        if ($review === null) {
            return [
                'reviewed' => false,
                'score' => null,
                'comment' => null,
                'private_note' => null,
                'reviewed_by' => null,
                'created_at' => null,
                'review_count' => 0,
            ];
        }

        $review->loadMissing('reviewedBy');

        $score = null;
        if ($review->points_awarded !== null && $review->points_possible !== null) {
            $score = ManualReviewScore::fromAwardedAndPossible(
                (int) $review->points_awarded,
                (int) $review->points_possible,
            )->toArray();
        }

        return [
            'reviewed' => true,
            'score' => $score,
            'comment' => $review->comment,
            'private_note' => $review->private_note,
            'reviewed_by' => $review->reviewedBy?->name,
            'created_at' => $review->created_at,
            'review_count' => 1,
        ];
    }

    /**
     * Teacher history for one submission (newest first).
     *
     * @return list<array<string, mixed>>
     */
    public function teacherHistory(BlockSubmission $submission): array
    {
        $submission->loadMissing(['reviews.reviewedBy']);

        return $submission->reviews
            ->sortByDesc(fn (BlockSubmissionReview $row) => [
                $row->created_at?->timestamp ?? 0,
                $row->id,
            ])
            ->values()
            ->map(function (BlockSubmissionReview $review) {
                $payload = $this->teacherPayload($review);
                $payload['id'] = $review->id;

                return $payload;
            })
            ->all();
    }

    public function pointsPossible(string $blockType, array $config): int
    {
        return match ($blockType) {
            'short_response' => 1,
            'cer' => count(is_array($config['fields'] ?? null) ? $config['fields'] : []),
            default => 0,
        };
    }

    public function submissionNeedsReview(BlockSubmission $submission): bool
    {
        if (! $submission->requires_manual_review) {
            return false;
        }

        $submission->loadMissing('latestReview');

        return $submission->latestReview === null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveReviewableBlock(LessonAttempt $attempt, BlockSubmission $submission): array
    {
        $type = (string) $submission->block_type;

        if (! in_array($type, ['short_response', 'cer'], true)) {
            throw ValidationException::withMessages([
                'submission' => 'Only short response and CER submissions can be reviewed.',
            ]);
        }

        if ($submission->grading_result !== null) {
            throw ValidationException::withMessages([
                'submission' => 'Auto-graded submissions cannot be reviewed this way.',
            ]);
        }

        if (! $submission->requires_manual_review) {
            throw ValidationException::withMessages([
                'submission' => 'This submission does not require manual review.',
            ]);
        }

        $manifest = is_array($attempt->lessonVersion?->manifest)
            ? $attempt->lessonVersion->manifest
            : null;
        $block = $this->blocks->findBlock($manifest, (string) $submission->block_id);

        if ($block === null) {
            throw ValidationException::withMessages([
                'submission' => 'That block is not present in the attempt\'s pinned version.',
            ]);
        }

        if (($block['type'] ?? null) !== $type) {
            throw ValidationException::withMessages([
                'submission' => 'The pinned block type does not match this submission.',
            ]);
        }

        return $block;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function validateMode(string $mode, ?int $pointsAwarded, int $possible): array
    {
        if ($mode === self::MODE_REVIEW_ONLY) {
            if ($pointsAwarded !== null) {
                throw ValidationException::withMessages([
                    'points_awarded' => 'Review-only mode cannot include a score.',
                ]);
            }

            return [null, null];
        }

        if ($mode !== self::MODE_SCORED) {
            throw ValidationException::withMessages([
                'mode' => 'Mode must be review_only or scored.',
            ]);
        }

        if ($possible < 1) {
            throw ValidationException::withMessages([
                'points_awarded' => 'This block has no scorable fields in the pinned version; mark it reviewed without a score.',
            ]);
        }

        if ($pointsAwarded === null) {
            throw ValidationException::withMessages([
                'points_awarded' => 'A score is required in scored mode.',
            ]);
        }

        if ($pointsAwarded < 0 || $pointsAwarded > $possible) {
            throw ValidationException::withMessages([
                'points_awarded' => "Score must be between 0 and {$possible}.",
            ]);
        }

        return [$pointsAwarded, $possible];
    }

    private function normalizeText(?string $value, string $attribute): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        $max = (int) config('submission-reviews.comment_max', 5000);

        if (mb_strlen($normalized) > $max) {
            throw ValidationException::withMessages([
                $attribute => "Text may not exceed {$max} characters.",
            ]);
        }

        return $normalized === '' ? null : $normalized;
    }
}
