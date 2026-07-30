<?php

namespace App\Services;

use App\Blocks\BlockTypeRegistry;
use App\Models\BlockState;
use App\Models\BlockSubmission;
use App\Models\LessonAttempt;

/**
 * Server-side page completion — the record's copy of completion.js.
 *
 * The JS registry owns the UX gate; this class owns whether Continue may
 * advance the attempt. A divergence between the two is a bug in whichever
 * one is wrong, not something to reconcile at the call site. Parity tests
 * exercise the shared rule scenarios against evaluateRule().
 */
class PageCompletionEvaluator
{
    public const CONTRIBUTOR_CATEGORIES = [
        'confirmation',
        'response',
        'activity',
        'gradable',
    ];

    public const RULE_CATEGORIES = [
        'view' => [],
        'confirm_video' => ['confirmation'],
        'submit_required' => ['response', 'activity', 'gradable'],
        'complete_activity' => ['activity', 'gradable'],
        'pass_activity' => ['gradable'],
    ];

    private const DEFAULT_BLOCKED_MESSAGE = 'Finish this page to continue.';

    private const NOT_YET_SHOWN_MESSAGE = 'Open this page to continue.';

    public function __construct(private readonly BlockTypeRegistry $registry)
    {
    }

    /**
     * Rule evaluation matching createCompletionRegistry().evaluate in
     * resources/js/lesson-player/completion.js.
     *
     * @param  list<array{id: string, category: string, isSatisfied: bool, isPassed?: bool, message?: string}>  $contributors
     * @return array{satisfied: bool, message: string|null}
     */
    public function evaluateRule(string $rule, array $contributors, bool $shown = true): array
    {
        if ($rule === 'view') {
            return $shown
                ? ['satisfied' => true, 'message' => null]
                : ['satisfied' => false, 'message' => self::NOT_YET_SHOWN_MESSAGE];
        }

        $categories = self::RULE_CATEGORIES[$rule] ?? [];

        foreach ($contributors as $contributor) {
            if (! in_array($contributor['category'], $categories, true)) {
                continue;
            }

            if (! $this->meetsRule($contributor, $rule)) {
                return [
                    'satisfied' => false,
                    'message' => ($contributor['message'] ?? '') !== ''
                        ? (string) $contributor['message']
                        : self::DEFAULT_BLOCKED_MESSAGE,
                ];
            }
        }

        return ['satisfied' => true, 'message' => null];
    }

    /**
     * @param  array<string, mixed>  $page  pinned manifest page
     * @return array{satisfied: bool, message: string|null}
     */
    public function evaluatePage(LessonAttempt $attempt, array $page): array
    {
        $rule = (string) ($page['completion_type'] ?? 'view');
        $contributors = $this->contributorsForPage($attempt, $page);

        // The student has opened this page to press Continue.
        return $this->evaluateRule($rule, $contributors, shown: true);
    }

    /**
     * @param  array<string, mixed>  $page
     * @return list<array{id: string, category: string, isSatisfied: bool, isPassed?: bool, message?: string}>
     */
    public function contributorsForPage(LessonAttempt $attempt, array $page): array
    {
        $states = $attempt->blockStates()
            ->get()
            ->keyBy('block_id');

        $latestSubmissions = $attempt->blockSubmissions()
            ->orderByDesc('attempt_number')
            ->get()
            ->unique('block_id')
            ->keyBy('block_id');

        $contributors = [];

        foreach ($page['blocks'] ?? [] as $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null) || ! is_string($block['block_id'] ?? null)) {
                continue;
            }

            $type = $this->registry->get($block['type']);
            $category = $this->categoryFor($block['type']);

            if ($category === null) {
                continue;
            }

            $config = is_array($block['config'] ?? null) ? $block['config'] : [];
            /** @var BlockState|null $stateRow */
            $stateRow = $states->get($block['block_id']);
            $state = is_array($stateRow?->state) ? $stateRow->state : [];

            /** @var BlockSubmission|null $submission */
            $submission = $latestSubmissions->get($block['block_id']);

            if ($category === 'gradable') {
                $contributors[] = [
                    'id' => $block['block_id'].':gradable',
                    'category' => 'gradable',
                    'isSatisfied' => $submission !== null,
                    'isPassed' => $submission?->passed === true,
                    'message' => self::DEFAULT_BLOCKED_MESSAGE,
                ];

                continue;
            }

            if ($category === 'response' || $category === 'activity') {
                $contributors[] = [
                    'id' => $block['block_id'].':'.$category,
                    'category' => $category,
                    'isSatisfied' => $type->isStateSatisfied($state, $config),
                    'message' => self::DEFAULT_BLOCKED_MESSAGE,
                ];

                continue;
            }

            // confirmation (and any future default-satisfied category)
            $contributors[] = [
                'id' => $block['block_id'].':'.$category,
                'category' => $category,
                'isSatisfied' => true,
                'message' => self::DEFAULT_BLOCKED_MESSAGE,
            ];
        }

        return $contributors;
    }

    /**
     * Maps block types onto completion.js contributor categories.
     *
     * Placement blocks register as "gradable" in the Alpine layer today so
     * pass_activity can require isPassed(), but they have no grading submit
     * path yet. Here they are "activity" and satisfied by filled slots —
     * matching complete_activity / submit_required on the client via
     * isSatisfied(). Quiz alone is "gradable" and uses submission rows.
     */
    private function categoryFor(string $type): ?string
    {
        return match ($type) {
            'short_response', 'cer' => 'response',
            'matching', 'image_labeling' => 'activity',
            'quiz' => 'gradable',
            'video' => 'confirmation',
            default => null,
        };
    }

    /**
     * @param  array{id: string, category: string, isSatisfied: bool, isPassed?: bool, message?: string}  $contributor
     */
    private function meetsRule(array $contributor, string $rule): bool
    {
        if ($rule === 'pass_activity' && $contributor['category'] === 'gradable') {
            return ($contributor['isPassed'] ?? false) === true;
        }

        return ($contributor['isSatisfied'] ?? false) === true;
    }
}
