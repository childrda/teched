<?php

namespace App\Filament\Resources\SchoolClasses\RelationManagers;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\SchoolClass;
use App\Services\LessonAssignmentService;
use App\Support\DisplayTime;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Assignments';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lesson', 'lessonVersion'])->orderByDesc('id'))
            ->columns([
                TextColumn::make('lesson.code')->label('Code')->searchable(),
                TextColumn::make('lesson.title')->label('Lesson')->searchable(),
                TextColumn::make('lessonVersion.version')->label('Pinned v'),
                TextColumn::make('available_at')
                    ->formatStateUsing(fn ($state) => DisplayTime::toDayDateTimeString($state))
                    ->placeholder('Immediately'),
                TextColumn::make('due_at')
                    ->formatStateUsing(fn ($state) => DisplayTime::toDayDateTimeString($state))
                    ->placeholder('—'),
                TextColumn::make('archived_at')
                    ->label('Status')
                    ->formatStateUsing(fn ($state, LessonAssignment $record) => $record->isArchived() ? 'Archived' : 'Active')
                    ->badge()
                    ->color(fn (LessonAssignment $record) => $record->isArchived() ? 'gray' : 'success'),
                TextColumn::make('attempts_count')->counts('attempts')->label('Attempts'),
            ])
            ->filters([
                TernaryFilter::make('archived')
                    ->label('Status')
                    ->placeholder('Active')
                    ->trueLabel('Archived')
                    ->falseLabel('Active')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('archived_at'),
                        false: fn ($query) => $query->whereNull('archived_at'),
                        blank: fn ($query) => $query->whereNull('archived_at'),
                    ),
            ])
            ->headerActions([
                Action::make('assignLesson')
                    ->label('Assign lesson')
                    ->visible(fn (): bool => Gate::allows('manage', $this->getOwnerRecord()))
                    ->form([
                        Select::make('lesson_id')
                            ->label('Published lesson')
                            ->searchable()
                            ->required()
                            ->getSearchResultsUsing(function (string $search): array {
                                $search = trim($search);
                                $query = Lesson::query()
                                    ->where('status', LessonStatus::Published->value)
                                    ->where('current_version', '>=', 1)
                                    ->orderBy('code')
                                    ->limit(30);

                                if (mb_strlen($search) >= 1) {
                                    $query->where(function (Builder $q) use ($search) {
                                        $q->where('code', 'like', "%{$search}%")
                                            ->orWhere('title', 'like', "%{$search}%");
                                    });
                                }

                                return $query->get(['id', 'code', 'title', 'current_version'])
                                    ->mapWithKeys(fn (Lesson $lesson) => [
                                        $lesson->id => "{$lesson->code} — {$lesson->title} (v{$lesson->current_version})",
                                    ])
                                    ->all();
                            })
                            ->getOptionLabelUsing(function ($value): ?string {
                                $lesson = Lesson::query()->find($value);

                                return $lesson
                                    ? "{$lesson->code} — {$lesson->title} (v{$lesson->current_version})"
                                    : null;
                            })
                            ->helperText('Any published lesson may be assigned. The version is pinned at save time.'),
                        DateTimePicker::make('available_at')
                            ->label('Available at')
                            ->native(false),
                        DateTimePicker::make('due_at')
                            ->label('Due at')
                            ->helperText('Displayed only — not enforced yet.')
                            ->native(false),
                    ])
                    ->action(function (array $data): void {
                        $this->runAssignmentMutation(function () use ($data) {
                            /** @var SchoolClass $class */
                            $class = $this->getOwnerRecord();

                            app(LessonAssignmentService::class)->create($class, Auth::user(), [
                                'lesson_id' => $data['lesson_id'],
                                'available_at' => $data['available_at'] ?? null,
                                'due_at' => $data['due_at'] ?? null,
                            ]);
                        }, 'Assignment created');
                    }),
            ])
            ->recordActions([
                Action::make('editDates')
                    ->label('Edit dates')
                    ->visible(fn (LessonAssignment $record): bool => Gate::allows('update', $record))
                    ->form([
                        DateTimePicker::make('available_at')->label('Available at')->native(false),
                        DateTimePicker::make('due_at')->label('Due at')->native(false),
                    ])
                    ->fillForm(fn (LessonAssignment $record): array => [
                        'available_at' => $record->available_at,
                        'due_at' => $record->due_at,
                    ])
                    ->action(function (LessonAssignment $record, array $data): void {
                        $this->runAssignmentMutation(function () use ($record, $data) {
                            app(LessonAssignmentService::class)->update($record, $data);
                        }, 'Assignment updated');
                    }),
                Action::make('repin')
                    ->label('Repin to current version')
                    ->requiresConfirmation()
                    ->modalHeading('Repin assignment')
                    ->modalDescription(function (LessonAssignment $record): string {
                        $record->loadMissing(['lesson', 'lessonVersion']);
                        $current = $record->lesson->currentVersion();
                        $attempts = $record->attempts()->count();

                        return "Currently pinned: v{$record->lessonVersion?->version}. "
                            ."Current published: ".($current ? "v{$current->version}" : 'none').". "
                            ."{$attempts} existing attempt(s) stay on their pinned version; only future attempts use the new pin.";
                    })
                    ->visible(fn (LessonAssignment $record): bool => Gate::allows('update', $record))
                    ->action(function (LessonAssignment $record): void {
                        $this->runAssignmentMutation(function () use ($record) {
                            app(LessonAssignmentService::class)->repinToCurrentVersion($record, Auth::user());
                        }, 'Assignment repinned');
                    }),
                Action::make('archive')
                    ->label('Archive')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (LessonAssignment $record): bool => Gate::allows('archive', $record) && ! $record->isArchived())
                    ->action(function (LessonAssignment $record): void {
                        $this->runAssignmentMutation(function () use ($record) {
                            app(LessonAssignmentService::class)->archive($record, Auth::user());
                        }, 'Assignment archived');
                    }),
                Action::make('unarchive')
                    ->label('Unarchive')
                    ->requiresConfirmation()
                    ->visible(fn (LessonAssignment $record): bool => Gate::allows('archive', $record) && $record->isArchived())
                    ->action(function (LessonAssignment $record): void {
                        $this->runAssignmentMutation(function () use ($record) {
                            app(LessonAssignmentService::class)->unarchive($record, Auth::user());
                        }, 'Assignment unarchived');
                    }),
                Action::make('delete')
                    ->label('Delete')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (LessonAssignment $record): bool => Gate::allows('delete', $record) && ! $record->isArchived())
                    ->action(function (LessonAssignment $record): void {
                        $this->runAssignmentMutation(function () use ($record) {
                            app(LessonAssignmentService::class)->delete($record);
                        }, 'Assignment deleted');
                    }),
            ]);
    }

    private function runAssignmentMutation(callable $callback, string $successTitle): void
    {
        try {
            $callback();
            Notification::make()->title($successTitle)->success()->send();
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Could not update assignment')
                ->body(collect($e->errors())->flatten()->implode("\n"))
                ->danger()
                ->persistent()
                ->send();
        }
    }
}
