<?php

namespace App\Http\Controllers;

use App\Blocks\BlockTypeRegistry;
use App\Exceptions\InvalidGradingToken;
use App\Models\Lesson;
use App\Services\GradingToken;
use App\Services\StudentManifest;
use App\Support\QuizResponseValidator;
use App\Support\StudentGradingResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * Stateless grading for one auto-gradable block against the LessonVersion a
 * student is actually playing.
 *
 * Aggregate-only scoring plus complete-submission validation stops a single
 * request from revealing per-question results or the explanatory feedback.
 * It does not make the endpoint unprobeable: a student can change one answer
 * between complete submissions and read the answer off the score moving by
 * one, recovering the key in roughly questions × options requests. The
 * throttle raises the cost further and nothing more.
 *
 * Real protection is Phase 3: persisted attempts, an author-configured retry
 * limit, and reveal only on passing or the final permitted attempt.
 */
class GradeBlockController extends Controller
{
    public function __construct(
        private readonly StudentManifest $studentManifest,
        private readonly GradingToken $gradingToken,
        private readonly BlockTypeRegistry $registry,
        private readonly QuizResponseValidator $quizResponses,
        private readonly StudentGradingResult $studentResults,
    ) {
    }

    public function __invoke(Request $request, string $code, string $blockId): JsonResponse
    {
        $lesson = Lesson::query()->where('code', $code)->first();

        // Availability only — the token decides which version grades.
        if ($lesson === null || $this->studentManifest->availableVersion($lesson) === null) {
            abort(404);
        }

        try {
            $version = $this->gradingToken->resolve($request->input('version_token'), $lesson);
        } catch (InvalidGradingToken) {
            return $this->invalidToken();
        }

        $block = $this->findBlock($version->manifest, $blockId);

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
            // A registered auto-gradable type with a wired submit path that
            // declines to grade a valid complete payload is a programming
            // error, not something a student can fix.
            abort(500);
        }

        return response()->json($this->studentResults->map($result));
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @return array<string, mixed>|null
     */
    private function findBlock(?array $manifest, string $blockId): ?array
    {
        foreach ($manifest['pages'] ?? [] as $page) {
            if (! is_array($page)) {
                continue;
            }

            foreach ($page['blocks'] ?? [] as $block) {
                if (! is_array($block)) {
                    continue;
                }

                if (($block['block_id'] ?? null) === $blockId) {
                    return $block;
                }
            }
        }

        return null;
    }

    private function invalidToken(): JsonResponse
    {
        return response()->json([
            'message' => 'The grading session is invalid.',
        ], 422);
    }
}
