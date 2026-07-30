# Phase 3B — Grading policy, retries, and reveal

Phase 3A recorded everything faithfully and enforced nothing. This phase decides what a student is allowed to do and what they are allowed to see.

**Not** in scope: the teacher progress dashboard, classes, rosters, `lesson_assignments`, `points`, and grade export. Those are 4A and 4B. This phase adds only the minimum staff surface needed to unblock a stuck student.

Accessibility and localization remain acceptance criteria.

---

## 1. Config additions

Two new keys on the block grading config, joining the existing `rule`, `min_score`, `allow_retry`, `max_attempts`, `record_first_attempt`, and `points`. **Do not rename anything that exists.**

- `reveal_policy` — string, backed by a new `RevealPolicy` enum: `never`, `on_pass`, `on_final_attempt`, `on_pass_or_final_attempt`. Default `never`.
- `reveal_answers` — boolean, default `false`. Separate from feedback on purpose: a teacher may want to explain a concept without handing over the correct option.

Thread both through every layer that already handles grading config: `configRules()` / `validateConfig()`, `compileConfig()`, `docs/schemas/lesson-manifest.schema.json`, and the `fullGradingShape()` test helper in `tests/Pest.php`. Reveal applies only to auto-gradable types; a non-gradable block carrying these keys is a validation error.

`points` stays unenforced and untouched. It gains meaning in 4B with a gradebook.

## 2. Retry eligibility, and teacher grants

Teacher intervention must never delete history. Nothing is reset — records **add** attempts, so the domain language should say so.

New table `attempt_retry_grants`, model `AttemptRetryGrant`:

- `lesson_attempt_id` → lesson_attempts, cascade on delete
- `block_id` — string (the manifest ULID, as everywhere else)
- `granted_by_user_id` → users
- `additional_attempts` — unsigned integer, minimum 1
- `reason` — string, nullable
- `created_at`
- Index `(lesson_attempt_id, block_id)`

**Grants are immutable**, enforced the same way `BlockSubmission` and `LessonVersion` are: `updating` and `deleting` events throw, plus `update()` and `delete()` overrides, plus the same comment about model events not firing for query-builder writes. Test both paths. A correction is another grant, never an edit.

Eligibility is computed server-side, never stored as a remaining-count:

```
allowed = max_attempts + SUM(additional_attempts for this attempt + block)
used    = COUNT(block_submissions for this attempt + block)
```

Put this in one place — `app/Services/RetryPolicy.php` or similar — used by the grading endpoint, the reveal mapper, the restore payload, and the tests. Two implementations of this arithmetic will drift.

Rules:

- `max_attempts` null means unlimited. Grants are irrelevant then, and `allowed` is null rather than a number.
- `allow_retry` false means exactly one submission regardless of `max_attempts`. Treat it as `max_attempts = 1` at evaluation time rather than special-casing it downstream.

## 3. Enforcement at the grading endpoint

The endpoint keeps its complete-submission validation and its attempt resolution from 3A. It now refuses submissions past the allowance.

- Compute eligibility **before** grading. When `used >= allowed`, return 422 with a translated message stating that no attempts remain and that a teacher must grant more. Do not grade, do not record a submission, do not touch the attempt.
- Enforcement is entirely server-side. The client displays what it is told and never decides whether a submission is permitted — a client that has lost count must be corrected by the server, not trusted.
- Check inside the same transaction that already holds `lockForUpdate()` on the attempt, so two simultaneous submissions cannot both pass the check and consume the last slot.

Every successful grading response carries the counts the UI needs. They ride in an **envelope** around the result, because the result object's own shape is fixed at six keys:

```json
{
  "result": { "…six keys…" },
  "attempts": { "used": 2, "allowed": 3, "remaining": 1 }
}
```

Two distinct contracts, and the tests must treat them separately:

- **the result object** — exactly six keys (section 4)
- **the grading envelope** — exactly `result` and `attempts`

The resume payload reuses this same envelope rather than inventing another shape (section 7). If the envelope breaks the existing client contract more than you expect, stop and say so rather than reshaping the result object to fit the counts in.

`allowed` and `remaining` are null when unlimited.

## 4. The six-key result contract

2C fixed the public result at exactly five keys, and the client's `isPublicResult()` rejects anything else. That check stays strict; it gains exactly one key.

Top level is **always** these six, in every response and in the resume payload:

```json
{
  "score": 7,
  "max_score": 10,
  "percentage": 70,
  "passed": false,
  "requires_manual_review": false,
  "reveal": null
}
```

`reveal` is always present. It is `null` until disclosure is earned, and otherwise a strict object:

```json
{
  "trigger": "final_attempt",
  "items": [
    { "question_id": "question-1", "correct": false, "feedback": "…", "correct_option_id": null }
  ]
}
```

