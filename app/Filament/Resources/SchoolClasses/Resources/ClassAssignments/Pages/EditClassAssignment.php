<?php

namespace App\Filament\Resources\SchoolClasses\Resources\ClassAssignments\Pages;

use App\Filament\Resources\SchoolClasses\Resources\ClassAssignments\ClassAssignmentResource;
use App\Filament\Resources\SchoolClasses\SchoolClassResource;
use App\Models\LessonAssignment;
use App\Models\SchoolClass;
use App\Services\LessonAssignmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditClassAssignment extends EditRecord
{
    protected static string $resource = ClassAssignmentResource::class;

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function resolveParentRecord(array $parameters): Model
    {
        $key = $parameters['school_class'] ?? null;
        $parent = SchoolClass::query()->find($key);

        if ($parent === null) {
            throw (new ModelNotFoundException)->setModel(SchoolClass::class, [$key]);
        }

        return $parent;
    }

    protected function authorizeParentRecordAccess(): void
    {
        abort_unless(Gate::allows('manage', $this->getParentRecord()), 403);
    }

    public function mount(int|string $record, ?Model $parentRecord = null): void
    {
        if ($parentRecord instanceof SchoolClass) {
            $this->parentRecord = $parentRecord;
            $this->authorizeParentRecordAccess();
        } else {
            $this->mountParentRecord();
        }

        parent::mount($record);

        /** @var LessonAssignment $assignment */
        $assignment = $this->getRecord();
        /** @var SchoolClass $class */
        $class = $this->getParentRecord();

        if ((int) $assignment->school_class_id !== (int) $class->getKey()) {
            abort(404);
        }
    }

    public function form(Schema $schema): Schema
    {
        /** @var LessonAssignment $assignment */
        $assignment = $this->getRecord();
        $assignment->loadMissing(['lesson', 'lessonVersion']);

        return $schema->components([
            Placeholder::make('lesson')
                ->label('Lesson')
                ->content(fn () => $assignment->lesson?->code.' — '.$assignment->lesson?->title),
            Placeholder::make('pinned')
                ->label('Pinned version')
                ->content(fn () => 'v'.($assignment->lessonVersion?->version ?? '?')),
            DateTimePicker::make('available_at')
                ->label('Available at')
                ->native(false)
                ->disabled(fn (): bool => $this->getRecord()->isArchived()),
            DateTimePicker::make('due_at')
                ->label('Due at')
                ->native(false)
                ->disabled(fn (): bool => $this->getRecord()->isArchived()),
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var LessonAssignment $record */
        if ($record->isArchived()) {
            Notification::make()
                ->title('Archived assignment is read-only')
                ->body('Unarchive it before editing dates.')
                ->warning()
                ->send();

            return $record;
        }

        try {
            return app(LessonAssignmentService::class)->update($record, [
                'available_at' => $data['available_at'] ?? null,
                'due_at' => $data['due_at'] ?? null,
            ]);
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Could not save assignment')
                ->body(collect($e->errors())->flatten()->implode("\n"))
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to class')
                ->url(fn (): string => SchoolClassResource::getUrl('edit', ['record' => $this->getParentRecord()])),
            Action::make('archive')
                ->label('Archive')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => Gate::allows('archive', $this->getRecord()) && ! $this->getRecord()->isArchived())
                ->action(function (): void {
                    try {
                        app(LessonAssignmentService::class)->archive($this->getRecord(), Auth::user());
                        Notification::make()->title('Assignment archived')->success()->send();
                        $this->record = $this->getRecord()->fresh();
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Could not archive')
                            ->body(collect($e->errors())->flatten()->implode("\n"))
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('unarchive')
                ->label('Unarchive')
                ->requiresConfirmation()
                ->visible(fn (): bool => Gate::allows('archive', $this->getRecord()) && $this->getRecord()->isArchived())
                ->action(function (): void {
                    try {
                        app(LessonAssignmentService::class)->unarchive($this->getRecord(), Auth::user());
                        Notification::make()->title('Assignment unarchived')->success()->send();
                        $this->record = $this->getRecord()->fresh();
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Could not unarchive')
                            ->body(collect($e->errors())->flatten()->implode("\n"))
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
