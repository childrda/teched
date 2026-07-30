<?php

namespace App\Enums;

/**
 * When a student may see per-item reveal data after grading.
 *
 * `on_final_attempt` and the final half of `on_pass_or_final_attempt` do not
 * fire in Phase 3B: a temporarily exhausted allowance is not terminal because
 * a teacher can grant more attempts. Terminal activity closure arrives with
 * the teacher dashboard. Phase 5's editor must surface this limitation when
 * it exposes these fields.
 */
enum RevealPolicy: string
{
    case Never = 'never';
    case OnPass = 'on_pass';
    case OnFinalAttempt = 'on_final_attempt';
    case OnPassOrFinalAttempt = 'on_pass_or_final_attempt';
}
