# Lesson Manifest Schema

This document describes the **compiled manifest** produced by
`App\Services\LessonPublisher` and stored on a `lesson_versions` row. The
formal contract lives in [`schemas/lesson-manifest.schema.json`](schemas/lesson-manifest.schema.json);
this file is the human-readable companion.

Key facts:

- A lesson is **authored** as live rows (`lessons` → `lesson_pages` →
  `lesson_blocks`). **Publishing compiles** that tree into an immutable JSON
  manifest. Students only ever consume a published manifest.
- Every published version is immutable. Editing and republishing creates the
  next version.
- `page_id`, `block_id`, and every nested item ID (questions, options, terms,
  pairs, hotspots, bank items, CER fields) are **stable string IDs** (ULIDs at
  creation). Reordering never changes a stable ID; student responses reference
  these IDs.
- All author-supplied HTML fields (`html`, `transcript_html`, `prompt_html`,
  `rubric_html`, `scenario_html`) are sanitized during compile. Only sanitized
  markup is ever stored in a manifest.
- The student API (`GET /api/lessons/{code}`) additionally passes every block
  config through its type's `redactConfig()`, removing `answer_id`,
  `feedback`, `rubric_html`, and `source_ref` before the payload leaves the
  server. The stored manifest itself (documented here) still contains them.

## Top level

```json
{
  "schema_version": 1,
  "code": "WEL-6.1.1",
  "title": "What Is Welding?",
  "version": 1,
  "estimated_minutes": 45,
  "learning_target": "Explain what welding is and why engineers use it.",
  "success_criteria": ["I can define welding.", "I can name a welding hazard."],
  "pages": [ /* page objects, ordered by position */ ]
}
```

## Page

Pages own progression and completion rules; blocks own content. Navigation,
progress indicators, and results display are platform behavior, never
authored blocks.

```json
{
  "page_id": "01JD2W5R7NXKQ8ZC3VMB4T9AEF",
  "title": "Welcome",
  "position": 1,
  "completion_type": "view",
  "estimated_minutes": 3,
  "settings": {
    "minimum_score": null,
    "require_all_blocks": false,
    "allow_back_navigation": true,
    "allow_skip": false,
    "show_in_nav": true,
    "allow_read_aloud": true
  },
  "blocks": [ /* block objects, ordered by position */ ]
}
```

`completion_type` is one of `view`, `submit_required`, `complete_activity`,
`pass_activity`, `confirm_video`.

`allow_read_aloud` controls whether text-to-speech is offered on this page,
so a teacher can switch it off where an IEP distinguishes instruction from
reading assessment. It is **already resolved** at compile time — exactly one
boolean per page — and the player performs no further resolution. The
authoring-side `lessons.settings.default_allow_read_aloud` only seeds this
value when a page is first created and never appears in a manifest.

## Block wrapper

The publisher owns this wrapper; block type classes only produce `config`.

```json
{
  "block_id": "01JD2W5R7P4GJXW8KQZC3VMB4T",
  "type": "quiz",
  "config": { /* per-type shape below */ },
  "grading": {
    "rule": "min_score",
    "min_score": 80,
    "allow_retry": true,
    "max_attempts": 3,
    "show_feedback": true,
    "record_first_attempt": true,
    "points": 10
  }
}
```

`grading` is `null` for ungraded blocks and has this identical shape for every
gradable block. `rule` is one of `all_correct`, `min_score`,
`completion_only`; `min_score` is a percentage (0–100).

## Block capability matrix

| type              | collects response | auto-gradable |
| ----------------- | ----------------- | ------------- |
| rich_text         | no                | no            |
| image             | no                | no            |
| video             | no                | no            |
| file_link         | no                | no            |
| callout           | no                | no            |
| static_table      | no                | no            |
| vocabulary_cards  | no                | no            |
| matching          | yes               | yes           |
| image_labeling    | yes               | yes           |
| quiz              | yes               | yes           |
| short_response    | yes               | no (manual)   |
| cer               | yes               | no (manual)   |

## Config shapes (one example per type)

### rich_text

```json
{ "html": "<h2>Welcome</h2><p>Let's get started.</p>" }
```

### image

`url` (and `image_url` on image_labeling) accepts an absolute http/https URL
**or** a root-relative path beginning with `/` (uploaded assets are served
from `/storage/...`). Protocol-relative (`//`) values and `javascript:`/
`data:` schemes are rejected at validation.

```json
{
  "url": "https://cdn.example.com/welding-mask.jpg",
  "alt": "A welder wearing a protective mask",
  "caption": "Always wear protective gear.",
  "long_description": null
}
```

