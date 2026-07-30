# Phase 3A — Attempts, autosave, and resume

Everything a student does now survives a closed lid. Phase 2 ended with a player that grades correctly and forgets everything; this phase gives it a durable record.

Scope is deliberately "the record." **Not** in this phase: enforcing `max_attempts` or `allow_retry`, showing remaining attempts, `reveal_policy` / `reveal_answers`, and teacher-facing views. Those are Phase 3B, and they will be much easier to write against a schema that already exists. Store everything faithfully now; decide what students may do and see next.

Accessibility and localization remain acceptance criteria, as in every prior phase.

---

## 1. Schema

Three new tables, three new migrations. Follow the existing conventions: string columns with backed PHP enums, native JSON, ULID strings for manifest identifiers.

**`lesson_attempts`** — one row per student per run at a lesson.

- `user_id` → users, `lesson_id` → lessons, `lesson_version_id` → lesson_versions. The version is the pin: an attempt is bound to the compiled manifest it started against and never migrates.
- `current_page_id` — string. This is the manifest's `page_id` ULID, **not** a foreign key to `lesson_pages`. The authoring rows can be edited, reordered, or deleted after publishing; the manifest is the student's reality. Same reasoning for every `block_id` below.
- `status` — string, backed by a new `AttemptStatus` enum: `in_progress`, `completed`.
- `started_at`, `completed_at` (nullable), `last_activity_at`.
- `active_seconds` — unsigned integer, default 0. Accumulated active time; see section 6.
- `shuffle_seed` — string. One seed per attempt, so every shuffle in the lesson is stable across a resume.
- `revision` — unsigned integer, default 0. Guards the navigation and timing fields on this row only. Block content has its own counters and must never share this one.
- Index `(user_id, lesson_id, status)`.
- At most one `in_progress` attempt may exist per user per lesson. Enforce it in application code inside a transaction, **and** at the database level: a stored generated column that is `1` when the status is `in_progress` and `NULL` otherwise, with a unique index on `(user_id, lesson_id, <that column>)`. MySQL has no partial indexes, and NULLs do not collide in a unique index, so this works. Two browser tabs opening a lesson at the same moment is the race this prevents.
- **Make that generated column conditional on the database driver.** Do not force MySQL-specific generated-column syntax onto a SQLite test database — check the connection driver in the migration and skip the generated column and its index where unsupported, leaving the application-level guard and the insert-race recovery in section 3 to hold the line there. Note in a comment which environments get the database-level guarantee and which rely on application code.

**`block_states`** — mutable working state, one row per attempt per block.

- `lesson_attempt_id` → lesson_attempts, cascade on delete.
- `block_id` (string), `block_type` (string, denormalized so restore and reporting need not re-read the manifest).
- `state` — JSON.
- `revision` — unsigned integer, default 0.
- `timestamps`.
- Unique `(lesson_attempt_id, block_id)`.

**`block_submissions`** — immutable record of something submitted.

- `lesson_attempt_id` → lesson_attempts, `lesson_version_id` → lesson_versions (denormalized from the attempt for teacher queries; assert it matches on write).
- `block_id`, `block_type`.
- `attempt_number` — unsigned integer, assigned **server-side**, sequential per `(lesson_attempt_id, block_id)`.
- `response` — JSON, exactly what the student submitted.
- `grading_result` — JSON, nullable. The full internal `buildGradingResult()` including `details[]` for gradable blocks; null for `short_response` and `cer`.
- `score`, `max_score`, `percentage` — nullable numerics, denormalized from the grading result for querying.
- `passed` — nullable boolean. `requires_manual_review` — boolean, default false.
- `active_seconds_at_submission` — unsigned integer, nullable.
- `submitted_at`.
- Unique `(lesson_attempt_id, block_id, attempt_number)`.
- **Immutable after creation**, enforced exactly the way `LessonVersion` does it — `updating` and `deleting` events throw, plus the `update()` and `delete()` overrides — and carry the same comment about model events not firing for query-builder writes.

