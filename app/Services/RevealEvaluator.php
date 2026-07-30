<?php

namespace App\Services;

use App\Enums\RevealPolicy;

/**
 * Decides whether a newly created submission earns a reveal stamp.
 */
class RevealEvaluator
{
    /**
     * @param  array<string, mixed>|null  $grading
     * @return 'passed'|'final_attempt'|null
     */
    public function triggerForNewSubmission(?array $grading, bool $passed): ?string
    {
        $policy = RevealPolicy::tryFrom((string) ($grading['reveal_policy'] ?? RevealPolicy::Never->value))
            ?? RevealPolicy::Never;

        return match ($policy) {
            RevealPolicy::Never => null,
            RevealPolicy::OnPass => $passed ? 'passed' : null,
            RevealPolicy::OnFinalAttempt => $this->finalAttemptTrigger(),
            // Behaves as on_pass until terminality exists (see enum docblock).
            RevealPolicy::OnPassOrFinalAttempt => $passed ? 'passed' : null,
        };
    }

    /**
     * Terminal activity closure is not available in 3B — a temporarily
     * exhausted allowance can still receive teacher grants — so final-attempt
     * reveal never fires here.
     */
    private function finalAttemptTrigger(): ?string
    {
        return null;
    }
}
