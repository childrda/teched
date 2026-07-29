<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;
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
}