## 2. Block state contract

Autosave accepts student-supplied JSON, so every state payload must be validated by the block type that owns it. Without this the endpoint is an arbitrary JSON store on the student's side of the trust boundary.

Add to the `BlockType` contract, defaulting in `AbstractBlockType` so content types need no edit:

```php
/** Whether this type keeps student working state between visits. */
public function holdsStudentState(): bool;   // default false

/**
 * Validate and normalize an incoming state payload against the compiled
 * config. Throws ValidationException on anything unrecognized. Returns the
 * state as it should be stored — never the caller's array unchanged.
 */
public function validateState(array $state, array $compiledConfig): array;
```

Implement for the four stateful types:

- `short_response` — a single string, stored **as the student typed it**. Reject non-strings, normalize line endings (`\r\n` and `\r` → `\n`), and reject anything over 20,000 characters. Do **not** trim before storing: trimming alters what the student wrote and makes restored text differ from what was on screen. Store `{ "value": "..." }` with the leading and trailing whitespace intact, and use `trim()` only when evaluating satisfaction.
- `cer` — one string per configured field, keyed by field id, under the same rules. Unknown field ids are rejected, not dropped. Same normalization, same per-field cap, same no-trimming rule.
- `matching` and `image_labeling` — a slot-id → item-id map. Every slot id must exist in the compiled config, every item id must exist in that block's bank, `null` is allowed for an empty slot, and no item may appear in two slots. This is where the derived-bank invariant from 2B gets enforced on the server rather than trusted from the client.
- `quiz` — unsubmitted selections: a question-id → option-id map. Unknown question or option ids are rejected. Partial maps are fine here; unlike grading, saving work in progress does not require completeness.

`validateState()` must never see or need an answer key, and must never write one into stored state. Add a test per type that a payload referencing an unknown id is rejected rather than silently filtered.

On the client, each stateful Alpine component gains `serializeState()` and `restoreState(state)`. Restoring must be a no-op when the state is missing or malformed — a student with a corrupt saved state gets an empty activity, never a broken page.

## 3. Attempt lifecycle and version pinning

`LessonPlayerController` currently renders whatever version is current. That has to change, and it changes `StudentManifest` with it.

1. Split `StudentManifest`: add `forVersion(LessonVersion $version): array` holding the redaction, speech, and `grading_token` logic. `forLesson()` becomes `availableVersion()` followed by `forVersion()`.

   **The JSON manifest route must become attempt-aware too.** Leaving it on the current version means a student pinned to v1 in the player can fetch v2 from `/api/lessons/{code}` along with a v2 grading token their v1 attempt will correctly reject — an inconsistency with no legitimate use. For an authenticated student with an `in_progress` attempt, the API returns `forVersion($attempt->lessonVersion)` plus that attempt's restore payload, exactly as the player does. It must never return a newly published version to a student who is mid-attempt. With no attempt, it returns the currently available version as before.

   If you conclude the endpoint should instead be a current-version preview API for staff only, stop and say so rather than building both behaviors — but then it must not be something the player consumes.
2. On `GET /lessons/{code}`:
   - Resolve the lesson and require it playable via the existing `availableVersion()`.
   - Inside a transaction, find the user's `in_progress` attempt for this lesson. If one exists, use it.
   - **If none exists but a completed attempt does, render the most recent completed attempt in a read-only completed state.** A normal GET must never start a second run — otherwise refreshing after finishing immediately begins attempt two. Starting another run requires an explicit restart action, which is Phase 3B.
   - Only when the student has no attempt at all, create one bound to the currently available version with a fresh `shuffle_seed`, `started_at` and `last_activity_at` set, and `current_page_id` at the manifest's first page.
   - **Creation is a race.** Two tabs can both find no attempt and both insert. Attempt the insert inside a transaction; when the uniqueness mechanism rejects it, catch that, re-query the winning `in_progress` attempt, and return it. Both requests then operate on the same attempt. A database exception must never reach the student.
   - Read-only completed state means: the manifest renders, stored state is restored and visible, and every write path is closed. The autosave, activity, Continue, and grading endpoints all reject a non-`in_progress` attempt already; the client must also render without save affordances rather than letting a student type into a page that will 409.
   - Render `forVersion($attempt->lessonVersion)` — **the attempt's pinned version, not the current one.** A student mid-lesson when a teacher republishes stays on the manifest they started. There is no student-facing switch and no notice in this phase; a teacher restarting or reassigning is Phase 3B and beyond.
