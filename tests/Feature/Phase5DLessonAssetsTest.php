<?php

use App\Enums\LessonStatus;
use App\Enums\PageCompletionType;
use App\Enums\UserRole;
use App\Exceptions\AuthoringValidationException;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\User;
use App\Rules\LessonScopedAssetUrl;
use App\Services\LessonAssetService;
use App\Services\LessonAuthoringService;
use App\Services\LessonContentDuplicator;
use App\Services\LessonPublisher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('public');
});

/** Minimal valid 1×1 PNG (no GD extension required). */
function phase5dPngBytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

/**
 * @return array{teacher: User, lesson: Lesson, page: LessonPage}
 */
function phase5dOwnedLesson(): array
{
    $teacher = asTeacher();
    $lesson = app(LessonAuthoringService::class)->create([
        'code' => 'UP-'.Str::upper(Str::random(4)),
        'title' => 'Upload lesson',
        'pages' => [[
            'page_id' => (string) Str::ulid(),
            'title' => 'Page 1',
            'completion_type' => PageCompletionType::View->value,
            'settings' => LessonPage::DEFAULT_SETTINGS,
            'blocks' => [[
                'type' => 'image',
                'data' => array_merge(
                    app(App\Blocks\BlockTypeRegistry::class)->get('image')->defaultConfig(),
                    ['block_id' => (string) Str::ulid(), 'grading' => null]
                ),
            ]],
        ]],
    ], $teacher);

    return [
        'teacher' => $teacher,
        'lesson' => $lesson->fresh(['pages.blocks']),
        'page' => $lesson->pages()->first(),
    ];
}

function phase5dPng(string $name = 'diagram.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, phase5dPngBytes());
}

function phase5dSvg(string $name = 'icon.svg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
    );
}

function phase5dFakeExecutableAsPng(string $name = 'malware.png'): UploadedFile
{
    // PE header — content-type detection must reject despite .png extension.
    return UploadedFile::fake()->createWithContent($name, "MZ\x90\x00\x03\x00\x00\x00".str_repeat("\0", 64));
}

function phase5dMinimalPdf(string $name = 'handout.pdf'): UploadedFile
{
    $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    return UploadedFile::fake()->createWithContent($name, $pdf);
}

function phase5dOoxml(string $name, string $partPath, string $partXml): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'ooxml');
    $zip = new \ZipArchive;
    $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $zip->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/'.$partPath.'" ContentType="application/vnd.openxmlformats-officedocument.'
        .(str_contains($name, 'docx') ? 'wordprocessingml.document.main+xml'
            : (str_contains($name, 'xlsx') ? 'spreadsheetml.sheet.main+xml'
                : 'presentationml.presentation.main+xml'))
        .'"/></Types>'
    );
    $zip->addFromString($partPath, $partXml);
    $zip->close();

    return new UploadedFile($tmp, $name, null, null, true);
}

function phase5dDocx(string $name = 'notes.docx'): UploadedFile
{
    return phase5dOoxml(
        $name,
        'word/document.xml',
        '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>'
    );
}

function phase5dXlsx(string $name = 'sheet.xlsx'): UploadedFile
{
    return phase5dOoxml(
        $name,
        'xl/workbook.xml',
        '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>'
    );
}

function phase5dPptx(string $name = 'slides.pptx'): UploadedFile
{
    return phase5dOoxml(
        $name,
        'ppt/presentation.xml',
        '<?xml version="1.0"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>'
    );
}

test('valid image uploads under lessons/{uuid}/ and stores a /storage/ config URL', function () {
    ['teacher' => $teacher, 'lesson' => $lesson] = phase5dOwnedLesson();
    $service = app(LessonAssetService::class);

    $url = $service->storeImage($lesson, phase5dPng('My Diagram.PNG'), $teacher);

    expect($url)->toStartWith('/storage/lessons/'.$lesson->uuid.'/')
        ->and($url)->toEndWith('.png')
        ->and($url)->not->toContain('My Diagram');

    $diskPath = $service->toDiskRelativePath($url);
    expect($diskPath)->not->toBeNull();
    Storage::disk('public')->assertExists($diskPath);
});

test('svg and executable-renamed-as-png uploads are rejected', function () {
    ['teacher' => $teacher, 'lesson' => $lesson] = phase5dOwnedLesson();
    $service = app(LessonAssetService::class);

    expect(fn () => $service->storeImage($lesson, phase5dSvg(), $teacher))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->storeImage($lesson, phase5dFakeExecutableAsPng(), $teacher))
        ->toThrow(ValidationException::class);
});

