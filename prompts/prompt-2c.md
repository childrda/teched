# Phase 2C — short_response, cer, quiz, and the stateless grading endpoint

Phase 2A shipped the player shell and seven content blocks. Phase 2B shipped the two placement activities. This phase adds the last three MVP blocks and the one server endpoint the player needs.

Nothing in this phase persists. There is no attempt record, no student response row, and no auth. A reload loses a student's work, and that is expected — Phase 3 adds persistence, authenticated attempts, and authoritative retry limits.

Accessibility is acceptance criteria here, not a later pass.

---

## 1. Version binding: a signed grading token

A student's quiz must be graded against the manifest their browser actually rendered. If a teacher publishes a revision while a student has the lesson open, grading against whatever version is now current would apply a new answer key to an old quiz UI.

`lesson_versions` has no public identifier column — only an autoincrement `id` and a per-lesson `version` integer — and the manifest's top-level `version` is that same integer. Rather than add a column, issue an opaque signed token. Laravel's encrypter is authenticated, so a token cannot be forged or edited.

1. Add a small service, `app/Services/GradingToken.php`:

   - `issue(LessonVersion $version): string` — encrypts a payload of `['lesson_id' => ..., 'version_id' => ...]` via `Crypt::encryptString(json_encode(...))` (or `Crypt::encrypt()` with an array).
   - `resolve(string $token, Lesson $lesson): LessonVersion` — decrypts, validates, and returns the `LessonVersion`.

   Add `app/Exceptions/InvalidGradingToken.php`. `resolve()` catches or normalizes **every** failure mode into that one exception and throws nothing else:

   - `DecryptException`
   - invalid JSON
   - missing or non-integer `lesson_id` / `version_id`
   - no `LessonVersion` row for that id — use `find()`, not `findOrFail()`
   - a version belonging to a different lesson than the one passed in

   This is the point of the single type: an unknown `version_id` escaping as a `ModelNotFoundException` would surface as a distinguishable 404, and the difference between "forged" and "real but unknown" is exactly what must not be observable.

2. `StudentManifest::forLesson()` already builds the payload both the web player and the JSON API consume, so it is where the token belongs — that is what keeps the two from drifting. Add a top-level `grading_token` key to the returned array, issued from the version it just resolved.

   Leave every other manifest key alone. Do not add the version's database id, and do not expose it anywhere in the payload.

3. Deliberate property, and the intended one: a token is not time-limited and is not bound to a session, so a student holding an old token is graded against the old version. That is grading consistency working as designed. No TTL in this phase.

## 2. The grading endpoint

Route, in `routes/web.php` beside the existing player route:

```php
Route::post('/player/lessons/{code}/blocks/{blockId}/grade', GradeBlockController::class)
    ->middleware('throttle:30,1')
    ->name('player.blocks.grade');
```

The throttle is defence in depth, not the mitigation — see section 6.

`app/Http/Controllers/GradeBlockController.php`, a single-action controller. In order:

1. Find the lesson by `code`; 404 if missing, or if `StudentManifest::forLesson()` would return null (unpublished or archived). Reuse that check rather than re-deriving the availability rule.
2. Resolve the `LessonVersion` from the request's `version_token` through `GradingToken`. On any failure, 422 with a generic message.
3. Find the block by `blockId` in **that version's compiled manifest** — the unredacted one from the version row, never a redacted copy and never the live authoring tables.

   The manifest has no top-level block collection. Blocks live under pages, so search `manifest.pages[*].blocks[*]` for an exact `block_id` match, traversing every page. Compare with `===` on strings so PHP's numeric coercion cannot make one id match another.
4. 404 if no block in that manifest has the id. The block must belong to this lesson's version; a valid token plus another lesson's block id is a 404.
5. 422 if the block type's `isAutoGradable()` is false. Gradability is already public — the block type is in the manifest — so this leaks nothing.
6. Validate the response payload (section 3). 422 on any failure.
7. Grade by calling the block type's existing `grade($compiledConfig, $grading, $response)`. Do not reimplement grading and do not change `buildGradingResult()`.
8. Map the internal result to the student-safe response (section 4) and return it as JSON.

The request body is exactly:

```json
{
  "version_token": "<opaque>",
  "response": { "question-1": "option-3" }
}
```

The client sends nothing else. No block config, no answer ids, no grading rule, no threshold, no score, and no attempt number. If a future block type needs a differently shaped `response`, that shape is the block's business; the envelope stays this.

The player page is a web route, so CSRF applies: add `<meta name="csrf-token" content="{{ csrf_token() }}">` to `resources/views/lesson-player/show.blade.php` and send it as an `X-CSRF-TOKEN` header.

