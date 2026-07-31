# TechEd — Self-Paced CTE Lesson Platform

A Laravel application for authoring and delivering self-paced K-12 CTE
(Career and Technical Education) lessons. Teachers author lessons as an
editable tree; publishing compiles that tree into an immutable JSON manifest
that the student player consumes.

## Core model

```
Lesson (status, current_version)
└── LessonPage (page_id, position, completion_type, settings)
    └── LessonBlock (block_id, type, config, grading)

publish() ──compiles──▶ LessonVersion (immutable manifest JSON)
```

- **Authoring rows are live and editable.** Editing any page or block flags
  the lesson as having unpublished changes (it never reverts to draft).
- **Publishing compiles.** `App\Services\LessonPublisher` validates every
  block, sanitizes all author HTML, compiles each block config, and stores
  the whole result as a manifest on a new `lesson_versions` row — inside one
  transaction with the lesson row locked. A failed publish leaves everything
  untouched.
- **Published versions are immutable.** Updating or deleting a
  `LessonVersion` model throws. Republishing creates the next version.
- **Students never see authoring rows.** `App\Services\StudentManifest`
  builds the one payload students receive: the current published manifest
  with answers, feedback, rubrics, and source references redacted, plus
  read-aloud speech per block. Both `GET /api/lessons/{code}` (JSON) and
  `GET /lessons/{code}` (the player) call it, so they cannot drift. Drafts
  and archived lessons 404 on both.
- **Stable IDs.** Pages, blocks, and every nested item a student response
  can reference (questions, options, terms, slots, hotspots, bank items,
  CER fields) carry stable ULID string IDs. Reordering never changes them —
  use `LessonPage::reorderWithin()` / `LessonBlock::reorderWithin()`.
- **Read-aloud is derived, not stored.** Each block gets plain-text speech
  segments from its type's `speakableText()`, computed from the redacted
  config so answers can never be spoken. Teachers can switch it off per
  page via `settings.allow_read_aloud`.

The manifest contract lives in
[`docs/schemas/lesson-manifest.schema.json`](docs/schemas/lesson-manifest.schema.json)
with a human-readable guide (including student response and grading shapes)
in [`docs/manifest-schema.md`](docs/manifest-schema.md).

## Block types

Twelve block types are registered in `App\Providers\BlockTypeServiceProvider`:

| Content | Collects response | Auto-graded |
| --- | --- | --- |
| rich_text, image, video, file_link, callout, static_table, vocabulary_cards | short_response, cer | matching, image_labeling, quiz |

Pages own progression and completion rules; blocks own content. Navigation,
progress, and results display are platform UI, never authored blocks.

## Student player

`GET /lessons/{code}` renders the player: one lesson page at a time, with a
header (title, page N of M, progress), the page's blocks, and Back /
Continue / Skip. The whole manifest is embedded once with Blade's `@js()`
and navigation happens client-side, so there is no client-side fetch.

The player is an Alpine component in `resources/js/lesson-player/`:

| File | Responsibility |
| --- | --- |
| `player.js` | the Alpine component: navigation, focus, gating, read-aloud wiring |
| `completion.js` | framework-agnostic page-completion registry and rules |
| `speech.js` | SpeechSynthesis controller, voice/rate preferences |
| `placement.js` | framework-agnostic placement state machine (bank, slots, selection) |
| `placement-controller.js` | the Alpine wrapper: announcements, focus, drag ids, shuffle |

- **Renderers resolve by convention.** A block of type `static_table` is
  drawn by `resources/views/lesson-player/blocks/static_table.blade.php`.
  There is no switch statement. A type with no partial is logged with its
  `block_id` and type, and the student sees a neutral placeholder.
- **Completion is contributed, not hard-coded.** A renderer may register
  contributors (`confirmation`, `response`, `activity`, `gradable`) with the
  registry; the page's `completion_type` decides which categories it weighs.
  Content blocks register none, so a page of prose is complete once shown; a
  video with `require_confirmation` registers one. Continue is gated until
  the rule is satisfied and names the first thing still outstanding.