### video

```json
{
  "provider": "youtube",
  "video_id": "abc123XYZ",
  "title": "Welding Basics",
  "instructions": "Watch for the three main weld types.",
  "focus_questions": [
    { "id": "01JD2WFQ01", "text": "What metals can be welded?" }
  ],
  "require_confirmation": true,
  "captions_available": true,
  "transcript_html": "<p>Today we look at welding…</p>"
}
```

### file_link

```json
{
  "url": "https://cdn.example.com/shop-safety.pdf",
  "label": "Shop Safety Checklist",
  "description": "Print and keep at your station.",
  "opens_in_new_tab": true
}
```

### callout

```json
{
  "style": "warning",
  "heading": "Safety First",
  "html": "<p>Never look directly at an arc without a shield.</p>"
}
```

`style` is one of `info`, `warning`, `tip`.

### static_table

```json
{
  "caption": "Common weld types",
  "headers": ["Type", "Best for"],
  "rows": [
    ["MIG", "Beginners, thin metals"],
    ["TIG", "Precision work"]
  ]
}
```

Every row has exactly as many cells as there are headers.

### vocabulary_cards

```json
{
  "terms": [
    {
      "id": "01JD2WGA10",
      "term": "Weld",
      "definition": "A joint made by fusing materials together.",
      "analogy": "Like melting two crayons into one."
    }
  ],
  "reveal_mode": "tap"
}
```

### matching

```json
{
  "instructions": "Match each term to its description.",
  "pairs": [
    { "id": "01JD2WHB20", "label": "Flux", "description": "Cleans the metal as you weld" },
    { "id": "01JD2WHB21", "label": "Slag", "description": "Waste material left on a weld" }
  ],
  "shuffle": true
}
```

### image_labeling

Coordinates are **percentages** (0–100 inclusive), never pixels.

```json
{
  "image_url": "https://cdn.example.com/welding-diagram.png",
  "image_alt": "Cross-section diagram of a weld",
  "long_description": "The diagram shows the torch, filler rod, and weld pool.",
  "instructions": "Drag each label onto the matching numbered point.",
  "hotspots": [
    {
      "id": "01JD2WJC30",
      "number": 1,
      "x_pct": 42.5,
      "y_pct": 18.0,
      "answer_id": "01JD2WJC90",
      "description": "The topmost component"
    }
  ],
  "bank": [
    { "id": "01JD2WJC90", "label": "Torch" },
    { "id": "01JD2WJC91", "label": "Weld pool" }
  ]
}
```

Every hotspot's `answer_id` references an item in this block's `bank`;
hotspot `number`s are unique within the block.

### short_response

```json
{
  "prompt_html": "<p>Describe one situation where welding beats bolting.</p>",
  "placeholder": "Type your answer…",
  "min_length": 50,
  "rubric_html": "<p>Full credit: names a situation and justifies it.</p>"
}
```

### cer

```json
{
  "scenario_html": "<p>A bike frame cracked at a joint. The shop must decide how to repair it.</p>",
  "fields": [
    { "id": "claim", "label": "Claim", "placeholder": "State your position…", "min_length": 20 },
    { "id": "evidence", "label": "Evidence", "placeholder": "What supports it?", "min_length": 30 },
    { "id": "reasoning", "label": "Reasoning", "placeholder": "Why does the evidence support the claim?", "min_length": 30 }
  ]
}
```

### quiz

```json
{
  "questions": [
    {
      "id": "01JD2WKD40",
      "prompt": "What does welding do?",
      "options": [
        { "id": "01JD2WKD41", "text": "Fuses materials together" },
        { "id": "01JD2WKD42", "text": "Glues materials together" }
      ],
      "answer_id": "01JD2WKD41",
      "feedback": "Welding melts and fuses the base materials themselves.",
      "source_ref": { "page": "Reading", "excerpt": "Welding fuses materials by melting them." }
    }
  ],
  "shuffle_questions": false
}
```

Every question's `answer_id` references one of that question's own options.

## Read-aloud (text-to-speech)

Read-aloud speech is **derived at read time, never stored in the manifest** —
adding it required no migration and no `schema_version` change. The student
API adds a `speech` array to every block, alongside (not inside) `config`:

```json
{
  "block_id": "01JD2W5R7P4GJXW8KQZC3VMB4T",
  "type": "quiz",
  "config": { /* redacted */ },
  "grading": { /* ... */ },
  "speech": [
    { "id": "01JD2WKD40:prompt", "label": "Question 1", "text": "What does welding do?" },
    { "id": "01JD2WKD40:01JD2WKD41", "label": "Option A", "text": "Fuses materials together" },
    { "id": "01JD2WKD40:01JD2WKD42", "label": "Option B", "text": "Glues materials together" }
  ]
}
```

