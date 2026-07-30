<?php

namespace App\Support;

use App\Enums\RevealPolicy;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Publisher-owned grading shape validation and defaults. Block types compile
 * only their config; the publisher asks this class (via AbstractBlockType)
 * to normalize grading before it lands in a manifest.
 */
final class GradingConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $includeReveal): array
    {
        $rules = [
            'rule' => ['required', Rule::in(['all_correct', 'min_score', 'completion_only'])],
            'min_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'allow_retry' => ['required', 'boolean'],
            'max_attempts' => ['nullable', 'integer', 'min:1'],
            'show_feedback' => ['required', 'boolean'],
            'record_first_attempt' => ['required', 'boolean'],
            'points' => ['nullable', 'integer', 'min:0'],
        ];

        if ($includeReveal) {
            $rules['reveal_policy'] = ['required', Rule::enum(RevealPolicy::class)];
            $rules['reveal_answers'] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>|null  $grading
     * @return array<string, mixed>|null
     *
     * @throws ValidationException
     */
    public static function validateAndCompile(?array $grading, bool $isAutoGradable): ?array
    {
        if ($grading === null) {
            if ($isAutoGradable) {
                throw ValidationException::withMessages([
                    'grading' => 'Auto-gradable blocks require a grading configuration.',
                ]);
            }

            return null;
        }

        // Reveal applies only to auto-gradable types — carrying these keys on
        // a non-gradable block is a validation error (points/rule may still
        // exist for CER and similar until the gradebook lands in 4B).
        if (! $isAutoGradable) {
            if (array_key_exists('reveal_policy', $grading) || array_key_exists('reveal_answers', $grading)) {
                throw ValidationException::withMessages([
                    'grading.reveal_policy' => 'Reveal settings apply only to auto-gradable blocks.',
                ]);
            }

            $withDefaults = array_merge(self::baseDefaults(), $grading);
            $validator = Validator::make($withDefaults, self::rules(includeReveal: false));

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            /** @var array<string, mixed> $validated */
            $validated = $validator->validated();

            return self::normalizeBase($validated);
        }

        $withDefaults = array_merge(self::defaults(), $grading);
        $validator = Validator::make($withDefaults, self::rules(includeReveal: true));

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return array_merge(self::normalizeBase($validated), [
            'reveal_policy' => $validated['reveal_policy'] instanceof RevealPolicy
                ? $validated['reveal_policy']->value
                : (string) $validated['reveal_policy'],
            'reveal_answers' => (bool) $validated['reveal_answers'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return array_merge(self::baseDefaults(), [
            'reveal_policy' => RevealPolicy::Never->value,
            'reveal_answers' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseDefaults(): array
    {
        return [
            'rule' => 'all_correct',
            'min_score' => null,
            'allow_retry' => true,
            'max_attempts' => null,
            'show_feedback' => true,
            'record_first_attempt' => true,
            'points' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function normalizeBase(array $validated): array
    {
        return [
            'rule' => $validated['rule'],
            'min_score' => $validated['min_score'],
            'allow_retry' => (bool) $validated['allow_retry'],
            'max_attempts' => $validated['max_attempts'],
            'show_feedback' => (bool) $validated['show_feedback'],
            'record_first_attempt' => (bool) $validated['record_first_attempt'],
            'points' => $validated['points'],
        ];
    }
}
