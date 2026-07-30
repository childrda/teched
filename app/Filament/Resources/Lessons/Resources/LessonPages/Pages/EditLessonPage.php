<?php

namespace App\Filament\Resources\Lessons\Resources\LessonPages\Pages;

use App\Exceptions\AuthoringPayloadException;
use App\Exceptions\AuthoringValidationException;
use App\Exceptions\StalePageEditException;
use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\Lessons\Resources\LessonPages\LessonPageResource;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Services\LessonAuthoringService;
use App\Services\LessonContentDuplicator;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class EditLessonPage extends EditRecord
{
    protected static string $resource = LessonPageResource::class;

    /**
     * Resolve the parent lesson without the teacher SQL scope so a foreign
     * lesson yields 403 (policy) rather than 404 (invisible record). Page
     * belonging is still enforced separately via scoped binding.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function resolveParentRecord(array $parameters): Model
    {
        $key = $parameters['lesson'] ?? null;
        $parent = Lesson::query()->find($key);

        if ($parent === null) {
            throw (new ModelNotFoundException)->setModel(Lesson::class, [$key]);
        }

        return $parent;
    }

    protected function authorizeParentRecordAccess(): void
    {
        abort_unless(Gate::allows('update', $this->getParentRecord()), 403);
    }

    public function mount(int|string $record, ?Model $parentRecord = null): void
    {
        // Livewire::test can pass parentRecord directly; HTTP requests resolve
        // it from the {lesson} route parameter via InteractsWithParentRecord.
        if ($parentRecord instanceof Lesson) {
            $this->parentRecord = $parentRecord;
            $this->authorizeParentRecordAccess();
        } else {
            $this->mountParentRecord();
        }

        parent::mount($record);

        /** @var LessonPage $page */
        $page = $this->getRecord();
        /** @var Lesson $lesson */
        $lesson = $this->getParentRecord();

        // Scoped binding is not enough on its own for a crafted URL — require
        // the resolved page to belong to the parent named in the route.
        if ((int) $page->lesson_id !== (int) $lesson->getKey()) {
            abort(404);
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var LessonPage $page */
        $page = $this->getRecord();

        return app(LessonAuthoringService::class)->toPageFormState($page);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var LessonPage $record */
        try {
            $result = app(LessonAuthoringService::class)->savePage($record, $data, Auth::user());

            if ($result['warnings'] !== []) {
                Notification::make()
                    ->title('Saved with authoring warnings')
                    ->body(implode("\n", array_slice($result['warnings'], 0, 8)))
                    ->warning()
                    ->send();
            }

            return $result['page'];
        } catch (StalePageEditException $e) {
            // Keep submitted form state — never discard or auto-merge Builder.
            Notification::make()
                ->title('Stale edit')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->actions([
                    Action::make('reload')
                        ->label('Reload page')
                        ->button()
                        ->url($this->getResource()::getUrl('edit', [
                            'lesson' => $this->getParentRecord(),
                            'record' => $this->getRecord(),
                        ])),
                ])
                ->send();

            $this->halt();
        } catch (AuthoringValidationException|AuthoringPayloadException $e) {
            $body = $e instanceof AuthoringValidationException
                ? implode("\n", $e->errors)
                : $e->getMessage();

            Notification::make()
                ->title('Could not save page')
                ->body($body)
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        /** @var LessonPage $page */
        $page = $this->getRecord();

        return $page->title;
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        /** @var Lesson $lesson */
        $lesson = $this->getParentRecord();
        /** @var LessonPage $page */
        $page = $this->getRecord();

        return $page->title.' — '.$lesson->title;
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        /** @var Lesson $lesson */
        $lesson = $this->getParentRecord();

        return [
            LessonResource::getUrl() => 'Lessons',
            LessonResource::getUrl('edit', ['record' => $lesson]) => $lesson->title,
            'Edit page',
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToLesson')
                ->label('Back to lesson')
                ->url(fn (): string => LessonResource::getUrl('edit', ['record' => $this->getParentRecord()])),
            Action::make('duplicateBlock')
                ->label('Duplicate block')
                ->visible(fn (): bool => Gate::allows('update', $this->getParentRecord()))
                ->form([
                    Select::make('block_id')
                        ->label('Block to duplicate')
                        ->options(function () {
                            /** @var LessonPage $page */
                            $page = $this->getRecord();

                            return $page->blocks()
                                ->get()
                                ->mapWithKeys(fn (LessonBlock $block) => [
                                    $block->block_id => $block->type->value,
                                ])
                                ->all();
                        })
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var LessonPage $page */
                    $page = $this->getRecord();
                    $block = LessonBlock::query()
                        ->where('lesson_page_id', $page->getKey())
                        ->where('block_id', $data['block_id'])
                        ->firstOrFail();
                    app(LessonContentDuplicator::class)->duplicateBlockWithin($page, $block);
                    Notification::make()->title('Block duplicated')->success()->send();
                    $this->fillForm();
                }),
        ];
    }
}