- **Finishing and passing are different questions.** A contributor answers
  `isSatisfied()` ("has the student done this?") and, if it is gradable,
  `isPassed()` ("did they meet the bar?"). Only `pass_activity` asks the
  second, so a submitted-but-failing quiz completes a `complete_activity`
  page but not a `pass_activity` one. A gradable contributor must implement
  both: without `isPassed()`, it falls back to `isSatisfied()` and a failing
  score would slip through. Until grading exists, the placement activities
  answer `isPassed()` with `false` and warn if a page asks for
  `pass_activity`, rather than reporting a pass they cannot judge.
- **State is in memory only.** No persistence, resume, or attempts (Phase
  3). The sole exception is read-aloud preferences, under
  `lesson_player.speech.rate` and `lesson_player.speech.voice_uri`.
- **Read-aloud highlights in place.** Each renderer marks the element for
  each speech segment with `data-speech-id`; the player toggles a class and
  `aria-current` on the segment being spoken. For `rich_text`,
  `App\Services\RichTextSegmenter` both tags the sanitized HTML server-side
  and produces the segments, so the two always line up one to one.

### Placement activities

`matching` and `image_labeling` are both placement problems — items from a
bank go into slots — so one state machine (`placement.js`) drives both and
one Alpine controller wraps it. Matching's slots are description rows;
image labeling's are diagram markers.

- **Three ways in, one model.** Choose-then-choose (tap or click), full
  keyboard, and mouse drag all call the same operations. Every item and slot
  is a real `<button>`, so `Enter` and `Space` come from the browser rather
  than a keydown handler of ours, and `Escape` cancels a selection and
  returns focus to the item it came from. Selection is `aria-pressed`; nothing
  uses the deprecated `aria-grabbed`.
- **Announced, not just shown.** Pick up, place, move, displace, return,
  cancel, reset, and completion each announce into a polite live region.
  Strings come from `lang/en/placement.php` and are localized server-side,
  so the JavaScript holds no English.
- **Image labeling always has two layers.** The diagram's hotspots are
  percentage-positioned buttons; beneath it, a numbered list carries the same
  slot IDs. Either layer updates the other immediately, focus advances within
  whichever layer is in use, and if the image fails to load the visual layer
  hides itself while the list remains a complete path.
- **Finished is not correct.** Completion means every slot is filled. There
  is no correctness checking here, and the redacted manifest carries no
  answer mapping for one to use.

Phase 2A–2C ship the full player (content blocks, placement activities,
response blocks, and the version-bound grading endpoint). Phase 2D adds
local session authentication so those routes require a signed-in user.
Google sign-in arrives in Phase 6 on the same `users` rows via `google_id`.

## Staff authoring (Filament)

