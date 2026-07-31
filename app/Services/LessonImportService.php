<?php

namespace App\Services;

use App\Blocks\BlockTypeRegistry;
use App\Enums\PageCompletionType;
use App\Exceptions\AuthoringValidationException;
use App\Models\Lesson;
use App\Models\LessonPage;
use App\Models\User;
use App\Services\Authoring\PageCompletionSatisfiability;
use App\Support\ImportAssetPlaceholder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Strict, transactional import of authoring-JSON (format_version 1.0).
 *
 * Validates the entire package before any write; rewrites local keys to ULIDs;
 * sanitizes HTML; then persists via LessonAuthoringService::create so drafts
 * share the same validators as hand-authored lessons.
 */
class LessonImportService
{
    public const FORMAT_VERSION = '1.0';

    private const KEY_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,49}$/';

    private const SOURCE_REF_EXCERPT_WARN_CHARS = 500;

    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'owner_id',
        'created_by_user_id',
        'status',
        'published_at',
        'current_version',
        'lesson_version_id',
        'assigned_by_user_id',
        'created_at',
        'updated_at',
        'deleted_at',
        'uuid',
        'page_id',
        'block_id',
        'has_unpublished_changes',
        'updated_by',
        'id',
    ];

    /** @var list<string> */
    private const ROOT_KEYS = ['format_version', 'source', 'lesson', 'pages'];

    /** @var list<string> */
    private const SOURCE_KEYS = ['title', 'type', 'filename'];

    /** @var list<string> */
    private const LESSON_KEYS = [
        'code', 'title', 'description', 'subject', 'grade_range',
        'estimated_minutes', 'learning_target', 'success_criteria', 'standards',
    ];

    /** @var list<string> */
    private const PAGE_KEYS = ['key', 'title', 'completion_type', 'estimated_minutes', 'blocks'];

    /** @var list<string> */
    private const BLOCK_META_KEYS = ['key', 'type', 'grading'];

    /** @var list<string> */
    private const HTML_FIELDS = [
        'html', 'transcript_html', 'prompt_html', 'rubric_html', 'scenario_html',
    ];

    /** @var array<string, mixed> */
    private const DEFAULT_IMPORT_QUIZ_GRADING = [
        'rule' => 'min_score',
        'min_score' => 80,
        'allow_retry' => true,
        'max_attempts' => 3,
        'record_first_attempt' => true,
        'points' => 10,
        'reveal_policy' => 'on_pass',
        'reveal_answers' => false,
    ];

    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly HtmlSanitizer $sanitizer,
        private readonly LessonAuthoringService $authoring,
        private readonly PageCompletionSatisfiability $completionSatisfiability,
    ) {
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array{lesson: Lesson, warnings: list<array{path: string, code: string, message: string}>}
     *
     * @throws AuthoringValidationException
     */
    public function import(array $package, User $user): array
    {
        $errors = [];
        $warnings = [];

        $this->rejectForbiddenKeys($package, '', $errors);
        $this->assertOnlyKeys($package, self::ROOT_KEYS, '', $errors);

        if (($package['format_version'] ?? null) !== self::FORMAT_VERSION) {
            $errors[] = 'format_version: unsupported or missing (expected "'.self::FORMAT_VERSION.'").';
        }

        if (array_key_exists('source', $package)) {
            if (! is_array($package['source'])) {
                $errors[] = 'source: must be an object.';
            } else {
                $this->assertOnlyKeys($package['source'], self::SOURCE_KEYS, 'source', $errors);
            }
        }

        if (! is_array($package['lesson'] ?? null)) {
            $errors[] = 'lesson: required object.';
        } else {
            $this->assertOnlyKeys($package['lesson'], self::LESSON_KEYS, 'lesson', $errors);
        }

        if (! is_array($package['pages'] ?? null)) {
            $errors[] = 'pages: required array.';
        }

        if ($errors !== []) {
            throw AuthoringValidationException::with($errors);
        }

        /** @var array<string, mixed> $lessonMeta */
        $lessonMeta = $package['lesson'];
        /** @var list<mixed> $pagesIn */
        $pagesIn = array_values($package['pages']);

        $code = is_string($lessonMeta['code'] ?? null) ? trim($lessonMeta['code']) : '';
        $title = is_string($lessonMeta['title'] ?? null) ? trim($lessonMeta['title']) : '';

        if ($code === '') {
            $errors[] = 'lesson.code: required.';
        } elseif (Lesson::withTrashed()->where('code', $code)->exists()) {
            $errors[] = "lesson.code: \"{$code}\" is already in use. Choose a different code — import never overwrites an existing lesson.";
        }

        if ($title === '') {
            $errors[] = 'lesson.title: required.';
        }

        if ($pagesIn === []) {
            $errors[] = 'pages: at least one page is required.';
        }

        $pageKeyMap = [];
        $rewrittenPages = [];

        foreach ($pagesIn as $pageIndex => $pageData) {
            $pagePath = "pages[{$pageIndex}]";
            if (! is_array($pageData)) {
                $errors[] = "{$pagePath}: must be an object.";

                continue;
            }

            $this->assertOnlyKeys($pageData, self::PAGE_KEYS, $pagePath, $errors);

            $pageKey = $pageData['key'] ?? null;
            if (! $this->isValidKey($pageKey)) {
                $errors[] = "{$pagePath}.key: must match ".self::KEY_PATTERN.' (got '.json_encode($pageKey).').';

                continue;
            }

            if (isset($pageKeyMap[$pageKey])) {
                $errors[] = "{$pagePath} (key: {$pageKey}): duplicate page key.";

                continue;
            }

            $pageKeyMap[$pageKey] = (string) Str::ulid();
            $pagePathLabeled = "{$pagePath} (key: {$pageKey})";

            $completion = $pageData['completion_type'] ?? null;
            if (! is_string($completion) || PageCompletionType::tryFrom($completion) === null) {
                $errors[] = "{$pagePathLabeled}.completion_type: invalid value.";
            }

            $pageTitle = is_string($pageData['title'] ?? null) ? trim($pageData['title']) : '';
            if ($pageTitle === '') {
                $errors[] = "{$pagePathLabeled}.title: required.";
            }

            $blocksIn = array_values(is_array($pageData['blocks'] ?? null) ? $pageData['blocks'] : []);
            if (! array_key_exists('blocks', $pageData) || ! is_array($pageData['blocks'])) {
                $errors[] = "{$pagePathLabeled}.blocks: required array.";
            }

            $blockKeyMap = [];
            $rewrittenBlocks = [];

            foreach ($blocksIn as $blockIndex => $blockData) {
                $blockPath = "{$pagePathLabeled} → blocks[{$blockIndex}]";
                if (! is_array($blockData)) {
                    $errors[] = "{$blockPath}: must be an object.";

                    continue;
                }

                $blockKey = $blockData['key'] ?? null;
                if (! $this->isValidKey($blockKey)) {
                    $errors[] = "{$blockPath}.key: invalid key.";

                    continue;
                }

                if (isset($blockKeyMap[$blockKey])) {
                    $errors[] = "{$blockPath} (key: {$blockKey}): duplicate block key on this page.";

                    continue;
                }

                $blockKeyMap[$blockKey] = (string) Str::ulid();
                $blockPathLabeled = "{$blockPath} (key: {$blockKey})";

                $type = $blockData['type'] ?? null;
                if (! is_string($type) || ! $this->registry->has($type)) {
                    $errors[] = "{$blockPathLabeled}.type: unknown block type ".json_encode($type).'.';

                    continue;
                }

                $allowedConfig = array_keys($this->registry->get($type)->defaultConfig());
                $allowedBlockKeys = array_merge(self::BLOCK_META_KEYS, $allowedConfig);
                $this->assertOnlyKeys($blockData, $allowedBlockKeys, $blockPathLabeled, $errors);

                $grading = array_key_exists('grading', $blockData) && is_array($blockData['grading'])
                    ? $blockData['grading']
                    : null;

                $config = $blockData;
                unset($config['key'], $config['type'], $config['grading']);

                [$config, $blockErrors, $blockWarnings] = $this->rewriteBlockConfig(
                    $type,
                    $config,
                    $blockPathLabeled,
                );

                foreach ($blockErrors as $error) {
                    $errors[] = $error;
                }
                foreach ($blockWarnings as $warning) {
                    $warnings[] = $warning;
                }

                $config = $this->sanitizeHtmlFields($config);

                // Hard-validate with the real block rules once keys rewrite cleanly.
                if ($blockErrors === []) {
                    try {
                        $validated = $this->registry->get($type)->validateConfig($config);
                        $this->registry->get($type)->validateGrading($grading);
                        $config = $validated;
                    } catch (ValidationException $e) {
                        foreach ($e->errors() as $field => $messages) {
                            foreach ($messages as $message) {
                                $errors[] = "{$blockPathLabeled} → {$field}: {$message}";
                            }
                        }
                    }

                    if ($type === 'quiz') {
                        $this->assertQuizSourceRefs($config, $blockPathLabeled, $errors, $warnings);
                        if ($this->isDefaultImportQuizGrading($grading)) {
                            $warnings[] = [
                                'path' => "{$blockPathLabeled}.grading",
                                'code' => 'DEFAULT_QUIZ_GRADING',
                                'message' => 'Quiz grading still uses the import template defaults — review before publish.',
                            ];
                        }
                    }

                    $this->collectAssetWarnings($type, $config, $blockPathLabeled, $warnings);
                    $this->collectVideoWarnings($type, $config, $blockPathLabeled, $warnings);
                    $this->collectHotspotWarnings($type, $config, $blockPathLabeled, $warnings);
                }

                $rewrittenBlocks[] = [
                    'type' => $type,
                    'block_id' => $blockKeyMap[$blockKey],
                    'config' => $config,
                    'grading' => $grading,
                ];
            }

            if (is_string($completion) && PageCompletionType::tryFrom($completion) !== null) {
                $satisfiable = $this->completionSatisfiability->isSatisfiable(
                    $completion,
                    array_map(fn (array $b) => ['type' => $b['type'], 'config' => $b['config']], $rewrittenBlocks)
                );
                if (! $satisfiable) {
                    $errors[] = "{$pagePathLabeled}.completion_type: \"{$completion}\" is not satisfiable by this page's blocks.";
                }
            }

            $rewrittenPages[] = [
                'page_id' => $pageKeyMap[$pageKey],
                'title' => $pageTitle !== '' ? $pageTitle : 'Untitled page',
                'completion_type' => is_string($completion) ? $completion : PageCompletionType::View->value,
                'estimated_minutes' => $pageData['estimated_minutes'] ?? null,
                'settings' => LessonPage::DEFAULT_SETTINGS,
                'blocks' => array_map(fn (array $b) => [
                    'type' => $b['type'],
                    'data' => array_merge($b['config'], [
                        'block_id' => $b['block_id'],
                        'grading' => $b['grading'],
                    ]),
                ], $rewrittenBlocks),
            ];
        }

        if ($errors !== []) {
            throw AuthoringValidationException::with($errors, $this->flattenWarningMessages($warnings));
        }

        // Persist only after the whole package validated — create() is transactional.
        $lesson = $this->authoring->create([
            'code' => $code,
            'title' => $title,
            'description' => $lessonMeta['description'] ?? null,
            'subject' => $lessonMeta['subject'] ?? null,
            'grade_range' => $lessonMeta['grade_range'] ?? null,
            'estimated_minutes' => $lessonMeta['estimated_minutes'] ?? null,
            'learning_target' => $lessonMeta['learning_target'] ?? null,
            'success_criteria' => $lessonMeta['success_criteria'] ?? null,
            'standards' => $lessonMeta['standards'] ?? null,
            'pages' => $rewrittenPages,
        ], $user);

        return [
            'lesson' => $lesson,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: array<string, mixed>, 1: list<string>, 2: list<array{path: string, code: string, message: string}>}
     */
    private function rewriteBlockConfig(string $type, array $config, string $blockPath): array
    {
        $errors = [];
        $warnings = [];

        return match ($type) {
            'quiz' => $this->rewriteQuiz($config, $blockPath, $errors, $warnings),
            'matching' => $this->rewriteBankAnswered($config, $blockPath, 'slots', $errors, $warnings),
            'image_labeling' => $this->rewriteBankAnswered($config, $blockPath, 'hotspots', $errors, $warnings),
            'vocabulary_cards' => $this->rewriteKeyedList($config, 'terms', $blockPath, $errors, $warnings),
            'cer' => $this->rewriteKeyedList($config, 'fields', $blockPath, $errors, $warnings),
            'video' => $this->rewriteKeyedList($config, 'focus_questions', $blockPath, $errors, $warnings),
            default => [$config, $errors, $warnings],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $errors
     * @param  list<array{path: string, code: string, message: string}>  $warnings
     * @return array{0: array<string, mixed>, 1: list<string>, 2: list<array{path: string, code: string, message: string}>}
     */
    private function rewriteQuiz(array $config, string $blockPath, array &$errors, array &$warnings): array
    {
        $questionsIn = array_values(is_array($config['questions'] ?? null) ? $config['questions'] : []);
        $questionKeys = [];
        $questions = [];

        foreach ($questionsIn as $qi => $question) {
            $qPath = "{$blockPath} → questions[{$qi}]";
            if (! is_array($question)) {
                $errors[] = "{$qPath}: must be an object.";

                continue;
            }

            $qKey = $question['key'] ?? null;
            if (! $this->isValidKey($qKey)) {
                $errors[] = "{$qPath}.key: invalid key.";

                continue;
            }
            if (isset($questionKeys[$qKey])) {
                $errors[] = "{$qPath} (key: {$qKey}): duplicate question key.";

                continue;
            }

            $questionKeys[$qKey] = (string) Str::ulid();
            $qPathLabeled = "{$qPath} (key: {$qKey})";

            $optionsIn = array_values(is_array($question['options'] ?? null) ? $question['options'] : []);
            $optionKeys = [];
            $options = [];

            foreach ($optionsIn as $oi => $option) {
                $oPath = "{$qPathLabeled} → options[{$oi}]";
                if (! is_array($option)) {
                    $errors[] = "{$oPath}: must be an object.";

                    continue;
                }
                $oKey = $option['key'] ?? null;
                if (! $this->isValidKey($oKey)) {
                    $errors[] = "{$oPath}.key: invalid key.";

                    continue;
                }
                if (isset($optionKeys[$oKey])) {
                    $errors[] = "{$oPath} (key: {$oKey}): duplicate option key in this question.";

                    continue;
                }
                $optionKeys[$oKey] = (string) Str::ulid();
                $option['id'] = $optionKeys[$oKey];
                unset($option['key']);
                $options[] = $option;
            }

            $answerKey = $question['answer_id'] ?? null;
            if (! is_string($answerKey) || $answerKey === '') {
                $errors[] = "{$qPathLabeled} → answer_id: required.";
            } elseif (! isset($optionKeys[$answerKey])) {
                $errors[] = "{$qPathLabeled} → answer_id: unresolved option key \"{$answerKey}\".";
            }

            $question['id'] = $questionKeys[$qKey];
            $question['options'] = $options;
            $question['answer_id'] = is_string($answerKey) ? ($optionKeys[$answerKey] ?? $answerKey) : $answerKey;
            unset($question['key']);
            $questions[] = $question;
        }

        $config['questions'] = $questions;

        return [$config, $errors, $warnings];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $errors
     * @param  list<array{path: string, code: string, message: string}>  $warnings
     * @return array{0: array<string, mixed>, 1: list<string>, 2: list<array{path: string, code: string, message: string}>}
     */
    private function rewriteBankAnswered(
        array $config,
        string $blockPath,
        string $answeredList,
        array &$errors,
        array &$warnings,
    ): array {
        [$config, $bankMap, $bankErrors] = $this->mapKeyedList($config, 'bank', $blockPath);
        foreach ($bankErrors as $error) {
            $errors[] = $error;
        }

        $itemsIn = array_values(is_array($config[$answeredList] ?? null) ? $config[$answeredList] : []);
        $itemKeys = [];
        $items = [];

        foreach ($itemsIn as $index => $item) {
            $path = "{$blockPath} → {$answeredList}[{$index}]";
            if (! is_array($item)) {
                $errors[] = "{$path}: must be an object.";

                continue;
            }
            $key = $item['key'] ?? null;
            if (! $this->isValidKey($key)) {
                $errors[] = "{$path}.key: invalid key.";

                continue;
            }
            if (isset($itemKeys[$key]) || isset($bankMap[$key])) {
                $errors[] = "{$path} (key: {$key}): duplicate key within this block (bank / {$answeredList} share one key space).";

                continue;
            }
            $itemKeys[$key] = (string) Str::ulid();

            $answerKey = $item['answer_id'] ?? null;
            if (! is_string($answerKey) || $answerKey === '') {
                $errors[] = "{$path} (key: {$key}) → answer_id: required.";
            } elseif (! isset($bankMap[$answerKey])) {
                $errors[] = "{$path} (key: {$key}) → answer_id: unresolved bank key \"{$answerKey}\".";
            }

            $item['id'] = $itemKeys[$key];
            $item['answer_id'] = is_string($answerKey) ? ($bankMap[$answerKey] ?? $answerKey) : $answerKey;
            unset($item['key']);
            $items[] = $item;
        }

        $config[$answeredList] = $items;

        return [$config, $errors, $warnings];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $errors
     * @param  list<array{path: string, code: string, message: string}>  $warnings
     * @return array{0: array<string, mixed>, 1: list<string>, 2: list<array{path: string, code: string, message: string}>}
     */
    private function rewriteKeyedList(
        array $config,
        string $listKey,
        string $blockPath,
        array &$errors,
        array &$warnings,
    ): array {
        [$config, , $listErrors] = $this->mapKeyedList($config, $listKey, $blockPath);
        foreach ($listErrors as $error) {
            $errors[] = $error;
        }

        return [$config, $errors, $warnings];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: list<string>}
     */
    private function mapKeyedList(array $config, string $listKey, string $blockPath): array
    {
        $itemsIn = array_values(is_array($config[$listKey] ?? null) ? $config[$listKey] : []);
        $map = [];
        $errors = [];
        $items = [];

        foreach ($itemsIn as $index => $item) {
            $path = "{$blockPath} → {$listKey}[{$index}]";
            if (! is_array($item)) {
                $errors[] = "{$path}: must be an object.";

                continue;
            }
            $key = $item['key'] ?? null;
            if (! $this->isValidKey($key)) {
                $errors[] = "{$path}.key: invalid key.";

                continue;
            }
            if (isset($map[$key])) {
                $errors[] = "{$path} (key: {$key}): duplicate {$listKey} key.";

                continue;
            }
            $map[$key] = (string) Str::ulid();
            $item['id'] = $map[$key];
            unset($item['key']);
            $items[] = $item;
        }

        $config[$listKey] = $items;

        return [$config, $map, $errors];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $errors
     * @param  list<array{path: string, code: string, message: string}>  $warnings
     */
    private function assertQuizSourceRefs(array $config, string $blockPath, array &$errors, array &$warnings): void
    {
        foreach ($config['questions'] ?? [] as $qi => $question) {
            if (! is_array($question)) {
                continue;
            }
            $path = "{$blockPath} → questions[{$qi}]";
            $ref = $question['source_ref'] ?? null;
            if (! is_array($ref)) {
                $errors[] = "{$path} → source_ref: required on every quiz question.";

                continue;
            }
            $page = is_string($ref['page'] ?? null) ? trim($ref['page']) : '';
            $excerpt = is_string($ref['excerpt'] ?? null) ? trim($ref['excerpt']) : '';
            if ($page === '') {
                $errors[] = "{$path} → source_ref.page: required.";
            }
            if ($excerpt === '') {
                $errors[] = "{$path} → source_ref.excerpt: required.";
            } elseif (mb_strlen($excerpt) > self::SOURCE_REF_EXCERPT_WARN_CHARS) {
                $warnings[] = [
                    'path' => "{$path}.source_ref.excerpt",
                    'code' => 'SOURCE_REF_EXCERPT_LONG',
                    'message' => 'source_ref excerpt is longer than '.self::SOURCE_REF_EXCERPT_WARN_CHARS.' characters — consider trimming for review.',
                ];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<array{path: string, code: string, message: string}>  $warnings
     */
    private function collectAssetWarnings(string $type, array $config, string $blockPath, array &$warnings): void
    {
        $field = match ($type) {
            'image', 'file_link' => 'url',
            'image_labeling' => 'image_url',
            default => null,
        };
        if ($field === null) {
            return;
        }
        $url = $config[$field] ?? null;
        if (ImportAssetPlaceholder::isPlaceholder(is_string($url) ? $url : null)) {
            $warnings[] = [
                'path' => "{$blockPath}.{$field}",
                'code' => 'ASSET_PLACEHOLDER',
                'message' => 'Asset placeholder must be replaced before publication.',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<array{path: string, code: string, message: string}>  $warnings
     */
    private function collectVideoWarnings(string $type, array $config, string $blockPath, array &$warnings): void
    {
        if ($type !== 'video') {
            return;
        }

        $transcript = $config['transcript_html'] ?? null;
        if ($transcript === null || (is_string($transcript) && trim(strip_tags($transcript)) === '')) {
            $warnings[] = [
                'path' => "{$blockPath}.transcript_html",
                'code' => 'MISSING_TRANSCRIPT',
                'message' => 'Video has no transcript.',
            ];
        }

        if (($config['captions_available'] ?? null) === false) {
            $warnings[] = [
                'path' => "{$blockPath}.captions_available",
                'code' => 'CAPTIONS_UNAVAILABLE',
                'message' => 'captions_available is false.',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<array{path: string, code: string, message: string}>  $warnings
     */
    private function collectHotspotWarnings(string $type, array $config, string $blockPath, array &$warnings): void
    {
        if ($type !== 'image_labeling') {
            return;
        }

        $hotspots = is_array($config['hotspots'] ?? null) ? $config['hotspots'] : [];
        if ($hotspots === []) {
            return;
        }

        $allDefault = true;
        foreach ($hotspots as $hotspot) {
            if (! is_array($hotspot)) {
                continue;
            }
            $x = (float) ($hotspot['x_pct'] ?? 0);
            $y = (float) ($hotspot['y_pct'] ?? 0);
            if (abs($x - 50.0) > 0.001 || abs($y - 50.0) > 0.001) {
                $allDefault = false;
                break;
            }
        }

        if ($allDefault) {
            $warnings[] = [
                'path' => "{$blockPath}.hotspots",
                'code' => 'HOTSPOTS_REQUIRE_POSITIONING',
                'message' => 'All hotspot coordinates remain at the import default.',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function sanitizeHtmlFields(array $config): array
    {
        foreach (self::HTML_FIELDS as $field) {
            if (array_key_exists($field, $config) && is_string($config[$field])) {
                $config[$field] = $this->sanitizer->sanitize($config[$field]);
            }
        }

        return $config;
    }

    private function isDefaultImportQuizGrading(?array $grading): bool
    {
        if ($grading === null) {
            return false;
        }

        foreach (self::DEFAULT_IMPORT_QUIZ_GRADING as $key => $expected) {
            if (($grading[$key] ?? null) !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function isValidKey(mixed $key): bool
    {
        return is_string($key) && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $errors
     */
    private function assertOnlyKeys(array $data, array $allowed, string $path, array &$errors): void
    {
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                $prefix = $path === '' ? '' : "{$path}.";
                $errors[] = "{$prefix}{$key}: unknown field.";
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function rejectForbiddenKeys(mixed $node, string $path, array &$errors): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            $childPath = $path === '' ? (string) $key : "{$path}.{$key}";
            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                $errors[] = "{$childPath}: forbidden field — importer sets ownership and draft state.";
            }
            $this->rejectForbiddenKeys($value, $childPath, $errors);
        }
    }

    /**
     * @param  list<array{path: string, code: string, message: string}>  $warnings
     * @return list<string>
     */
    private function flattenWarningMessages(array $warnings): array
    {
        return array_values(array_map(
            fn (array $w) => "{$w['path']}: {$w['message']}",
            $warnings
        ));
    }
}