## 3. Complete submissions only

A missing answer grades as incorrect against a fixed `max_score`, so a payload containing one question would return a score of 1 or 0 and isolate that question's answer exactly. Partial payloads are therefore rejected, not graded.

For a quiz, the set of keys in `response` must equal the set of question ids in the compiled config, exactly:

- a missing question id → 422
- an unknown question id → 422
- an extra entry beyond the questions → 422
- a value that is not one of that question's own option ids → 422
- an empty or absent `response` → 422

`response` is a JSON object keyed by question id, so a question cannot appear twice; "exactly one response per question" is set equality on the keys. Do not write duplicate handling.

Validation messages may reference structure — which question is missing, that an option id is unknown — because question and option ids are already in the redacted manifest. They must never reference correctness, and no 422 may vary depending on whether a submitted option happens to be the right one.

Put this in a `FormRequest` or a dedicated validator that reads the compiled config. Keep it in one place; do not scatter checks through the controller.

**The token is validated separately from the response, and never by a normal rule.** A `required|string` rule would give an absent token a different body and error key than a forged one, which is the distinction section 1 exists to remove. Every token failure — absent, non-string, empty, malformed, forged, unknown version, wrong lesson — goes through the `InvalidGradingToken` path and returns the identical 422:

```json
{ "message": "The grading session is invalid." }
```

Read `version_token` from the request without validating it, hand whatever is there to `GradingToken::resolve()`, and let that one exception render. Quiz-response validation errors stay specific and keep normal Laravel validation shape.

## 4. Two grading results: internal and public

Keep `buildGradingResult()` exactly as Phase 1 defined it. It stays the internal result, `details[]` included, and Phase 3 persists it for teacher reporting.

Add a plain mapper — a small `app/Support/StudentGradingResult.php` returning an array, called as `response()->json($mapper->map($result))`. Do **not** use `JsonResource`: it wraps output in a `data` key by default, which breaks the exact-five-keys contract. This response is deliberately tiny and does not need a resource class.

The mapper produces the **only** thing the endpoint returns:

```json
{
  "score": 7,
  "max_score": 10,
  "percentage": 70,
  "passed": false,
  "requires_manual_review": false
}
```

Five keys. No more.

The public response must never carry `details`, per-item correctness, authored feedback, `answer_id`, option ids, `correct`, or anything derived from them. The seeded WEL 6.1.1 feedback is explanatory — it states the correct concept in prose — so returning per-item feedback would hand over the answer key, and returning it only for wrong items would mark correctness by its presence. Neither goes out in this phase.

Authored feedback and item correctness arrive in Phase 3, gated behind passing or the final permitted attempt, with answer reveal as its own author-controlled setting.

## 5. The three block renderers

All three follow the 2A/2B conventions: a convention-resolved Blade partial under `resources/views/lesson-player/blocks/`, one Alpine component, page gating through the completion registry, and every student-facing string translated (`lang/en/`, alongside `placement.php`).

### short_response

- Config: `prompt_html`, `placeholder`, `min_length`. `rubric_html` is stripped by `redactConfig()` and is teacher-only — never render it, and add a test that proves it is absent from the page.
- One `<textarea>` with a real `<label for>`, `placeholder` when set, and the length requirement associated via `aria-describedby`.
- A visible characters-remaining hint. Plain text that re-renders, **not** a live region: an announcement on every keystroke is unusable with a screen reader.
- Speech segment id: `prompt`.
- Completion contributor: category `response`. `isSatisfied()` is true when the trimmed value meets `min_length`; when `min_length` is null, a non-empty trimmed value is required. A response block always asks for something.

### cer

- Config: `scenario_html` and `fields[]`, each with `id`, `label`, `placeholder`, `min_length`.
- The scenario, then one labelled textarea per field, each with its own hint and `aria-describedby`, following the short_response pattern.
- Speech segment ids: `scenario`, then `<field_id>:label` per field.
- Completion contributor: category `response`, one per block, satisfied when **every** field meets its own requirement under the same null rule as above.

### quiz

