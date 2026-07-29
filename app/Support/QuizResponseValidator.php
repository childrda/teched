<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Complete-submission validation for a quiz grading request.
 *
 * A partial payload would return a score against a fixed max_score and let a
 * student isolate whether a single answer was right. Missing, unknown, and
 * extra question ids are therefore all 422 — never graded.
 *
 * Messages may name structure (a missing question id, an unknown option)
 * because those ids are already in the redacted manifest. They must never
 * mention correctness.
 */
class QuizResponseValidator
{
    /**
     * @param  array<string, mixed>  $compiledConfig
     * @param  mixed  $response
     * @return array<string, string> question id → option id
     *
     * @throws ValidationException
     */
    public function validate(array $compiledConfig, mixed $response): array
    {
        if (! is_array($response) || $response === [] || array_is_list($response)) {
            throw ValidationException::withMessages([
                'response' => ['The response must be a non-empty object keyed by question id.'],
            ]);
        }

        $questions = is_array($compiledConfig['questions'] ?? null)
            ? $compiledConfig['questions']
            : [];

        $questionIds = [];
        $optionsByQuestion = [];

        foreach ($questions as $question) {
            if (! is_array($question) || ! is_string($question['id'] ?? null)) {
                continue;
            }

            $questionId = $question['id'];
            $questionIds[] = $questionId;
            $optionsByQuestion[$questionId] = array_values(array_filter(
                array_map(
                    fn ($option) => is_array($option) && is_string($option['id'] ?? null)
                        ? $option['id']
                        : null,
                    is_array($question['options'] ?? null) ? $question['options'] : []
                ),
                fn ($id) => $id !== null
            ));
        }

        $submittedIds = array_keys($response);
        $expected = $questionIds;
        sort($submittedIds);
        sort($expected);

        if ($submittedIds !== $expected) {
            $missing = array_values(array_diff($questionIds, array_keys($response)));
            $unknown = array_values(array_diff(array_keys($response), $questionIds));

            $messages = [];

            foreach ($missing as $id) {
                $messages["response.{$id}"][] = "A response is required for question \"{$id}\".";
            }

            foreach ($unknown as $id) {
                $messages["response.{$id}"][] = "Question id \"{$id}\" is not part of this quiz.";
            }

            if ($messages === []) {
                $messages['response'][] = 'The response must include exactly one entry per question.';
            }

            throw ValidationException::withMessages($messages);
        }

        $rules = [];
        $attributes = [];

        foreach ($questionIds as $questionId) {
            $options = $optionsByQuestion[$questionId];
            $rules["response.{$questionId}"] = ['required', 'string', 'in:'.implode(',', $options)];
            $attributes["response.{$questionId}"] = "question \"{$questionId}\"";
        }

        $validator = Validator::make(
            ['response' => $response],
            $rules,
            [
                'required' => 'A response is required for :attribute.',
                'string' => 'The answer for :attribute must be an option id.',
                'in' => 'The answer for :attribute is not one of that question\'s options.',
            ],
            $attributes
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /** @var array<string, string> $validated */
        $validated = $validator->validated()['response'];

        return $validated;
    }
}
