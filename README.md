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
- **Students never see authoring rows.** `GET /api/lessons/{code}` serves
  the current published manifest with answers, feedback, rubrics, and
  source references redacted. Drafts and archived lessons 404.
- **Stable IDs.** Pages, blocks, and every nested item a student response
  can reference (questions, options, terms, pairs, hotspots, bank items,
  CER fields) carry stable ULID string IDs. Reordering never changes them —
  use `LessonPage::reorderWithin()` / `LessonBlock::reorderWithin()`.

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

## Local setup

Requirements: PHP 8.2+, Composer, MySQL/MariaDB (developed against XAMPP
MariaDB 10.4).

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create two databases (dev + test), e.g. `teched` and `teched_test`, then set
the `DB_*` variables in `.env`. The test connection is configured in
`phpunit.xml` (`teched_test` by default).

```bash
php artisan migrate
php artisan db:seed        # builds + publishes WEL-6.1.1 "What Is Welding?"
```

Verify: `GET /api/lessons/WEL-6.1.1` returns the published, redacted
manifest.

Note: `.env.example` documents the production drivers (Redis for
queue/cache/session, S3 for files). For local development without Redis,
use `database` for those drivers and `local` for the filesystem.

## Running tests

Tests are written in [Pest](https://pestphp.com) and run against the
separate MySQL test database:

```bash
vendor/bin/pest
```

The suite covers the manifest JSON Schema contract, API redaction and 404
rules, version immutability and atomic publishing, block config
cross-validation, HTML sanitization policy, and the block type registry.

## Adding a new block type

No migration, publisher, API, or manifest-contract changes are needed —
only a new class plus registration:

1. Create `App\Blocks\Types\YourBlock` extending
   `App\Blocks\AbstractBlockType` (or implementing
   `App\Blocks\Contracts\BlockType`). Implement `key()`, `label()`, the
   capability flags, `configRules()`, `defaultConfig()`, and — as needed —
   `afterValidation()` for cross-field checks, `compileConfig()`,
   `redactConfig()` (strip anything answer-revealing), and `grade()` for
   auto-gradable types.
2. Add the class to the `BLOCK_TYPES` list in
   `App\Providers\BlockTypeServiceProvider`.
3. Add the key to the `App\Enums\BlockType` enum (and the JSON Schema's
   type enum plus a config definition, to keep the documented contract
   complete).

Block classes own **only their config**. The publisher always constructs
the `{ block_id, type, config, grading }` wrapper. An unregistered type
encountered during publish or read throws a descriptive exception rather
than being silently skipped.
