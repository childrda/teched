<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class QuizBlock extends AbstractBlockType
{
    public function key(): string
    {
        return 'quiz';
    }

    public function label(): string
    {
        return 'Quiz';
    }

    public function isAutoGradable(): bool
    {
        return true;
    }

    public function collectsResponse(): bool
    {
        return true;
    }

    public function gradingResponseShape(): ?string
    {
        return 'quiz_answers';
    }

    public function configRules(): array
    {
        return [
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.id' => ['required', 'string'],
            'questions.*.prompt' => ['required', 'string'],
            'questions.*.options' => ['required', 'array', 'min:2'],
            'questions.*.options.*.id' => ['required', 'string'],
            'questions.*.options.*.text' => ['required', 'string'],
            'questions.*.answer_id' => ['required', 'string'],
            'questions.*.feedback' => ['nullable', 'string'],
            'questions.*.source_ref' => ['nullable', 'array'],
            'questions.*.source_ref.page' => ['required_with:questions.*.source_ref', 'string'],
            'questions.*.source_ref.excerpt' => ['required_with:questions.*.source_ref', 'string'],
            'shuffle_questions' => ['required', 'boolean'],
        ];
    }

    protected function afterValidation(Validator $validator, array $config): void
    {
        $questions = is_array($config['questions'] ?? null) ? $config['questions'] : [];

        $this->assertDistinctIds($validator, $questions, 'questions');

        foreach ($questions as $qIndex => $question) {
            if (! is_array($question)) {
                continue;
            }

            $options = is_array($question['options'] ?? null) ? $question['options'] : [];

            $this->assertDistinctIds($validator, $options, "questions.{$qIndex}.options");

            $optionIds = array_column(array_filter($options, 'is_array'), 'id');
            $answerId = $question['answer_id'] ?? null;

            if ($answerId !== null && ! in_array($answerId, $optionIds, true)) {
                $validator->errors()->add(
                    "questions.{$qIndex}.answer_id",
                    "Question answer_id \"{$answerId}\" does not reference one of that question's own options."
                );
            }
        }
    }

    public function defaultConfig(): array
    {
        return [
            'questions' => [
                [
                    'id' => 'question-1',
                    'prompt' => 'Sample question?',
                    'options' => [
                        ['id' => 'option-1', 'text' => 'Correct answer'],
                        ['id' => 'option-2', 'text' => 'Distractor'],
                    ],
                    'answer_id' => 'option-1',
                    'feedback' => null,
                    'source_ref' => null,
                ],
            ],
            'shuffle_questions' => false,
        ];
    }

    public function compileConfig(array $validatedConfig): array
    {
        return [
            'questions' => array_values(array_map(
                function (array $q) {
                    $sourceRef = $q['source_ref'] ?? null;

                    return [
                        'id' => $q['id'],
                        'prompt' => $q['prompt'],
                        'options' => array_values(array_map(
                            fn (array $o) => ['id' => $o['id'], 'text' => $o['text']],
                            $q['options']
                        )),
                        'answer_id' => $q['answer_id'],
                        'feedback' => $q['feedback'] ?? null,
                        'source_ref' => $sourceRef === null ? null : [
                            'page' => $sourceRef['page'],
                            'excerpt' => $sourceRef['excerpt'],
                        ],
                    ];
                },
                $validatedConfig['questions']
            )),
            'shuffle_questions' => (bool) $validatedConfig['shuffle_questions'],
        ];
    }

    public function redactConfig(array $compiledConfig): array
    {
        $redacted = $compiledConfig;

        $redacted['questions'] = array_map(function (array $question) {
            unset($question['answer_id'], $question['feedback'], $question['source_ref']);

            return $question;
        }, $redacted['questions']);

        return $redacted;
    }

    public function holdsStudentState(): bool
    {
        return true;
    }

    public function validateState(array $state, array $compiledConfig): array
    {
        if (! array_key_exists('answers', $state) || ! is_array($state['answers'])) {
            throw ValidationException::withMessages([
                'state.answers' => 'Quiz state must include an answers object.',
            ]);
        }

        foreach (array_keys($state) as $key) {
            if ($key !== 'answers') {
                throw ValidationException::withMessages([
                    "state.{$key}" => 'Unrecognized quiz state key.',
                ]);
            }
        }

        $questions = is_array($compiledConfig['questions'] ?? null) ? $compiledConfig['questions'] : [];
        $optionsByQuestion = [];

        foreach ($questions as $question) {
            if (! is_array($question) || ! is_string($question['id'] ?? null)) {
                continue;
            }

            $optionIds = [];

            foreach ($question['options'] ?? [] as $option) {
                if (is_array($option) && is_string($option['id'] ?? null)) {
                    $optionIds[$option['id']] = true;
                }
            }

            $optionsByQuestion[$question['id']] = $optionIds;
        }

        $normalized = [];

        foreach ($state['answers'] as $questionId => $optionId) {
            if (! is_string($questionId) || ! isset($optionsByQuestion[$questionId])) {
                throw ValidationException::withMessages([
                    'state.answers' => "Unknown question id \"{$questionId}\".",
                ]);
            }

            if ($optionId === null) {
                $normalized[$questionId] = null;

                continue;
            }

            if (! is_string($optionId) || ! isset($optionsByQuestion[$questionId][$optionId])) {
                throw ValidationException::withMessages([
                    "state.answers.{$questionId}" => "Unknown option id \"{$optionId}\".",
                ]);
            }

            $normalized[$questionId] = $optionId;
        }

        return ['answers' => $normalized];
    }

    public function isStateSatisfied(array $state, array $compiledConfig): bool
    {
        // Quiz completion is driven by submission records, not working state.
        return true;
    }

    public function grade(array $compiledConfig, ?array $grading, array $response): ?array
    {
        $answers = $response['answers'] ?? [];

        $details = array_map(function (array $question) use ($answers) {
            $chosen = $answers[$question['id']] ?? null;

            return [
                'item_id' => $question['id'],
                'correct' => $chosen === $question['answer_id'],
                'feedback' => $question['feedback'] ?? null,
            ];
        }, $compiledConfig['questions']);

        return $this->buildGradingResult(array_values($details), $grading);
    }

    /**
     * Reads each question prompt followed by its lettered options. A
     * redacted config carries no answer_id, feedback, or source_ref, so
     * none of them can reach a speech segment.
     */
    public function speakableText(array $redactedConfig): array
    {
        $segments = [];

        foreach (array_values($redactedConfig['questions'] ?? []) as $questionIndex => $question) {
            $questionId = $question['id'] ?? $questionIndex;

            $this->pushSegment(
                $segments,
                "{$questionId}:prompt",
                'Question ' . ($questionIndex + 1),
                $question['prompt'] ?? null
            );

            foreach (array_values($question['options'] ?? []) as $optionIndex => $option) {
                $this->pushSegment(
                    $segments,
                    "{$questionId}:" . ($option['id'] ?? $optionIndex),
                    'Option ' . $this->optionLetter($optionIndex),
                    $option['text'] ?? null
                );
            }
        }

        return $segments;
    }
}
