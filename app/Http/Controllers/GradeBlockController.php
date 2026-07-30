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
 * returning only the five-key public shape.
 *
 * Retry stays unlimited in this phase — max_attempts is stored in the
 * manifest but not enforced until Phase 3B.
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
    ) {
    }

    public function __invoke(Request $request, string $code, string $blockId): JsonResponse
    {
        $lesson = Lesson::query()->where('code', $code)->first();

        if ($lesson === null || $this->studentManifest->availableVersion($lesson) === null) {
            abort(404);
        }

        $attempt = $this->attempts->inProgressFor(Auth::user(), $lesson);

        if ($attempt === null) {
            abort(404);
        }

        try {
            $tokenVersion = $this->gradingToken->resolve($request->input('version_token'), $lesson);
        } catch (InvalidGradingToken) {
            return $this->invalidToken();
        }

        // The attempt is the authority; the token is a tamper-proof check.
        if ($tokenVersion->id !== $attempt->lesson_version_id) {
            return $this->invalidToken();
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

            // Keep block_states after a successful quiz submission so a
            // resumed student still sees the selections they submitted
            // rather than an empty quiz.
            BlockSubmission::query()->create([
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
                'passed' => (bool) $result['passed'],
                'requires_manual_review' => (bool) ($result['requires_manual_review'] ?? false),
                'active_seconds_at_submission' => $locked->active_seconds,
                'submitted_at' => now(),
            ]);

            $locked->forceFill(['last_activity_at' => now()])->save();

            // Public response unchanged — exactly five keys. details[] lives
            // only in the database for Phase 3B and teacher reporting.
            return response()->json($this->studentResults->map($result));
        });
    }

    private function invalidToken(): JsonResponse
    {
        return response()->json([
            'message' => 'The grading session is invalid.',
        ], 422);
    }
}
