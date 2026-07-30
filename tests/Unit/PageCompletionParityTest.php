<?php

use App\Services\PageCompletionEvaluator;

/**
 * Parity with tests/js/completion.test.js against evaluateRule().
 *
 * The JS registry owns the UX gate; this PHP copy owns the record. A
 * divergence is a bug in whichever one is wrong — not something to reconcile
 * at the Continue call site.
 */
function contributor(string $category, bool $satisfied, array $overrides = []): array
{
    return array_merge([
        'id' => "block:{$category}",
        'category' => $category,
        'isSatisfied' => $satisfied,
        'isPassed' => $satisfied,
        'message' => "{$category} is not finished",
    ], $overrides);
}

test('an unsatisfied contributor only blocks a rule when its category is relevant', function () {
    $evaluator = app(PageCompletionEvaluator::class);

    foreach (array_keys(PageCompletionEvaluator::RULE_CATEGORIES) as $rule) {
        foreach (PageCompletionEvaluator::CONTRIBUTOR_CATEGORIES as $category) {
            $relevant = in_array($category, PageCompletionEvaluator::RULE_CATEGORIES[$rule], true);
            $result = $evaluator->evaluateRule($rule, [contributor($category, false)], shown: true);

            expect($result['satisfied'])->toBe(! $relevant, "{$rule} / {$category}");
        }
    }
});

test('pass_activity requires isPassed on gradable contributors', function () {
    $evaluator = app(PageCompletionEvaluator::class);

    $failing = [contributor('gradable', true, [
        'isPassed' => false,
        'message' => 'Score at least 80% to continue.',
    ])];

    expect($evaluator->evaluateRule('complete_activity', $failing)['satisfied'])->toBeTrue()
        ->and($evaluator->evaluateRule('pass_activity', $failing)['satisfied'])->toBeFalse()
        ->and($evaluator->evaluateRule('pass_activity', $failing)['message'])
        ->toBe('Score at least 80% to continue.');
});
