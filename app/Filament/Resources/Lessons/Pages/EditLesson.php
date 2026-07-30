<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Exceptions\AuthoringValidationException;
use App\Exceptions\StaleLessonEditException;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\User;
use App\Services\LessonAuthoringService;
use App\Services\LessonContentDuplicator;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
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
            Action::make('preview')
                ->label('Preview as Student')
                ->url(fn (): string => route('authoring.lessons.preview', $this->getRecord()))
                ->openUrlInNewTab()
                ->visible(fn (): bool => Gate::allows('preview', $this->getRecord())),
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
            Action::make('duplicate')
                ->label('Duplicate lesson')
                ->requiresConfirmation()
                ->visible(fn (): bool => Gate::allows('duplicate', $this->getRecord()))
                ->action(function (): void {
                    try {
                        $copy = app(LessonContentDuplicator::class)
                            ->duplicateLesson($this->getRecord(), Auth::user());
                        Notification::make()
                            ->title('Lesson duplicated')
                            ->body("Draft {$copy->code} created.")
                            ->success()
                            ->send();
                        $this->redirect(LessonResource::getUrl('edit', ['record' => $copy]));
                    } catch (AuthoringValidationException $e) {
                        Notification::make()
                            ->title('Duplicate failed')
                            ->body(implode("\n", $e->errors))
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('duplicatePage')
                ->label('Duplicate page')
                ->visible(fn (): bool => Gate::allows('update', $this->getRecord()))
                ->form([
                    Select::make('page_id')
                        ->label('Page to duplicate')
                        ->options(fn () => $this->getRecord()->pages()->pluck('title', 'page_id'))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $page = LessonPage::query()
                        ->where('lesson_id', $this->getRecord()->getKey())
                        ->where('page_id', $data['page_id'])
                        ->firstOrFail();
                    app(LessonContentDuplicator::class)->duplicatePageWithin($this->getRecord(), $page);
                    Notification::make()->title('Page duplicated')->success()->send();
                    $this->fillForm();
                }),
            Action::make('duplicateBlock')
                ->label('Duplicate block')
                ->visible(fn (): bool => Gate::allows('update', $this->getRecord()))
                ->form([
                    Select::make('block_id')
                        ->label('Block to duplicate')
                        ->options(function () {
                            return $this->getRecord()->pages()->with('blocks')->get()
                                ->flatMap(fn (LessonPage $page) => $page->blocks->mapWithKeys(
                                    fn (LessonBlock $block) => [
                                        $block->block_id => $page->title.' / '.$block->type->value,
                                    ]
                                ))
                                ->all();
                        })
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $block = LessonBlock::query()
                        ->where('block_id', $data['block_id'])
                        ->whereHas('page', fn ($q) => $q->where('lesson_id', $this->getRecord()->getKey()))
                        ->firstOrFail();
                    app(LessonContentDuplicator::class)->duplicateBlockWithin($block->page, $block);
                    Notification::make()->title('Block duplicated')->success()->send();
                    $this->fillForm();
                }),
            Action::make('copyPageInto')
                ->label('Copy page into this lesson')
                ->visible(fn (): bool => Gate::allows('update', $this->getRecord()))
                ->form([
                    Select::make('source_page_id')
                        ->label('Source page (any lesson you can view)')
                        ->options(function () {
                            $user = Auth::user();
                            $query = LessonPage::query()->with('lesson')->orderBy('title');
                            if (! $user->isAdmin()) {
                                $query->whereHas('lesson', fn ($q) => $q->where('created_by_user_id', $user->id));
                            }

                            return $query->get()->mapWithKeys(
                                fn (LessonPage $page) => [
                                    $page->id => ($page->lesson?->code ?? '?').' — '.$page->title,
                                ]
                            )->all();
                        })
                        ->required()
                        ->searchable(),
                ])
                ->action(function (array $data): void {
                    $source = LessonPage::query()->with('lesson')->findOrFail($data['source_page_id']);
                    Gate::authorize('view', $source->lesson);
                    Gate::authorize('update', $this->getRecord());
                    app(LessonContentDuplicator::class)->copyPageInto($source, $this->getRecord());
                    Notification::make()->title('Page copied into lesson')->success()->send();
                    $this->fillForm();
                }),
            Action::make('reassignOwner')
                ->label('Reassign owner')
                ->visible(fn (): bool => Gate::allows('reassignOwner', $this->getRecord()))
                ->form([
                    Select::make('new_owner_user_id')
                        ->label('New owner')
                        ->options(
                            User::query()
                                ->whereIn('role', ['teacher', 'admin'])
                                ->orderBy('name')
                                ->pluck('name', 'id')
                        )
                        ->required()
                        ->searchable(),
                ])
                ->action(function (array $data): void {
                    $newOwner = User::query()->findOrFail($data['new_owner_user_id']);
                    app(LessonAuthoringService::class)->reassignOwner(
                        $this->getRecord(),
                        $newOwner,
                        Auth::user(),
                    );
                    Notification::make()->title('Owner reassigned')->success()->send();
                    $this->refreshFormData(['updated_at']);
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
