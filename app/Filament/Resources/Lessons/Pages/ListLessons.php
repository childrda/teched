<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Exceptions\AuthoringValidationException;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use App\Services\LessonImportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListLessons extends ListRecords
{
    protected static string $resource = LessonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Import')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => Gate::allows('create', Lesson::class))
                ->authorize(fn (): bool => Gate::allows('create', Lesson::class))
                ->modalHeading('Import lesson JSON')
                ->modalDescription('Upload a format_version 1.0 authoring package. Creates a draft you own — never publishes.')
                ->form([
                    FileUpload::make('package')
                        ->label('Lesson JSON file')
                        ->acceptedFileTypes(['application/json', 'text/json', 'text/plain'])
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    abort_unless(Gate::allows('create', Lesson::class), 403);

                    $file = $data['package'] ?? null;
                    if (is_array($file)) {
                        $file = $file[0] ?? null;
                    }

                    if (! $file instanceof TemporaryUploadedFile) {
                        Notification::make()
                            ->title('Import failed')
                            ->body('Upload a .json file.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $raw = $file->get();
                    $decoded = json_decode($raw, true);
                    if (! is_array($decoded)) {
                        Notification::make()
                            ->title('Import failed')
                            ->body('File is not valid JSON.')
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    try {
                        $result = app(LessonImportService::class)->import($decoded, Auth::user());
                    } catch (AuthoringValidationException $e) {
                        Notification::make()
                            ->title('Import rejected')
                            ->body(implode("\n", array_slice($e->errors, 0, 20)))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    if ($result['warnings'] !== []) {
                        $lines = array_map(
                            fn (array $w) => "[{$w['code']}] {$w['path']}: {$w['message']}",
                            array_slice($result['warnings'], 0, 12)
                        );
                        Notification::make()
                            ->title('Imported with warnings')
                            ->body(implode("\n", $lines))
                            ->warning()
                            ->persistent()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Lesson imported as draft')
                            ->success()
                            ->send();
                    }

                    $this->redirect(LessonResource::getUrl('edit', ['record' => $result['lesson']]));
                }),
            CreateAction::make(),
        ];
    }
}