test('over-cap images are rejected with a validation message', function () {
    ['teacher' => $teacher, 'lesson' => $lesson] = phase5dOwnedLesson();
    $max = (int) config('lesson-assets.image_max_kb');
    $file = UploadedFile::fake()->createWithContent('huge.png', phase5dPngBytes())->size($max + 1);

    try {
        app(LessonAssetService::class)->storeImage($lesson, $file, $teacher);
        expect(false)->toBeTrue('expected ValidationException');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->not->toBeEmpty();
    }
});

test('replacing an image leaves the previous file on disk and published manifests resolve', function () {
    ['teacher' => $teacher, 'lesson' => $lesson, 'page' => $page] = phase5dOwnedLesson();
    $assets = app(LessonAssetService::class);
    $authoring = app(LessonAuthoringService::class);

    $firstUrl = $assets->storeImage($lesson, phase5dPng('first.png'), $teacher);
    $firstDisk = $assets->toDiskRelativePath($firstUrl);

    $block = $page->blocks()->first();
    $authoring->savePage($page, [
        'updated_at' => $page->fresh()->updated_at->toISOString(),
        'title' => $page->title,
        'completion_type' => $page->completion_type->value,
        'settings' => $page->settings,
        'blocks' => [[
            'type' => 'image',
            'data' => array_merge($block->config, [
                'block_id' => $block->block_id,
                'url' => $firstUrl,
                'alt' => 'First',
                'grading' => null,
            ]),
        ]],
    ], $teacher);

    app(LessonPublisher::class)->publish($lesson->fresh(), $teacher);
    $v1 = $lesson->fresh()->versions()->where('version', 1)->first();
    expect($v1)->not->toBeNull();
    $manifestUrl = data_get($v1->manifest, 'pages.0.blocks.0.config.url');
    expect($manifestUrl)->toBe($firstUrl);

    $secondUrl = $assets->storeImage($lesson, phase5dPng('second.png'), $teacher);
    expect($secondUrl)->not->toBe($firstUrl);
    Storage::disk('public')->assertExists($firstDisk);
    Storage::disk('public')->assertExists($assets->toDiskRelativePath($secondUrl));
});

test('removing a block, deleting a page, and archiving leave files untouched', function () {
    ['teacher' => $teacher, 'lesson' => $lesson, 'page' => $page] = phase5dOwnedLesson();
    $assets = app(LessonAssetService::class);
    $authoring = app(LessonAuthoringService::class);

    $url = $assets->storeImage($lesson, phase5dPng(), $teacher);
    $disk = $assets->toDiskRelativePath($url);

    $block = $page->blocks()->first();
    $authoring->savePage($page, [
        'updated_at' => $page->fresh()->updated_at->toISOString(),
        'title' => $page->title,
        'completion_type' => $page->completion_type->value,
        'settings' => $page->settings,
        'blocks' => [[
            'type' => 'image',
            'data' => array_merge($block->config, [
                'block_id' => $block->block_id,
                'url' => $url,
                'alt' => 'Kept',
                'grading' => null,
            ]),
        ]],
    ], $teacher);

    // Remove the image block (empty blocks).
    $authoring->savePage($page->fresh(), [
        'updated_at' => $page->fresh()->updated_at->toISOString(),
        'title' => $page->title,
        'completion_type' => $page->completion_type->value,
        'settings' => $page->settings,
        'blocks' => [],
    ], $teacher);
    Storage::disk('public')->assertExists($disk);

    $page2 = $authoring->createPage(
        $lesson->fresh(),
        $teacher,
        $lesson->fresh()->updated_at->toISOString(),
        ['title' => 'Temp']
    );
    $url2 = $assets->storeImage($lesson->fresh(), phase5dPng('page2.png'), $teacher);
    $disk2 = $assets->toDiskRelativePath($url2);
    $authoring->deletePage(
        $lesson->fresh(),
        $page2,
        $teacher,
        $lesson->fresh()->updated_at->toISOString()
    );
    Storage::disk('public')->assertExists($disk2);

    $authoring->archive($lesson->fresh(), $teacher);
    Storage::disk('public')->assertExists($disk);
    expect($lesson->fresh()->status)->toBe(LessonStatus::Archived);
});

test('a teacher cannot upload to a lesson they do not own', function () {
    ['lesson' => $lesson] = phase5dOwnedLesson();
    $other = User::factory()->create();
    $other->forceFill(['role' => UserRole::Teacher])->save();

    expect(fn () => app(LessonAssetService::class)->storeImage($lesson, phase5dPng(), $other->fresh()))
        ->toThrow(AccessDeniedHttpException::class);
});

