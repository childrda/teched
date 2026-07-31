<?php

namespace App\Services\Authoring;

use App\Blocks\BlockTypeRegistry;
use App\Enums\PageCompletionType;
use App\Services\PageCompletionEvaluator;

/**
 * Authoring-time check that a page's completion_type can be satisfied by its
 * blocks. Mirrors PageCompletionEvaluator::categoryFor + RULE_CATEGORIES —
 * runtime evaluation alone cannot refuse an empty contributor set.
 */
class PageCompletionSatisfiability
{
    public function __construct(private readonly BlockTypeRegistry $registry)
    {
    }

    /**
     * @param  list<array{type: string, config?: array<string, mixed>}>  $blocks
     */
    public function isSatisfiable(string $completionType, array $blocks): bool
    {
        $rule = PageCompletionType::tryFrom($completionType);
        if ($rule === null) {
            return false;
        }

        $needed = PageCompletionEvaluator::RULE_CATEGORIES[$rule->value] ?? null;
        if ($needed === null) {
            return false;
        }

        if ($needed === []) {
            return true;
        }

        foreach ($blocks as $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null)) {
                continue;
            }

            $category = $this->categoryFor($block['type'], is_array($block['config'] ?? null) ? $block['config'] : []);
            if ($category !== null && in_array($category, $needed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Same mapping as PageCompletionEvaluator::categoryFor, with video gated
     * on require_confirmation so confirm_video pages need a real confirmation block.
     *
     * @param  array<string, mixed>  $config
     */
    public function categoryFor(string $type, array $config = []): ?string
    {
        if (! $this->registry->has($type)) {
            return null;
        }

        return match ($type) {
            'short_response', 'cer' => 'response',
            'matching', 'image_labeling' => 'activity',
            'quiz' => 'gradable',
            'video' => ((bool) ($config['require_confirmation'] ?? false)) ? 'confirmation' : null,
            default => null,
        };
    }
}