- `trigger` is `passed` or `final_attempt`.
- `feedback` is nullable, and respects the existing `show_feedback` flag — that flag already nulls feedback inside `buildGradingResult()`, so honor it rather than re-deriving.
- `correct_option_id` is nullable and non-null **only** when `reveal_answers` permits it.
- `items` carries nothing else. Never `details`, `answer_id`, `source_ref`, or any part of the internal grading-result structure.

Update `isPublicResult()` to expect the six keys, then validate the nested object separately when `reveal !== null`. Keep the two checks distinct so a malformed reveal object is rejected without silently accepting a five-key body.

**The `items` shape belongs to the block type, not to a shared mapper.** `question_id` is quiz vocabulary. Add a method to the gradable block type that maps its own internal `details[]` into student-safe reveal items, so a future gradable type produces its own shape rather than being forced into quiz terms. `StudentGradingResult` composes the six keys and asks the type for `items`.

## 5. Reveal is stamped when the submission is created

"Once earned, reload must not remove it" cannot hold if eligibility is recomputed on every read. A teacher granting retries after a student has already seen a reveal would change the answer, and the reveal would silently disappear.

So persist it. Add to `block_submissions`:

- `reveal_trigger` — string, nullable (`passed` or `final_attempt`)
- `revealed_at` — timestamp, nullable, with an explicit default per the MySQL timestamp rule

For `on_pass`, eligibility is known **inside the grading transaction** — the submission being written is the one that passed. So set both columns while creating the row. Nothing is ever mutated, and `BlockSubmission`'s immutability guard stays exactly as strict as it is.

**Do not add a mutation exception to `BlockSubmission`.** There is no case in this phase that needs one: `on_final_attempt` never fires (section 6), so no already-written submission ever needs stamping after the fact. When terminal-activity closure arrives in a later phase, it will either determine reveal while creating the final submission or record reveal grants in a separate immutable table. Building a narrow escape hatch now, for a case that does not exist, is how immutability guarantees get quietly widened later.

A submission with `reveal_trigger` set always returns its reveal object, regardless of what current policy evaluation would say. That read path is what makes reveal sticky; the write path never revisits it.

## 6. What `final_attempt` means

A temporarily exhausted allowance is **not** terminal, because a teacher can grant more attempts. Revealing on it would hand out answers that a subsequent grant makes premature.

For this phase:

- `on_pass` reveals when the submission passes. This works fully.
- `on_final_attempt` requires the activity to be terminal *under policy*, which is not determinable yet — there is no teacher action that closes an activity. So it never fires in 3B. Implement the enum value, evaluate it to false, and comment plainly that terminality arrives with the teacher dashboard.
- `on_pass_or_final_attempt` therefore behaves as `on_pass` for now.
- `never` reveals nothing.

Only the seeder authors grading config today, so no teacher can select a value that quietly does nothing. Note in the code comment that the Phase 5 editor must surface this limitation when it exposes these fields.

## 7. `record_first_attempt`

Option 2: the flag controls **exposure**, never storage and never grading. Every submission is stored either way, and 3A already does that.

- Always store every immutable submission.
- Always return the latest result.
- Return `first_result` only when `record_first_attempt` is true; otherwise return `first_result: null`.

One stable restore shape per gradable block, reusing the section 3 envelope on both sides rather than inventing a second form:

```json
{
  "first_result": null,
  "latest_result": {
    "result": {
      "score": 8,
      "max_score": 10,
      "percentage": 80,
      "passed": true,
      "requires_manual_review": false,
      "reveal": null
    },
    "attempts": { "used": 2, "allowed": 3, "remaining": 1 }
  }
}
```

Do not reinterpret the flag as "only save the first attempt." The immutable history stays complete regardless, and nothing is deleted or overridden.

The question of which number a gradebook treats as *the* score is 4B's to answer and Phase 7's to consume. Do not decide it here, and do not add a column implying it has been decided.

## 8. Locked pages and student experience

When a student is out of attempts on a `pass_activity` page without having passed:

- The page stays locked. Continue is unavailable — server-side too, since `PageCompletionEvaluator` already requires a passed submission for `pass_activity`.
- The block shows a clear, translated message: no attempts remain, and a teacher needs to grant more. Say what happens next, not just that something failed.
- Submit is unavailable, and the reason is stated rather than the button silently disabled.
- Everything else keeps working. The student can navigate to earlier pages, leave, and resume later with all work intact. Nothing about being blocked may interfere with autosave.
- Announce the blocked state politely in the existing live region, once — not on every render.

Attempts remaining should be visible before the last one, not sprung at the end: show used and remaining whenever `allowed` is not null.

## 9. Teacher actions

Two distinct actions. Do not conflate them.

**Grant retries** — adds attempts to one block on one existing lesson attempt. This is the normal intervention for a student stuck on one quiz. Creates an `attempt_retry_grants` row. History is untouched.

**Restart lesson** — creates a **new** `in_progress` attempt pinned to the currently available version, and preserves the old one entirely.

