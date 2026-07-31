<?php

namespace App\Filament\LessonBlocks\Concerns;

use App\Models\Lesson;
use App\Rules\LessonScopedAssetUrl;
use App\Services\LessonAssetService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

trait HasLessonAssetUpload
{
    /**
     * Manual path + temporary upload control. The upload field is never
     * dehydrated into block config; LessonAssetService writes the canonical
     * /storage/... URL into $urlField.
     *
     * @return list<\Filament\Forms\Components\Component>
     */
    protected function imageAssetFields(string $urlField, string $urlLabel): array
    {
        return [
            TextInput::make($urlField)
                ->label($urlLabel)
                ->required()
                ->helperText('Upload below, or enter a same-lesson /storage/... path or a /lessons/... fixture path. Uploaded files are public — do not put sensitive material in them.')
                ->rule(fn (TextInput $component): LessonScopedAssetUrl => new LessonScopedAssetUrl(
                    $this->resolveLessonFromComponent($component)->uuid
                ))
                ->live(onBlur: true),
            FileUpload::make($urlField.'_upload')
                ->label('Upload image')
                ->disk(LessonAssetService::DISK)
                // Directory is display/default only — storeFiles(false) + the
                // service ignore any client-forged destination.
                ->directory(fn (FileUpload $component): string => app(LessonAssetService::class)
                    ->lessonDirectory($this->resolveLessonFromComponent($component)))
                ->acceptedFileTypes([
                    'image/png',
                    'image/jpeg',
                    'image/webp',
                    'image/gif',
                ])
                ->maxSize(config('lesson-assets.image_max_kb'))
                ->storeFiles(false)
                ->dehydrated(false)
                ->helperText('PNG, JPEG, WebP, or GIF. SVG is not allowed. Replacing an image keeps the previous file for published versions.')
                ->afterStateUpdated(function (mixed $state, Set $set, FileUpload $component) use ($urlField): void {
                    $this->consumeImageUpload($state, $set, $component, $urlField);
                }),
        ];
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    protected function documentAssetFields(string $urlField = 'url'): array
    {
        return [
            TextInput::make($urlField)
                ->label('URL / path')
                ->required()
                ->helperText('Upload below, or enter a same-lesson /storage/... path or a /lessons/... fixture path. Uploaded files are public — do not put sensitive material in them.')
                ->rule(fn (TextInput $component): LessonScopedAssetUrl => new LessonScopedAssetUrl(
                    $this->resolveLessonFromComponent($component)->uuid
                )),
            TextInput::make('label')->required(),
            FileUpload::make($urlField.'_upload')
                ->label('Upload document')
                ->disk(LessonAssetService::DISK)
                ->directory(fn (FileUpload $component): string => app(LessonAssetService::class)
                    ->lessonDirectory($this->resolveLessonFromComponent($component)))
                ->acceptedFileTypes([
                    'application/pdf',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                ])
                ->maxSize(config('lesson-assets.document_max_kb'))
                ->storeFiles(false)
                ->dehydrated(false)
                ->helperText('PDF, DOCX, XLSX, or PPTX. Macro-enabled Office formats are not allowed.')
                ->afterStateUpdated(function (mixed $state, Set $set, Get $get, FileUpload $component) use ($urlField): void {
                    $this->consumeDocumentUpload($state, $set, $get, $component, $urlField);
                }),
        ];
    }

    private function consumeImageUpload(mixed $state, Set $set, FileUpload $component, string $urlField): void
    {
        $file = $this->firstUploadState($state);
        if ($file === null) {
            return;
        }

        $lesson = $this->resolveLessonFromComponent($component);
        $service = app(LessonAssetService::class);

        try {
            if (is_string($file)) {
                $service->assertDiskPathBelongsToLesson($lesson, $file);
                $set($urlField, $service->toPublicUrl($file));
                $set($urlField.'_upload', null);

                return;
            }

            if (! $file instanceof TemporaryUploadedFile) {
                throw ValidationException::withMessages([
                    $urlField.'_upload' => 'Unrecognized upload state.',
                ]);
            }

            $url = $service->storeImage($lesson, $file, Auth::user());
            $set($urlField, $url);
            $set($urlField.'_upload', null);
        } catch (AccessDeniedHttpException $e) {
            $this->notifyUploadFailure($e->getMessage());
            $set($urlField.'_upload', null);
        } catch (ValidationException $e) {
            $this->notifyUploadFailure(collect($e->errors())->flatten()->first() ?: 'Upload failed.');
            $set($urlField.'_upload', null);
        }
    }

    private function consumeDocumentUpload(mixed $state, Set $set, Get $get, FileUpload $component, string $urlField): void
    {
        $file = $this->firstUploadState($state);
        if ($file === null) {
            return;
        }

        $lesson = $this->resolveLessonFromComponent($component);
        $service = app(LessonAssetService::class);

        try {
            if (is_string($file)) {
                $service->assertDiskPathBelongsToLesson($lesson, $file);
                $set($urlField, $service->toPublicUrl($file));
                $set($urlField.'_upload', null);

                return;
            }

            if (! $file instanceof TemporaryUploadedFile) {
                throw ValidationException::withMessages([
                    $urlField.'_upload' => 'Unrecognized upload state.',
                ]);
            }

            $stored = $service->storeDocument($lesson, $file, Auth::user());
            $set($urlField, $stored->url);
            if (blank($get('label'))) {
                $set('label', $stored->originalName);
            }
            $set($urlField.'_upload', null);
        } catch (AccessDeniedHttpException $e) {
            $this->notifyUploadFailure($e->getMessage());
            $set($urlField.'_upload', null);
        } catch (ValidationException $e) {
            $this->notifyUploadFailure(collect($e->errors())->flatten()->first() ?: 'Upload failed.');
            $set($urlField.'_upload', null);
        }
    }

    private function firstUploadState(mixed $state): mixed
    {
        if ($state === null || $state === [] || $state === '') {
            return null;
        }

        if (is_array($state)) {
            return array_values($state)[0] ?? null;
        }

        return $state;
    }

    private function resolveLessonFromComponent(TextInput|FileUpload $component): Lesson
    {
        $livewire = $component->getLivewire();

        if (method_exists($livewire, 'getParentRecord')) {
            $parent = $livewire->getParentRecord();
            if ($parent instanceof Lesson) {
                return $parent;
            }
        }

        throw new RuntimeException('Lesson asset fields require a parent lesson on the Livewire page.');
    }

    private function notifyUploadFailure(string $message): void
    {
        Notification::make()
            ->title('Upload failed')
            ->body($message)
            ->danger()
            ->send();
    }
}
