<?php

namespace App\Support;

use App\Blocks\Contracts\BlockType;
use App\Models\BlockSubmission;

/**
 * Teacher-side grading mapper. Reads block_submissions.grading_result directly
 * with no reveal_policy applied. Separate from StudentGradingResult — the two
 * surfaces must not share a mapper.
 */
class TeacherGradingResult
{
    /**
     * @param  array<string, mixed>  $compiledConfig
     * @param  array<string, mixed>|null  $grading
     * @return array{
     *     score: int|float|null,
     *     max_score: int|float|null,
     *     percentage: int|float|null,
     *     passed: bool|null,
     *     requires_manual_review: bool,
     *     attempt_number: int,
     *     submitted_at: string|null,
     *     details: list<array<string, mixed>>,
     *     record_first_attempt: bool
     * }|null
     */
    public function map(
        BlockSubmission $submission,
        BlockType $type,
        array $compiledConfig,
        ?array $grading,
    ): ?array {
        $internal = $submission->grading_result;

        if (! is_array($internal) && $submission->requires_manual_review !== true) {
            return null;
        }

        $internal = is_array($internal) ? $internal : [];

        return [
            'score' => $submission->score ?? ($internal['score'] ?? null),
            'max_score' => $submission->max_score ?? ($internal['max_score'] ?? null),
            'percentage' => $submission->percentage ?? ($internal['percentage'] ?? null),
            'passed' => $submission->passed,
            'requires_manual_review' => (bool) $submission->requires_manual_review,
            'attempt_number' => (int) $submission->attempt_number,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'details' => $this->details($internal, $submission, $type, $compiledConfig),
            'record_first_attempt' => (bool) ($grading['record_first_attempt'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $internal
     * @param  array<string, mixed>  $compiledConfig
     * @return list<array<string, mixed>>
     */
    private function details(
        array $internal,
        BlockSubmission $submission,
        BlockType $type,
        array $compiledConfig,
    ): array {
        $response = is_array($submission->response) ? $submission->response : [];
        $rows = [];

        foreach ($internal['details'] ?? [] as $detail) {
            if (! is_array($detail) || ! is_string($detail['item_id'] ?? null)) {
                continue;
            }

            $itemId = $detail['item_id'];
            $row = [
                'item_id' => $itemId,
                'correct' => (bool) ($detail['correct'] ?? false),
                'feedback' => $detail['feedback'] ?? null,
                'chosen' => $this->chosenFor($type->key(), $itemId, $response),
                'correct_answer' => $this->correctAnswerFor($type->key(), $itemId, $compiledConfig),
            ];

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function chosenFor(string $typeKey, string $itemId, array $response): mixed
    {
        return match ($typeKey) {
            'quiz' => $response['answers'][$itemId] ?? $response[$itemId] ?? null,
            'matching' => $response['matches'][$itemId] ?? null,
            'image_labeling' => $response['placements'][$itemId] ?? null,
            default => $response[$itemId] ?? null,
        };
    }

    /**
     * @param  array<string, mixed>  $compiledConfig
     */
    private function correctAnswerFor(string $typeKey, string $itemId, array $compiledConfig): mixed
    {
        return match ($typeKey) {
            'quiz' => $this->quizAnswer($itemId, $compiledConfig),
            'matching' => $this->slotAnswer($itemId, $compiledConfig['slots'] ?? []),
            'image_labeling' => $this->slotAnswer($itemId, $compiledConfig['hotspots'] ?? []),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $compiledConfig
     */
    private function quizAnswer(string $questionId, array $compiledConfig): ?string
    {
        foreach ($compiledConfig['questions'] ?? [] as $question) {
            if (is_array($question) && ($question['id'] ?? null) === $questionId) {
                return is_string($question['answer_id'] ?? null) ? $question['answer_id'] : null;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $slots
     */
    private function slotAnswer(string $slotId, array $slots): ?string
    {
        foreach ($slots as $slot) {
            if (is_array($slot) && ($slot['id'] ?? null) === $slotId) {
                return is_string($slot['answer_id'] ?? null) ? $slot['answer_id'] : null;
            }
        }

        return null;
    }
}