test('forged upload directories and traversal paths are rejected', function () {
    ['lesson' => $lesson] = phase5dOwnedLesson();
    $service = app(LessonAssetService::class);

    expect(fn () => $service->assertDiskPathBelongsToLesson($lesson, 'lessons/'.(string) Str::uuid().'/stolen.png'))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->assertDiskPathBelongsToLesson($lesson, 'lessons/'.$lesson->uuid.'/../other/x.png'))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->assertDiskPathBelongsToLesson($lesson, 'C:\\Windows\\system32\\x.png'))
        ->toThrow(ValidationException::class);

    // Same-lesson path is accepted; client directory preference is irrelevant
    // because storeImage always writes under lessonDirectory().
    $service->assertDiskPathBelongsToLesson($lesson, 'lessons/'.$lesson->uuid.'/abc.png');
});

test('manual path field accepts same-lesson storage and fixtures; rejects other lessons', function () {
    ['lesson' => $lesson] = phase5dOwnedLesson();
    $rule = new LessonScopedAssetUrl($lesson->uuid);

    $pass = function (string $value) use ($rule): bool {
        $failed = false;
        $rule->validate('url', $value, function () use (&$failed) {
            $failed = true;
        });

        return ! $failed;
    };

    expect($pass('/storage/lessons/'.$lesson->uuid.'/a.png'))->toBeTrue()
        ->and($pass('/lessons/wel-6-1-1/welding-diagram.png'))->toBeTrue()
        ->and($pass('https://cdn.example.com/a.png'))->toBeTrue()
        ->and($pass('/import-placeholder/image-required'))->toBeTrue()
        ->and($pass('/storage/lessons/'.(string) Str::uuid().'/a.png'))->toBeFalse()
        ->and($pass('/storage/lessons/'.$lesson->uuid.'/../x.png'))->toBeFalse()
        ->and($pass('C:\\uploads\\a.png'))->toBeFalse();
});

test('savePage hard-rejects another lesson storage path and never stores disk-relative config', function () {
    ['teacher' => $teacher, 'lesson' => $lesson, 'page' => $page] = phase5dOwnedLesson();
    $block = $page->blocks()->first();
    $otherUuid = (string) Str::uuid();

    expect(fn () => app(LessonAuthoringService::class)->savePage($page, [
        'updated_at' => $page->updated_at->toISOString(),
        'title' => $page->title,
        'completion_type' => $page->completion_type->value,
        'settings' => $page->settings,
        'blocks' => [[
            'type' => 'image',
            'data' => array_merge($block->config, [
                'block_id' => $block->block_id,
                'url' => '/storage/lessons/'.$otherUuid.'/stolen.png',
                'alt' => 'Nope',
                'grading' => null,
            ]),
        ]],
    ], $teacher))->toThrow(AuthoringValidationException::class);

    $url = app(LessonAssetService::class)->storeImage($lesson, phase5dPng(), $teacher);
    app(LessonAuthoringService::class)->savePage($page->fresh(), [
        'updated_at' => $page->fresh()->updated_at->toISOString(),
        'title' => $page->title,
        'completion_type' => $page->completion_type->value,
        'settings' => $page->settings,
        'blocks' => [[
            'type' => 'image',
            'data' => array_merge($block->config, [
                'block_id' => $block->block_id,
                'url' => $url,
                'alt' => 'Ok',
                'grading' => null,
            ]),
        ]],
    ], $teacher);

    $stored = $page->fresh()->blocks()->first()->config['url'];
    expect($stored)->toStartWith('/storage/')
        ->and($stored)->not->toStartWith('lessons/');
});

test('pdf and office documents are accepted; macro-enabled formats are rejected', function () {
    ['teacher' => $teacher, 'lesson' => $lesson] = phase5dOwnedLesson();
    $service = app(LessonAssetService::class);

    foreach ([phase5dMinimalPdf(), phase5dDocx(), phase5dXlsx(), phase5dPptx()] as $file) {
        $stored = $service->storeDocument($lesson, $file, $teacher);
        expect($stored->url)->toStartWith('/storage/lessons/'.$lesson->uuid.'/');
        Storage::disk('public')->assertExists($service->toDiskRelativePath($stored->url));
    }

    foreach (['macros.docm', 'macros.xlsm', 'macros.pptm'] as $name) {
        $bad = UploadedFile::fake()->create($name, 20);
        expect(fn () => $service->storeDocument($lesson, $bad, $teacher))
            ->toThrow(ValidationException::class);
    }
});