3. Embed alongside the manifest, for the client to restore from: the attempt id, `current_page_id`, `active_seconds`, the attempt `revision`, every `block_states` row for this attempt (block id, state, revision), and per gradable block a small summary of its latest submission — `attempt_number`, `score`, `max_score`, `percentage`, `passed`, `requires_manual_review`. **The five-key public shape from 2C is the ceiling for anything a student receives**: no `details[]`, no per-item correctness, no feedback. Reuse `StudentGradingResult` rather than hand-building this.
4. The grading endpoint now cross-checks: resolve the attempt, and require the `version_token`'s version to equal the attempt's pinned version. Disagreement is the same generic 422 as any other token failure. The attempt is the authority; the token is a tamper-proof consistency check on top of it.

## 4. Autosave endpoint

In `routes/web.php`, behind `auth` and inside the session/CSRF group. **Do not put this in `routes/api.php`** — that group has `StartSession` but no `VerifyCsrfToken`, and a session-authenticated write without CSRF protection is not an acceptable foundation. Add a comment in `bootstrap/app.php` saying so, next to the API middleware group.

```
PUT /player/attempts/{attempt}/blocks/{blockId}/state
```

Body: `{ "state": {...}, "revision": <last acknowledged revision, 0 if none> }`

In order:

1. Authorize: the attempt belongs to the authenticated user. Anything else is a 404, not a 403 — do not confirm that another student's attempt exists.
2. Reject writes to an attempt whose status is not `in_progress` (409, with a message saying the attempt is closed).
3. Resolve the block from the attempt's **pinned** version manifest, using the same page-traversal and strict `===` comparison the grading endpoint uses. Extract that lookup into one shared helper rather than keeping two copies.
4. 422 if the type does not `holdsStudentState()`.
5. Validate the payload through `validateState()`.
6. Apply optimistically: update the row `WHERE lesson_attempt_id = ? AND block_id = ? AND revision = ?`, incrementing `revision`. Zero rows affected means the client's revision is stale — return **409** with the current `revision` and `state`. Create the row when the client sends revision 0 and none exists; a revision-0 write against an existing row is also a 409.
7. Touch the attempt's `last_activity_at`.
8. Return `{ "revision": <new> }` and nothing else.

Throttle per authenticated user, generously — autosave is chatty by design. `throttle:120,1` and note in a comment that it is a runaway guard, not a security control.

## 5. Client autosave

Local-first, per your design. A small shared module the stateful components use, not four copies.

- Local state updates immediately. Typing and dragging are never blocked by a pending save.
- Debounce server writes at roughly 1,000ms per block.
- Queue pending state in `localStorage`, keyed by attempt id and block id, in this shape:

  ```js
  { state: {...}, baseRevision: 4, pendingSequence: 12, savedAt: "2026-07-29T..." }
  ```

  `baseRevision` is always the last revision the **server** issued for that block — never a number the client invented by adding one. `pendingSequence` is local-only and monotonic, so a later edit supersedes an earlier queued one without needing a server revision to order them. **Never clear a block's pending entry until the server acknowledges a write carrying that entry's `pendingSequence` or later.**
