<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Owns permanent lesson asset storage on the public disk.
 *
 * Append-only once a file is stored under lessons/{uuid}/: never delete on
 * replace, block removal, page deletion, or lesson archive/delete. Published
 * LessonVersion manifests pin those paths; removing the file would break
 * students mid-attempt. Orphan cleanup is a later admin feature — when built
 * it must consult every LessonVersion manifest (not just the draft), expose a
 * dry-run, and must not be a quiet scheduled job.
 */
final class LessonAssetService
{
    public const DISK = 'public';

    public function storeImage(Lesson $lesson, UploadedFile $file, User $actor): string
    {
        $this->authorize($lesson, $actor);
        $this->validateImage($file);

        $extension = $this->validatedExtension($file, config('lesson-assets.image_extensions'));
        $diskPath = $this->storePermanently($lesson, $file, $extension);

        return $this->toPublicUrl($diskPath);
    }

    public function storeDocument(Lesson $lesson, UploadedFile $file, User $actor): StoredLessonDocument
    {
        $this->authorize($lesson, $actor);
        $this->validateDocument($file);

        $extension = $this->validatedExtension($file, config('lesson-assets.document_extensions'));
        $diskPath = $this->storePermanently($lesson, $file, $extension);

        return new StoredLessonDocument(
            url: $this->toPublicUrl($diskPath),
            originalName: $file->getClientOriginalName() ?: ('document.'.$extension),
        );
    }

    /**
     * Reject a client-supplied disk-relative path that is not under this lesson.
     * Filament FileUpload state can name any directory — never honor it as a
     * write destination or as a config URL without this check.
     */
    public function assertDiskPathBelongsToLesson(Lesson $lesson, string $diskRelativePath): void
    {
        $normalized = str_replace('\\', '/', ltrim($diskRelativePath, '/'));

        if (str_contains($normalized, '..')) {
            throw ValidationException::withMessages([
                'upload' => 'The upload path must not contain path traversal.',
            ]);
        }

        $expectedPrefix = 'lessons/'.$lesson->uuid.'/';
        if (! str_starts_with($normalized, $expectedPrefix)) {
            throw ValidationException::withMessages([
                'upload' => 'The upload must be stored under this lesson\'s directory.',
            ]);
        }
    }

    public function lessonDirectory(Lesson $lesson): string
    {
        return 'lessons/'.$lesson->uuid;
    }

    public function toPublicUrl(string $diskRelativePath): string
    {
        $normalized = str_replace('\\', '/', ltrim($diskRelativePath, '/'));

        return '/storage/'.$normalized;
    }

    public function toDiskRelativePath(string $publicUrl): ?string
    {
        $normalized = str_replace('\\', '/', $publicUrl);
        if (! str_starts_with($normalized, '/storage/')) {
            return null;
        }

        return substr($normalized, strlen('/storage/'));
    }

    private function authorize(Lesson $lesson, User $actor): void
    {
        if (! Gate::forUser($actor)->allows('update', $lesson)) {
            throw new AccessDeniedHttpException('You are not authorized to upload assets for this lesson.');
        }
    }

    private function validateImage(UploadedFile $file): void
    {
        // SVG is excluded deliberately: it can carry <script>, and public-disk
        // files are served from the app origin (stored XSS). Never allowlist
        // image/svg+xml even if a host's MIME DB classifies SVG as an image.
        $validator = Validator::make(
            ['file' => $file],
            [
                'file' => [
                    'required',
                    File::types(config('lesson-assets.image_extensions'))
                        ->max(config('lesson-assets.image_max_kb')),
                ],
            ],
            [
                'file.required' => 'An image file is required.',
            ]
        );

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        // Prefer finfo over UploadedFile::getMimeType() — test fakes and some
        // clients report MIME from the extension, which would accept an
        // executable renamed to .png.
        $mime = $this->detectContentMime($file);
        $allowedMimes = [
            'image/png',
            'image/jpeg',
            'image/jpg',
            'image/webp',
            'image/gif',
        ];

        if ($mime === 'image/svg+xml' || str_contains($mime, 'svg') || ! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'file' => ['The file must be a PNG, JPEG, WebP, or GIF image (SVG is not allowed).'],
            ]);
        }
    }

    private function detectContentMime(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return '';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) ? strtolower($mime) : '';
    }

    private function validateDocument(UploadedFile $file): void
    {
        // Macro-enabled Office formats (docm/xlsm/pptm) are not in the
        // allowlist — nothing a student lesson needs, real risk if enabled.
        $maxKb = (int) config('lesson-assets.document_max_kb');
        $validator = Validator::make(
            ['file' => $file],
            [
                'file' => [
                    'required',
                    File::types(config('lesson-assets.document_extensions'))
                        ->max($maxKb),
                ],
            ],
            [
                'file.required' => 'A document file is required.',
            ]
        );

        if (! $validator->fails()) {
            return;
        }

        // Some hosts report OOXML as zip/octet-stream. Accept only when the
        // client extension is an allowlisted non-macro type AND the zip
        // contains the expected package part — never trust extension alone.
        if ($this->passesOoxmlFallback($file, $maxKb)) {
            return;
        }

        throw ValidationException::withMessages($validator->errors()->toArray());
    }

    private function passesOoxmlFallback(UploadedFile $file, int $maxKb): bool
    {
        if ($file->getSize() !== false && (int) $file->getSize() > $maxKb * 1024) {
            return false;
        }

        $ext = strtolower((string) $file->getClientOriginalExtension());
        $requiredPart = match ($ext) {
            'docx' => 'word/document.xml',
            'xlsx' => 'xl/workbook.xml',
            'pptx' => 'ppt/presentation.xml',
            default => null,
        };

        if ($requiredPart === null) {
            return false;
        }

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            return false;
        }

        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return false;
        }

        $found = $zip->locateName($requiredPart) !== false;
        $zip->close();

        return $found;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function validatedExtension(UploadedFile $file, array $allowed): string
    {
        $guessed = strtolower((string) $file->guessExtension());
        $client = strtolower((string) $file->getClientOriginalExtension());

        $extension = in_array($guessed, $allowed, true)
            ? $guessed
            : (in_array($client, $allowed, true) ? $client : '');

        if ($extension === '') {
            throw ValidationException::withMessages([
                'file' => 'The file type is not allowed.',
            ]);
        }

        // Normalize jpeg → jpg for stable URLs when both are allowlisted.
        if ($extension === 'jpeg' && in_array('jpg', $allowed, true)) {
            return 'jpg';
        }

        return $extension;
    }

    private function storePermanently(Lesson $lesson, UploadedFile $file, string $extension): string
    {
        // Destination is always derived from the lesson uuid — never from
        // Filament/Livewire form state naming a directory.
        $directory = $this->lessonDirectory($lesson);
        $filename = Str::lower((string) Str::ulid()).'.'.$extension;

        $path = $file->storeAs($directory, $filename, self::DISK);

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'file' => 'The file could not be stored.',
            ]);
        }

        $this->assertDiskPathBelongsToLesson($lesson, $path);

        return $path;
    }
}