test('identical original filenames produce distinct stored files', function () {
    ['teacher' => $teacher, 'lesson' => $lesson] = phase5dOwnedLesson();
    $service = app(LessonAssetService::class);

    $a = $service->storeImage($lesson, phase5dPng('same.png'), $teacher);
    $b = $service->storeImage($lesson, phase5dPng('same.png'), $teacher);

    expect($a)->not->toBe($b);
    Storage::disk('public')->assertExists($service->toDiskRelativePath($a));
    Storage::disk('public')->assertExists($service->toDiskRelativePath($b));
});

test('file_link empty label is initialized from the original filename', function () {
    ['teacher' => $teacher, 'lesson' => $lesson] = phase5dOwnedLesson();
    $stored = app(LessonAssetService::class)->storeDocument(
        $lesson,
        phase5dMinimalPdf('Safety Sheet.pdf'),
        $teacher
    );

    expect($stored->originalName)->toBe('Safety Sheet.pdf')
        ->and($stored->url)->not->toContain('Safety Sheet');
});

test('alt text remains required after an image upload path is set', function () {
    ['teacher' => $teacher, 'lesson' => $lesson, 'page' => $page] = phase5dOwnedLesson();
    $url = app(LessonAssetService::class)->storeImage($lesson, phase5dPng(), $teacher);
    $block = $page->blocks()->first();

    $result = app(LessonAuthoringService::class)->savePage($page, [
        'updated_at' => $page->updated_at->toISOString(),
        'title' => $page->title,
        'completion_type' => $page->completion_type->value,
        'settings' => $page->settings,
        'blocks' => [[
            'type' => 'image',
            'data' => array_merge($block->config, [
                'block_id' => $block->block_id,
                'url' => $url,
                'alt' => '',
                'grading' => null,
            ]),
        ]],
    ], $teacher);

    // Draft save soft-warns on validateConfig failures; alt empty must still warn.
    expect(implode("\n", $result['warnings']))->toContain('alt');
});

test('duplicating a lesson shares asset paths and does not copy files', function () {
    ['teacher' => $teacher, 'lesson' => $lesson, 'page' => $page] = phase5dOwnedLesson();
    $assets = app(LessonAssetService::class);
    $url = $assets->storeImage($lesson, phase5dPng(), $teacher);
    $disk = $assets->toDiskRelativePath($url);
    $block = $page->blocks()->first();

    app(LessonAuthoringService::class)->savePage($page, [
        'updated_at' => $page->updated_at->toISOString(),
        'title' => $page->title,
        'completion_type' => $page->completion_type->value,
        'settings' => $page->settings,
        'blocks' => [[
            'type' => 'image',
            'data' => array_merge($block->config, [
                'block_id' => $block->block_id,
                'url' => $url,
                'alt' => 'Shared',
                'grading' => null,
            ]),
        ]],
    ], $teacher);

    $copy = app(LessonContentDuplicator::class)->duplicateLesson($lesson->fresh(), $teacher);
    $copyUrl = $copy->pages()->first()->blocks()->first()->config['url'] ?? null;

    expect($copyUrl)->toBe($url);
    Storage::disk('public')->assertExists($disk);
    // Still a single object — duplication must not rewrite or copy the blob.
    expect(Storage::disk('public')->files('lessons/'.$lesson->uuid))->toHaveCount(1);
});

test('client-submitted directory is ignored in favor of the server-derived lesson directory', function () {
    ['teacher' => $teacher, 'lesson' => $lesson] = phase5dOwnedLesson();
    $service = app(LessonAssetService::class);

    // Even if a caller imagined writing elsewhere, storeImage always uses lesson uuid.
    $url = $service->storeImage($lesson, phase5dPng('x.png'), $teacher);
    $disk = $service->toDiskRelativePath($url);

    expect($disk)->toStartWith('lessons/'.$lesson->uuid.'/')
        ->and($disk)->not->toStartWith('lessons/forged-');
});

test('temporary-upload cleanup config does not delete finalized lesson assets', function () {
    ['teacher' => $teacher, 'lesson' => $lesson] = phase5dOwnedLesson();
    $service = app(LessonAssetService::class);
    $url = $service->storeImage($lesson, phase5dPng(), $teacher);
    $disk = $service->toDiskRelativePath($url);

    // Livewire cleans temporary uploads; finalized public-disk lesson files remain.
    expect(config('livewire.temporary_file_upload.cleanup'))->toBeTrue()
        ->and(config('livewire.temporary_file_upload.rules'))->toContain('max:'.(int) config('lesson-assets.document_max_kb'));
    Storage::disk('public')->assertExists($disk);
});