- On load, replay any pending entry whose `pendingSequence` exceeds what was last acknowledged, before anything else. Do not compare a pending entry against the server revision to decide whether it is newer — an unsent edit has no newer revision, which is exactly why `pendingSequence` exists.
- Retry failures with exponential backoff and a ceiling (roughly 1s → 30s), indefinitely while the page lives.
- Track the last acknowledged revision per block and send it as `revision` with every write. Never send a revision the server did not issue.
- One visible status per lesson, translated: **Saving**, **Saved**, **Not yet synced**. The last one is not an error — it means the work is safe locally and will sync. Announce transitions politely, but debounce the announcements; a screen reader reciting "Saving… Saved" on every keystroke pause is worse than silence.
- Flush on `visibilitychange` (hidden) and before page navigation, using `fetch` with `keepalive: true` and the normal `X-CSRF-TOKEN` header. **Do not design around `navigator.sendBeacon`**: it only sends POST and gives no control over headers, so it cannot carry the CSRF token to a PUT endpoint. Use `keepalive` consistently for both endpoints. There is no reliable synchronous unload save in any browser — `localStorage` is the durability mechanism when the request cannot finish, and the flush is a best-effort optimization on top of it.
- **On 409, stop autosaving that block and say so plainly**: the lesson is open somewhere else, and the page must be reloaded to continue. Do not silently pick a winner between the two states, and do not discard the student's local copy — surface it and let them reload. Same student, two tabs, is the realistic cause.

## 6. Time tracking

Two numbers answering two questions. Time to complete is derived (`started_at` → `completed_at`) and never stored separately. Active time is accumulated.

- The client ticks active time only while the document is visible **and** the last interaction was under five minutes ago. Pause on hidden, pause on idle, resume on either returning.
- Flush the accumulated delta every ~30 seconds and on visibilitychange/navigation, to `POST /player/attempts/{attempt}/activity` with `{ "active_seconds_delta": n }`.
- **The server adds the delta; the client never sends a total.** A stale or malicious client must not be able to overwrite a larger accumulated value. Reject a delta that is negative, non-integer, or larger than 300.
- Add it atomically in the database — never read the total, add in PHP, and save:

  ```php
  $attempt->newQuery()
      ->whereKey($attempt->id)
      ->increment('active_seconds', $delta, ['last_activity_at' => now()]);
  ```

  Addition commutes, so concurrent flushes are safe and this endpoint needs no attempt `revision`.
- Be honest in the comment about what the validation buys: delta limits prevent accidental and single-request inflation, but nothing here stops a student sending many individually plausible deltas. **Active time is approximate and is not tamper-proof in Phase 3A.** Do not describe it as if it were.
- Label it *active time* everywhere it surfaces, never "time spent." It is an approximation and the wording should admit it.

## 7. Continue and submission snapshots

Continue is already a page-level control the player renders. It now also persists.

```
POST /player/attempts/{attempt}/pages/{pageId}/continue
```

1. Authorize the attempt, require `in_progress`, and resolve the page from the pinned manifest.
2. **The client must have every block on the page acknowledged before it calls this.** In order: cancel outstanding debounce timers, submit every dirty block, await a successful acknowledgement for each one, and only then call Continue. While any block on the page is unsynced, retrying, or conflicted, Continue is refused client-side with the sync status shown — a *Not yet synced* block must not advance. Without that rule Continue reads stale database state while a save is still in flight and returns a false "page incomplete" error, which looks like a bug in the lesson rather than a network hiccup.

   The endpoint reads stored `block_states` as its source of truth and never accepts block state in the Continue payload — one write path for state, always validated the same way.
