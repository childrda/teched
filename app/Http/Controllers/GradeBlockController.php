<?php

namespace App\Http\Controllers;

use App\Blocks\BlockTypeRegistry;
use App\Enums\AttemptStatus;
use App\Exceptions\InvalidGradingToken;
use App\Models\BlockSubmission;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Services\AttemptService;
use App\Services\GradingToken;
use App\Services\RevealEvaluator;
use App\Services\RetryPolicy;
use App\Services\StudentManifest;
use App\Support\ManifestBlockLookup;
use App\Support\QuizResponseValidator;
use App\Support\StudentGradingResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Grades one auto-gradable block against the student's in_progress attempt
 * pin. Persists the full internal grading_result (including details[]) while
 * returning the grading envelope: { result (six keys), attempts }.
 */
class GradeBlockController extends Controller
{
    public function __construct(
        private readonly StudentManifest $studentManifest,
        private readonly GradingToken $gradingToken,
        private readonly BlockTypeRegistry $registry,
        private readonly QuizResponseValidator $quizResponses,
        private readonly StudentGradingResult $studentResults,
        private readonly AttemptService $attempts,
        private readonly ManifestBlockLookup $blocks,
        private readonly RetryPolicy $retries,
        private readonly RevealEvaluator $reveals,
    ) {
    }

    public function __invoke(Request $request, string $code, string $blockId): JsonResponse
    {
        $lesson = Lesson::query()->where('code', $code)->first();

        if ($lesson === null || $this->studentManifest->availableVersion($lesson) === null) {
            abort(404);
        }

        try {
            $tokenVersion = $this->gradingToken->resolve($request->input('version_token'), $lesson);
        } catch (InvalidGradingToken) {
            return $this->invalidToken();
        }

        $attempt = $this->attempts->inProgressMatchingVersion(
            Auth::user(),
            $lesson,
            $tokenVersion->id
        );

        if ($attempt === null) {
            // An in-progress attempt on this lesson with a different pin means
            // the client brought the wrong version_token — same 422 as before,
            // not a 404 that implies "no attempt exists."
            $otherPin = LessonAttempt::query()
                ->where('user_id', Auth::id())
                ->where('lesson_id', $lesson->id)
                ->where('status', AttemptStatus::InProgress)
                ->exists();

            if ($otherPin) {
                return $this->invalidToken();
            }

            abort(404);
        }

        // The attempt is the authority; the token is a tamper-proof check.
        if ($tokenVersion->id !== $attempt->lesson_version_id) {
            return $this->invalidToken();
        }

        if (! Auth::user()->can('work', $attempt)) {
            abort(403);
        }

        return DB::transaction(function () use ($request, $attempt, $blockId, $tokenVersion) {
            /** @var LessonAttempt $locked */
            $locked = LessonAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== AttemptStatus::InProgress) {
                return response()->json([
                    'message' => 'This attempt is closed.',
                ], 409);
            }

            $locked->loadMissing('lessonVersion');
            $block = $this->blocks->findBlock($locked->lessonVersion->manifest, $blockId);

            if ($block === null) {
                abort(404);
            }

            $type = $this->registry->get($block['type']);

            if (! $type->isAutoGradable() || $type->gradingResponseShape() === null) {
                return response()->json([
                    'message' => 'This block cannot be graded.',
                ], 422);
            }

            $shape = $type->gradingResponseShape();
            $config = is_array($block['config'] ?? null) ? $block['config'] : [];
            $grading = is_array($block['grading'] ?? null) ? $block['grading'] : null;

            // Eligibility before grading — refuse without recording anything.
            if (! $this->retries->canSubmit($locked, $blockId, $grading)) {
                return response()->json([
                    'message' => __('quiz.no_attempts_remaining'),
                ], 422);
            }

            $validatedResponse = match ($shape) {
                'quiz_answers' => $this->quizResponses->validate($config, $request->input('response')),
                default => throw new LogicException(
                    "Block type \"{$type->key()}\" declared grading response shape \"{$shape}\" but no validator is wired for it."
                ),
            };

            $result = $type->grade(
                $config,
                $grading,
                $shape === 'quiz_answers' ? ['answers' => $validatedResponse] : $validatedResponse
            );

            if ($result === null) {
                abort(500);
            }

            $nextNumber = (int) $locked->blockSubmissions()
                ->where('block_id', $blockId)
                ->max('attempt_number') + 1;

            $passed = (bool) $result['passed'];
            $revealTrigger = $this->reveals->triggerForNewSubmission($grading, $passed);
            $now = now();

            $row = BlockSubmission::query()->create([
                'lesson_attempt_id' => $locked->id,
                'lesson_version_id' => $locked->lesson_version_id,
                'block_id' => $blockId,
                'block_type' => $block['type'],
                'attempt_number' => max(1, $nextNumber),
                'response' => $shape === 'quiz_answers'
                    ? ['answers' => $validatedResponse]
                    : $validatedResponse,
                'grading_result' => $result,
                'score' => $result['score'],
                'max_score' => $result['max_score'],
                'percentage' => $result['percentage'],
                'passed' => $passed,
                'requires_manual_review' => (bool) ($result['requires_manual_review'] ?? false),
                'reveal_trigger' => $revealTrigger,
                'revealed_at' => $revealTrigger !== null ? $now : null,
                'active_seconds_at_submission' => $locked->active_seconds,
                'submitted_at' => $now,
            ]);

            $locked->forceFill(['last_activity_at' => $now])->save();

            $reveal = $this->studentResults->revealFromSubmission($row, $type, $config, $grading);
            $public = $this->studentResults->mapResult($result, $reveal);
            $counts = $this->retries->counts($locked, $blockId, $grading);

            return response()->json($this->studentResults->envelope($public, $counts));
        });
    }

    private function invalidToken(): JsonResponse
    {
        return response()->json([
            'message' => 'The grading session is invalid.',
        ], 422);
    }
}