- Config after redaction: `questions[]` with `id`, `prompt`, `options[]` (`id`, `text`), plus `shuffle_questions`. No `answer_id`, no `feedback`, no `source_ref` — they are stripped at redaction, and this renderer has no idea what the answers are.
- Each question is a `<fieldset>` with the prompt as its `<legend>` and native radio inputs sharing a name unique per block and question. Native radios give correct keyboard behaviour and grouping for free; do not build a custom widget.
- Speech segment ids, matching `QuizBlock::speakableText()`: `<question_id>:prompt` and `<question_id>:<option_id>`.
- Submit is always enabled. On an incomplete quiz it does not fire a request; it announces the translated "answer every question" message and moves focus to the first unanswered question. A disabled button with no stated reason is the worse pattern.
- While a request is in flight: disable submit, show a pending state, and announce it politely.
- On success, show `score` / `max_score`, the percentage, and pass or fail. Convey pass state in words and a symbol, never colour alone. Announce the result in the block's live region.
- On a network or server error, show a translated failure message with a retry affordance, and leave attempt state untouched.
- Answers are **kept** after grading so a student can change one and resubmit.
- `shuffle_questions` has the same unseeded-shuffle limitation as the 2B bank: a reload reorders. Mirror the existing `TODO Phase 3` comment rather than inventing a fix.
- Completion contributor: category `gradable`. `isSatisfied()` is true once a grading request has completed. `isPassed()` returns `latestResult?.passed === true`. Both are required — registration rejects a gradable contributor without `isPassed`.

### Local attempt state

The quiz component tracks, in memory only:

```js
{ attemptCount: 0, firstResult: null, latestResult: null }
```

These change on one condition only: a **successful HTTP 200 response whose body is the expected five-key result**. Then increment `attemptCount`, set `firstResult` if it is still null, and replace `latestResult`.

Everything else leaves all three untouched and is surfaced as an error: 422, 404, 419, 429, any 5xx, a network failure, malformed JSON, or a 200 whose body is not the expected shape. Validate the shape before trusting it — a 200 is not by itself a graded attempt.

This is UI state and Phase 3 forward-compatibility. It is never sent to the server, and it must not influence authorization, retry availability, or what the endpoint returns. Retries are unlimited in this phase, including on a `pass_activity` page.

## 6. What this does and does not protect

State this plainly in a comment on the controller, so the next person does not over-trust it.

Aggregate-only scoring plus complete-submission validation stops a single request from revealing per-question results or the explanatory feedback. It does **not** make the endpoint unprobeable: a student can change one answer between complete submissions and read the answer off the score moving by one, recovering the key in roughly questions × options requests. The throttle raises the cost further and nothing more.

Real protection is Phase 3: persisted attempts, an author-configured retry limit, and reveal only on passing or the final permitted attempt.

## 7. Tests

**PHP — endpoint.** The response shape tests matter most:

- A valid complete submission returns HTTP 200 and a body whose key set is **exactly** the five public keys.
- The response body contains none of: `details`, `correct`, `feedback`, `answer_id`, `source_ref`.
- Assert against the real seeded lesson that no authored feedback sentence from `WeldingLessonSeeder` appears anywhere in the response.
- Each 422: missing question, unknown question id, extra entry, unknown option id, empty response, absent `response`.
- A non-auto-gradable block id (short_response, cer, matching, image_labeling) → 422.
- An unknown block id → 404. A block id belonging to a different lesson → 404.
- A garbage token, a token encrypted for a different lesson, an absent token, and a non-string token → 422, all with byte-identical bodies.
- A structurally valid token whose `LessonVersion` row no longer exists → the same generic 422, not a 404 and not an exception page.
- Unpublished and archived lessons → 404.

**PHP — version binding.** The regression this whole section exists for: publish v1, capture its token, publish v2 whose answer key differs, then submit v1's token and assert the score reflects v1's key.

**PHP — renderers.** Each of the three renders on the player page. `rubric_html` does not appear in the page source. No seeded quiz feedback string appears in the page source. Every id returned by each block type's `speakableText()` has a matching `data-speech-id` in the rendered markup — a single test driven off the registry catches drift in all three at once.

**JS.** Quiz: incomplete submit fires no request and announces; the request body contains exactly `version_token` and `response`; `attemptCount` / `firstResult` / `latestResult` transitions across two successful submissions; a failed request leaves all three untouched; a 200 whose body is not the five-key result is treated as an error and leaves `attemptCount`, `firstResult`, and `latestResult` unchanged; `isSatisfied` false before grading and true after; `isPassed` follows `latestResult.passed`. Response blocks: satisfaction at, below, and above `min_length`, and the non-empty rule when `min_length` is null.

## 8. Acceptance

- `php artisan test` and `npx vitest run` fully green.
- `php artisan migrate:fresh --seed` still works; WEL 6.1.1 renders end to end and its quiz grades through the endpoint.
- No migrations added or amended in this phase.
- `buildGradingResult()` unchanged.
- Keyboard-only pass through all three blocks: reach every control, answer a quiz, submit, read the result, retry.
- Nothing outside the block renderers, their Alpine components, the new controller, request/validator, token service, mapper, `StudentManifest`, `show.blade.php`, `routes/web.php`, lang files, and tests. If a change seems to need more, stop and say so rather than doing it.