Teachers and admins author lessons at `/admin` using
[Filament](https://filamentphp.com) (Livewire). That stack is intentional for
**staff authoring on a desk machine** — it is not a reversal of the Alpine/Vite
student player, which stays free of Livewire because school wifi latency makes
round-trips painful for interactions. Publishing still goes through
`LessonPublisher` and writes immutable `lesson_versions`; the student player is
unchanged.

**New staff surfaces go in Filament** (classes, rosters, assignments at
`/admin`). Phase 4B’s `/staff` progress views stay where they are until there
is a reason to move them — consolidation is directional, not a rewrite of the
intervention screens.

## Local setup

Requirements: PHP 8.2+, Composer, MySQL/MariaDB (developed against XAMPP
MariaDB 10.4).

Also required for the player: Node 20+ and npm.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan storage:link   # required for teacher-uploaded lesson assets
```

Create two databases (dev + test), e.g. `teched` and `teched_test`, then set
the `DB_*` variables in `.env`. The test connection is configured in
`phpunit.xml` (`teched_test` by default).

```bash
php artisan migrate
php artisan db:seed        # users + WEL-6.1.1 "What Is Welding?"
npm run build              # or: npm run dev
```

Production (and any box where `UserSeeder` refuses) creates the first staff
account with:

```bash
php artisan teched:create-staff-user
```

Prefer the interactive password prompt (`secret()`). `--force` only allows
non-interactive runs; it never overwrites an existing email. Blank password
creates a Google-provisioned account awaiting first Workspace sign-in.

### Deployment notes (lesson asset uploads)

Teacher uploads land on the Laravel `public` disk (`storage/app/public`) and
are served at `/storage/...` via the `storage:link` symlink. Without that
link, authoring looks fine but every uploaded asset 404s for students.

Seeded fixtures under `public/lessons/` (for example the WEL diagram) stay in
the repo; uploads are user data under `storage/app/public/lessons/{lesson uuid}/`
and are never committed.

Caps live in `config/lesson-assets.php` (defaults: images 5 MB, documents
20 MB). PHP `upload_max_filesize` and `post_max_size`, any reverse-proxy body
limit, and Livewire's temporary upload max (raised in `AppServiceProvider` to
match the document cap) must all be **at or above** the document cap —
otherwise the framework rejects the file before the app can return a clear
validation message.

Seeded development accounts (password for all: `password`):

| Email | Role |
| --- | --- |
| `admin@teched.test` | admin |
| `teacher@teched.test` | teacher (publishes WEL-6.1.1) |
| `student1@teched.test` | student |
| `student2@teched.test` | student |

Sign in at `/login`, then open `/lessons/WEL-6.1.1`. The JSON manifest at
`/api/lessons/WEL-6.1.1` also requires an authenticated session.

Session lifetime defaults to 60 minutes (see `SESSION_LIFETIME` in
`.env.example`) for shared Chromebook carts. `SESSION_EXPIRE_ON_CLOSE` stays
false so a closed lid does not force a re-login before Phase 3 resume exists.
`.env.example` uses `SESSION_DRIVER=database` and `SESSION_COOKIE=teched_session`
so a fresh copy works without Redis and does not collide with other Laravel
apps on `localhost`.

Timestamps are stored in UTC (`APP_TIMEZONE=UTC`) and shown in Eastern
(`APP_DISPLAY_TIMEZONE=America/New_York`). Do not set the app timezone to
Eastern — that would persist local wall-clock times.

After changing environment values on a deployed box, run
`php artisan config:cache` so the new values take effect.

## Running tests

There are two suites: [Pest](https://pestphp.com) for PHP (run against the
separate MySQL test database) and [Vitest](https://vitest.dev) for the
player's framework-agnostic JavaScript.

```bash
php artisan test        # PHP — or: vendor/bin/pest
npm run test            # JavaScript
composer test:all       # both, in sequence
```

The PHP suite covers the manifest JSON Schema contract, redaction and 404
rules shared by the API and the player, version immutability and atomic
publishing, block config cross-validation, HTML sanitization policy, the
block type registry, speech extraction, and the player's rendering. It also
feeds each auto-graded type's redacted config back into `grade()` with a
response guessed from that config alone, so a leak in the answer key shows up
as a score above zero rather than as a missing key name.

The JavaScript suite covers the completion registry (every page rule
against every contributor category), the player component's navigation and
Continue gating, and the placement state machine and its controller.

## Adding a new block type

No migration, publisher, API, or manifest-contract changes are needed —
only a new class plus registration:

1. Create `App\Blocks\Types\YourBlock` extending
   `App\Blocks\AbstractBlockType` (or implementing
   `App\Blocks\Contracts\BlockType`). Implement `key()`, `label()`, the
   capability flags, `configRules()`, `defaultConfig()`,
   `speakableText()` (return `[]` if there is nothing to read aloud), and —
   as needed — `afterValidation()` for cross-field checks,
   `compileConfig()`, `redactConfig()` (strip anything answer-revealing),
   and `grade()` for auto-gradable types.
2. Add the class to the `BLOCK_TYPES` list in
   `App\Providers\BlockTypeServiceProvider`.
3. Add the key to the `App\Enums\BlockType` enum (and the JSON Schema's
   type enum plus a config definition, to keep the documented contract
   complete).
4. Add `resources/views/lesson-player/blocks/your_block.blade.php` to render
   it. Mark the element for each speech segment with
   `data-speech-id="{segment id}"`, and register a completion contributor
   from `x-init` if the block must be finished before a student continues.

Block classes own **only their config**. The publisher always constructs
the `{ block_id, type, config, grading }` wrapper. An unregistered type
encountered during publish or read throws a descriptive exception rather
than being silently skipped.