An unfinished attempt must **not** be marked `completed` to satisfy the one-active-attempt invariant. It wasn't completed, and teacher reporting would inherit that lie before the dashboard is even built. Add a third case to `AttemptStatus`:

```php
case Superseded = 'superseded';
```

On restart, inside a transaction:

- lock the old attempt
- set its status to `superseded`, leaving `completed_at` **null**
- record the actor and the moment via two new nullable columns on `lesson_attempts`: `superseded_at` and `superseded_by_user_id` (→ users)
- create the new `in_progress` attempt
- leave every `block_state` and `block_submission` on the old attempt exactly as it is

The one-active-attempt guard keeps working unchanged, since the generated column keys on `status = 'in_progress'` and a superseded row falls out of it.

Check every place that branches on status. `resolveForPlayer()` and `existingAttempt()` currently look for `in_progress`, then most-recent `completed` — a superseded attempt must not be resurrected as a student's read-only view when a newer in-progress attempt exists, and the read-only fallback should still prefer a genuinely completed attempt. Write down the ordering you choose.

Note this is a change from the earlier assumption that students could restart themselves: **restart is staff-only.** A student who completes a lesson keeps their read-only view and cannot begin again unaided. If that is wrong, stop and say so.

**Minimal staff surface, not a dashboard:**

- Routes restricted to `teacher` and `admin` roles — a middleware or gate using the `UserRole` predicates from 2D. A student reaching them gets a 403.
- One protected page listing attempts currently blocked on a gradable block: student name, lesson, block, used/allowed, and the two actions. Nothing more. No search, no filtering, no per-student drill-down — 4B builds that.
- Both actions are POST with CSRF, and both record who acted.

**Name the authorization hole explicitly in a comment on the controller:** there is no roster yet, so any `teacher` can act on any student's attempt. That is acceptable only because 4A introduces classes, memberships, and the visibility rules that scope this properly. Write that down where the next person will read it, and add a test asserting the role gate holds even though the ownership scope does not exist yet.

## 10. Minimal student landing

Login currently redirects to an empty welcome page, and the only way to reach a lesson is typing its code. 3B needs the smallest thing that fixes that.

On `/` for an authenticated student: a list of published lessons with, per lesson, whether they have an attempt, its status, and a Resume or Start link. Nothing assignment-aware — that needs 4A, and 4B replaces this outright.

Keep it deliberately plain and say so in a comment, so nobody mistakes it for the real dashboard. Teachers and admins see the same list plus a link to the blocked-attempts page.

## 11. Tests

**Enforcement.** A submission at the limit is refused with 422 and records nothing. A grant of one attempt makes exactly one more submission possible, and the one after that is refused again. `allow_retry: false` permits exactly one submission even when `max_attempts` is 5. Null `max_attempts` permits many. Two simultaneous submissions with one slot left result in exactly one recorded submission.

**Reveal.** `never` never reveals. `on_pass` reveals on a passing submission and not on a failing one. `on_final_attempt` does not fire in this phase. `reveal_answers: false` yields a null `correct_option_id` while feedback still appears; `true` yields the option id. `show_feedback: false` yields null feedback while correctness still appears. Reveal items contain no forbidden keys — extend the existing `assertNoForbiddenKeys()` helper to cover reveal payloads.

**Stickiness.** A student passes and earns reveal; a teacher then grants retries; the earned reveal is still returned on reload, because it was stamped on the submission rather than recomputed. `BlockSubmission` remains fully immutable — no test may need to mutate one to make reveal stick.

**Shape.** Every grading response is an envelope of exactly `result` and `attempts`; the result object has exactly the six keys with `reveal` present and null when not earned. The resume payload uses the same envelope inside `latest_result`, and `first_result` is null when `record_first_attempt` is false and populated when true. The internal `grading_result` in the database still contains `details[]`.

**Staff actions.** A student gets 403 on both routes. A teacher can grant retries and restart. Grants are immutable — `update()` and `delete()` both throw. A restart marks the old attempt `superseded` with `completed_at` still null, stamps `superseded_at` and `superseded_by_user_id`, creates a new `in_progress` attempt, leaves the old attempt's states and submissions intact, and does not break the one-active-attempt invariant. A superseded attempt is never shown as a student's read-only view while a newer in-progress attempt exists.

**JS.** `isPublicResult()` accepts the six-key shape and rejects five keys, seven keys, and a malformed reveal object. The blocked state renders the message, hides submit, announces once, and does not disturb autosave.

## 12. Acceptance

- `php artisan test` and `npx vitest run` fully green.
- `php artisan migrate:fresh --seed` works against the dev database, and WEL 6.1.1 plays end to end.
- With `max_attempts: 2` and `reveal_policy: on_pass` seeded on the quiz: fail twice, see the blocked message and a locked page, have a teacher grant one retry, pass, see the reveal, reload, and still see it.
- No `details[]`, `answer_id`, or `source_ref` in any student-facing response.
- Version pinning still holds in the player and the API.