3. Confirm the page's completion rule is satisfied, server-side. Add one more contract method to make that possible without rebuilding the whole registry in PHP:

   ```php
   /** Whether stored state satisfies this block on its own terms. */
   public function isStateSatisfied(array $state, array $compiledConfig): bool;
   ```

   Default `true` in `AbstractBlockType` (content blocks impose nothing). `short_response` and `cer` check `min_length` against the **trimmed** value, with the same null-means-non-empty rule the client uses. `matching` and `image_labeling` check that every slot is filled.

   **Put the rule evaluation in a dedicated `app/Services/PageCompletionEvaluator.php`, not in the controller.** It mirrors `completion.js` exactly, by contributor category:

   - content/confirmation contributors — satisfied by default
   - response contributors — `isStateSatisfied()`
   - placement/activity contributors — `isStateSatisfied()`
   - gradable contributors — submission records, not state: `complete_activity` needs at least one recorded submission, `pass_activity` needs one with `passed = true`
   - every other completion rule — the same semantics `completion.js` already implements

   Return 422 with a translated message when unsatisfied.

   **Add parity tests that run the same scenarios as the existing JS completion tests against this evaluator.** Two implementations of one rule set will drift, and the parity suite is the only thing that catches it. Note in a comment that the JS copy owns the UX and this copy owns the record, and that a divergence is a bug in whichever one is wrong rather than something to reconcile at the call site. If you would rather trust the client's assertion here, stop and say so instead of guessing.

4. For each `short_response` and `cer` block on that page, create a `block_submissions` row **only if its stored state differs from its most recent submission's response.** Unchanged content creates nothing — that is what makes returning to a page cheap and keeps the submission history meaningful.
5. Assign `attempt_number` server-side as `max(attempt_number) + 1` for that attempt and block. **A transaction alone does not make this safe** — two requests can read the same maximum and both pick the same number. Lock the parent `lesson_attempts` row with `lockForUpdate()` before assigning any submission number, and use that same pattern everywhere a number is assigned (here and in quiz grading). The unique index on `(lesson_attempt_id, block_id, attempt_number)` stays as the final safeguard, not as the mechanism.
6. Advance `current_page_id` using the attempt's own `revision` for optimistic concurrency, and return the new revision plus the new `current_page_id`. The client advances only after this responds.
7. When the page is the last in the manifest, set `status = completed` and `completed_at`. A completed attempt rejects further state writes (section 4, step 2).

## 8. Quiz submissions persist

The grading endpoint keeps its five-key public response and its complete-submission validation. It now also records.

- **How it finds the attempt:** resolve the authenticated user's single `in_progress` attempt for that lesson, and 404 when there is none. **Never accept an attempt id from the request body** — the route identifies a lesson and a block, and the attempt is derived from the session. Lock that attempt with `lockForUpdate()` for the duration of recording, as in section 7.
- Wrap grading in a transaction: assign the next `attempt_number` for that block, store the submitted response and the **full internal** `grading_result` including `details[]`, denormalize `score` / `max_score` / `percentage` / `passed` / `requires_manual_review`, and stamp `active_seconds_at_submission`.
- The public response is unchanged — still exactly five keys. `details[]` now lives in the database for Phase 3B and teacher reporting, and still never reaches the student.
- `attempt_number` is server-assigned and authoritative. The client's local counter from 2C becomes display state only; it must not be sent and must not influence anything.
- Retry stays unlimited in this phase. `max_attempts` is stored and validated in the manifest already but is **not** enforced here — that is 3B, deliberately.
- Clear the quiz block's `block_states` row after a successful submission, or keep it, but pick one and comment why. Recommended: keep it, so a resumed student sees the selections they submitted rather than an empty quiz.

## 9. Seeded shuffles

The 2B and 2C `TODO Phase 3` comments come due: both the quiz question shuffle and the matching bank shuffle are unseeded today, so a reload reorders.

**The two shuffles live in different layers, and neither should move.** Verify before changing anything: the quiz shuffles server-side in Blade, while the matching bank shuffles in `placement-controller.js` — deliberately, because Alpine draws the bank so the shuffled order *is* the DOM order, which is what keeps tab order and reading order matching what a student sees. Moving that into PHP would undo a 2B accessibility decision.

So the requirement is outcome-based:

