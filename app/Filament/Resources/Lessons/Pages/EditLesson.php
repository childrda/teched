<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Exceptions\AuthoringValidationException;
use App\Exceptions\StaleLessonEditException;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use App\Services\LessonAuthoringService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class EditLesson extends EditRecord
{
    protected static string $resource = LessonResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Lesson $lesson */
        $lesson = $this->getRecord();

        return app(LessonAuthoringService::class)->toFormState($lesson);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Lesson $record */
        try {
            $result = app(LessonAuthoringService::class)->save($record, $data, Auth::user());

            if ($result['warnings'] !== []) {
                Notification::make()
                    ->title('Saved with authoring warnings')
                    ->body(implode("\n", array_slice($result['warnings'], 0, 8)))
                    ->warning()
                    ->send();
            }

            return $result['lesson'];
        } catch (StaleLessonEditException $e) {
            Notification::make()
                ->title('Stale edit')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        } catch (AuthoringValidationException $e) {
            Notification::make()
                ->title('Could not save draft')
                ->body(implode("\n", $e->errors))
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label('Publish')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Publish lesson')
                ->modalDescription(function (): string {
                    /** @var Lesson $lesson */
                    $lesson = $this->getRecord();
                    $current = (int) $lesson->current_version;
                    $next = $current + 1;

                    return "This creates version {$next}"
                        .($current > 0 ? " (current live version is {$current})" : '')
                        .'. Students mid-attempt stay pinned to their existing version. '
                        .'Every successful publish creates a new version, even when the manifest is unchanged.';
                })
                ->visible(fn (): bool => Gate::allows('publish', $this->getRecord()))
                ->action(function (): void {
                    $this->save(shouldRedirect: false);

                    /** @var Lesson $lesson */
                    $lesson = $this->getRecord()->fresh();

                    try {
                        $version = app(LessonAuthoringService::class)->publish($lesson, Auth::user());
                        Notification::make()
                            ->title("Published version {$version->version}")
                            ->success()
                            ->send();
                        $this->refreshFormData(['updated_at', 'status', 'current_version', 'has_unpublished_changes']);
                        $this->fillForm();
                    } catch (AuthoringValidationException $e) {
                        Notification::make()
                            ->title('Publish blocked')
                            ->body(implode("\n", $e->errors))
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
            Action::make('archive')
                ->label('Archive')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Archive lesson')
                ->modalDescription('New student access is blocked. Versions, assignments, and attempts are preserved. The lesson remains editable; unarchive before publishing again.')
                ->visible(fn (): bool => Gate::allows('archive', $this->getRecord()))
                ->action(function (): void {
                    app(LessonAuthoringService::class)->archive($this->getRecord(), Auth::user());
                    Notification::make()->title('Lesson archived')->success()->send();
                    $this->refreshFormData(['status', 'updated_at']);
                    $this->fillForm();
                }),
            Action::make('unarchive')
                ->label('Unarchive')
                ->requiresConfirmation()
                ->visible(fn (): bool => Gate::allows('unarchive', $this->getRecord()))
                ->action(function (): void {
                    app(LessonAuthoringService::class)->unarchive($this->getRecord(), Auth::user());
                    Notification::make()->title('Lesson unarchived')->success()->send();
                    $this->refreshFormData(['status', 'updated_at']);
                    $this->fillForm();
                }),
            DeleteAction::make()
                ->visible(fn (): bool => Gate::allows('delete', $this->getRecord())),
        ];
    }
}