- `text` is plain text: all markup stripped, entities decoded, whitespace
  collapsed. Inline tags (`strong`, `em`, `a`, …) are removed without
  splitting words; block tags become spaces.
- `label` is an optional spoken lead-in. Players should speak
  `"<label>: <text>"` when a label is present, and `text` alone otherwise.
- `id` is stable and unique within the block, derived from the item's stable
  ID where one exists, so a player can address or resume a single segment.
- Blocks with nothing to read return an empty list (`file_link`).
- When a page has `settings.allow_read_aloud: false`, every block on that
  page returns `speech: []`. Suppression is enforced server-side, so the
  text is not merely hidden by the client.

Segments come from each block type's `speakableText()`, which the API calls
on the **redacted** config only. An `answer_id`, `feedback` string,
`rubric_html`, or `source_ref` is therefore structurally incapable of
reaching a speech segment.

What each type reads:

| type | segments |
| --- | --- |
| rich_text | the text |
| callout | the text, with the heading as its label |
| image / image_labeling | alt text, then long description |
| video | title, instructions, then focus questions — never the video or its transcript |
| static_table | caption, then each row linearized as `"<row header>. <column header>: <cell>. <column header>: <cell>."` |
| vocabulary_cards | term, definition, then analogy, per card |
| matching | instructions, then all terms, then all descriptions as separate groups |
| quiz | each question prompt, then `Option A`, `Option B`, … |
| short_response | the prompt (never the rubric) |
| cer | the scenario, then each field label |
| file_link | none |

## Student responses and grading

The following applies to every block type where `collectsResponse()` is true.
The **standard grading result** shape (returned by `grade()` for
auto-gradable types, and produced later by manual review for the others) is:

```json
{
  "correct": false,
  "score": 2,
  "max_score": 3,
  "percentage": 67,
  "passed": true,
  "requires_manual_review": false,
  "details": [
    { "item_id": "01JD2WKD40", "correct": true, "feedback": null }
  ]
}
```

- `correct` is true only when every item is correct.
- `percentage` is `round(score / max_score * 100)` as an integer.
- `passed` depends on `grading.rule`: `all_correct` requires every item
  correct; `min_score` requires `percentage >= grading.min_score`;
  `completion_only` passes on submission.
- When `grading.show_feedback` is false, every `details[].feedback` is null.

### quiz (auto-graded)

Response shape — one entry per question; **an unanswered question is
represented as `null`** (the key is still present):

```json
{ "answers": { "01JD2WKD40": "01JD2WKD41", "01JD2WKD50": null } }
```

Scoring: each question is one item. An item is correct when the chosen
option ID equals the question's `answer_id`; `null` (unanswered) is
incorrect. `max_score` = number of questions. `details[].feedback` carries
the question's authored feedback (subject to `show_feedback`).

### matching (auto-graded)

Response shape — keys are slot pair IDs, values are the chosen pair ID or
`null` when the slot was left unmatched:

```json
{ "matches": { "01JD2WHB20": "01JD2WHB20", "01JD2WHB21": null } }
```

Scoring: each pair is one item, correct when the chosen pair ID equals the
slot's own pair ID. Unmatched slots (`null`) are incorrect. `max_score` =
number of pairs.

### image_labeling (auto-graded)

Response shape — keys are hotspot IDs, values are the placed bank item ID or
`null` when no label was placed:

```json
{ "placements": { "01JD2WJC30": "01JD2WJC90", "01JD2WJC31": null } }
```

Scoring: each hotspot is one item, correct when the placed bank item ID
equals the hotspot's `answer_id`. Empty hotspots (`null`) are incorrect.
`max_score` = number of hotspots.

### short_response (manual review)

Response shape:

```json
{ "text": "I would weld the frame because…" }
```

`grade()` returns `null` — the platform records the response and marks the
attempt `requires_manual_review: true` with `score`/`percentage` pending
teacher review against the (never student-visible) `rubric_html`. An
unanswered block is an empty string `""`.

### cer (manual review)

Response shape — one entry per field; **an unanswered field is the empty
string `""`** (the key is still present):

```json
{
  "fields": {
    "claim": "The frame should be welded.",
    "evidence": "Welds restore full-strength joints.",
    "reasoning": ""
  }
}
```

`grade()` returns `null`; scoring is manual, as with `short_response`.