- Use the attempt's `shuffle_seed` to make matching-bank order and quiz-question order stable across reload and resume.
- Implement the deterministic shuffle **in the layer where each shuffle currently happens.** Do not move rendering between PHP and JavaScript for this task.
- Use the same documented algorithm in both: a Fisher-Yates driven by a small named PRNG, seeded from a hash of `shuffle_seed` and `block_id` so two shuffled blocks in one lesson never share a sequence. Document the algorithm once and implement it twice — a PHP helper and a JS helper — with a comment in each pointing at the other. Do not rely on `mt_srand` global state.
- The attempt seed has to reach the client for the JS shuffle; pass it through the same embedded restore payload as everything else.
- Remove both TODO comments. Test in each layer that the same seed and block id produce the same order twice, that two block ids produce different orders, and that the PHP and JS implementations agree on a shared fixture — otherwise the "same algorithm" claim is untested.

## 10. Tests

**PHP.** Attempt creation on first visit and reuse on second; a second concurrent open does not create two `in_progress` attempts; the player renders the pinned version after a republish, not the current one; the grading endpoint 422s when the token's version disagrees with the attempt's. State writes: happy path returns an incremented revision; a stale revision returns 409 with the current state; revision 0 against an existing row is a 409; another user's attempt is a 404; a completed attempt rejects writes; each stateful type rejects unknown ids and over-cap text; a non-stateful type is 422. Activity: deltas accumulate, negatives and oversized deltas are rejected, concurrent flushes both land. Continue: creates snapshots only for changed blocks, assigns sequential `attempt_number`s, 422s when the page rule is unsatisfied, advances `current_page_id`, and completes the attempt on the last page. Submissions are immutable — `update()` and `delete()` both throw. Quiz grading writes a submission whose `grading_result` contains `details[]` while the HTTP response still has exactly five keys.

**JS.** Debounce coalesces rapid edits into one write; the pending entry survives a simulated reload and is re-sent; a failed save retries with growing delay and the status reads *Not yet synced*; an acknowledged save clears the pending entry and the status reads *Saved*; a 409 stops that block's autosave and surfaces the conflict; revisions are tracked per block and a block's write carries only its own; active-time ticking pauses when hidden and after idle and resumes correctly; `restoreState()` on missing or malformed state leaves the component in its empty state rather than throwing.

**Also required, each targeting a specific correction above:**

- Revisiting a completed lesson renders the completed attempt read-only and does **not** create a second attempt.
- Two simultaneous first visits end with exactly one `in_progress` attempt, and both requests operate on it rather than one erroring.
- The manifest API returns the attempt's pinned version after a republish, not the newly published one.
- Two concurrent quiz submissions receive distinct sequential `attempt_number`s.
- Continue is refused while any block on the page is unsynced, and succeeds once all are acknowledged.
- Stored short-response whitespace round-trips exactly, while satisfaction is evaluated on the trimmed length.
- Activity flushes use an atomic increment: two concurrent deltas both land and the total is their sum.
- Matching bank order and quiz question order are identical after a full reload and resume, and the PHP and JS shuffle implementations agree on a shared fixture.

## 11. Acceptance

- `php artisan test` and `npx vitest run` fully green.
- `php artisan migrate:fresh --seed` works; sign in as the seeded student, work partway through WEL 6.1.1 including a placement and a CER field, close the browser, sign back in, and land on the same page with every answer intact.
- Republish the lesson mid-attempt and confirm the student stays on their pinned version — in the player **and** through the manifest API — while a brand-new attempt picks up the new one.
- Finish the lesson, then reload: the completed attempt renders read-only and no second attempt appears.
- Kill the network in devtools mid-typing: the status reads *Not yet synced*, typing continues unblocked, Continue is refused, and the work syncs when the network returns.
- The public grading response is still exactly five keys.
- No state-changing route is added to the API group, and `bootstrap/app.php` carries the comment explaining why.
