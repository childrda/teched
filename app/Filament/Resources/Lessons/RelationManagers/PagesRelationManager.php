<?php

namespace App\Filament\Resources\Lessons\RelationManagers;

use App\Exceptions\AuthoringValidationException;
use App\Exceptions\StaleLessonEditException;
use App\Filament\Resources\Lessons\Resources\LessonPages\LessonPageResource;
use App\Models\Lesson;
use App\Models\LessonPage;
use App\Services\LessonAuthoringService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

class PagesRelationManager extends RelationManager
{
    protected static string $relationship = 'pages';

    protected static ?string $relatedResource = LessonPageResource::class;

    protected static ?string $title = 'Pages';

    /**
     * lessons.updated_at known when this manager mounted (or after the last
     * successful table mutation). Must not be re-read via fresh() before a
     * write — that defeats the optimistic lock.
     */
    #[Locked]
    public ?string $lessonRevision = null;

    public function mount(): void
    {
        parent::mount();

        $this->lessonRevision ??= $this->getOwnerRecord()->updated_at?->toISOString();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->reorderable('position')
            ->defaultSort('position')
            ->columns([
                TextColumn::make('position')
                    ->label('Order')
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Page title')
                    ->searchable()
                    ->url(fn (LessonPage $record): string => LessonPageResource::getUrl('edit', [
                        'lesson' => $this->getOwnerRecord(),
                        'record' => $record,
                    ]))
                    ->color('primary')
                    ->weight('medium'),
                TextColumn::make('completion_type')
                    ->label('Completion')
                    ->formatStateUsing(fn ($state) => is_object($state) && property_exists($state, 'name')
                        ? str($state->name)->headline()->toString()
                        : (string) $state),
                TextColumn::make('blocks_count')
                    ->label('Blocks')
                    ->counts('blocks')
                    ->sortable(),
                TextColumn::make('estimated_minutes')
                    ->label('Minutes')
                    ->placeholder('—'),
                IconColumn::make('settings.allow_read_aloud')
                    ->label('Read-aloud')
                    ->boolean()
                    ->getStateUsing(fn (LessonPage $record): bool => (bool) ($record->settings['allow_read_aloud'] ?? false)),
                IconColumn::make('settings.show_in_nav')
                    ->label('In nav')
                    ->boolean()
                    ->getStateUsing(fn (LessonPage $record): bool => (bool) ($record->settings['show_in_nav'] ?? true)),
            ])
            ->headerActions([
                Action::make('addPage')
                    ->label('Add page')
                    ->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->action(function (): void {
                        /** @var Lesson $lesson */
                        $lesson = $this->getOwnerRecord();
                        abort_unless(Gate::allows('update', $lesson), 403);

                        try {
                            $page = app(LessonAuthoringService::class)->createPage(
                                $lesson,
                                Auth::user(),
                                $this->heldLessonRevision(),
                            );

                            $this->rememberLessonRevision($page->lesson ?? $lesson->fresh());

                            $this->redirect(LessonPageResource::getUrl('edit', [
                                'lesson' => $lesson,
                                'record' => $page,
                            ]));
                        } catch (StaleLessonEditException $e) {
                            $this->notifyStale($e);
                        }
                    }),
                Action::make('copyPageInto')
                    ->label('Copy page into this lesson')
                    ->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
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
                        /** @var Lesson $lesson */
                        $lesson = $this->getOwnerRecord();
                        Gate::authorize('update', $lesson);

                        try {
                            $copy = app(LessonAuthoringService::class)->copyPageInto(
                                $source,
                                $lesson,
                                Auth::user(),
                                $this->heldLessonRevision(),
                            );
                            $this->rememberLessonRevision($copy->lesson ?? $lesson->fresh());
                            Notification::make()->title('Page copied into lesson')->success()->send();
                        } catch (StaleLessonEditException|AuthoringValidationException $e) {
                            $this->notifyMutationFailure('Copy failed', $e);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->url(fn (LessonPage $record): string => LessonPageResource::getUrl('edit', [
                        'lesson' => $this->getOwnerRecord(),
                        'record' => $record,
                    ])),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->action(function (LessonPage $record): void {
                        /** @var Lesson $lesson */
                        $lesson = $this->getOwnerRecord();
                        abort_unless(Gate::allows('update', $lesson), 403);

                        try {
                            $copy = app(LessonAuthoringService::class)->duplicatePage(
                                $lesson,
                                $record,
                                Auth::user(),
                                $this->heldLessonRevision(),
                            );
                            $this->rememberLessonRevision($copy->lesson ?? $lesson->fresh());
                            Notification::make()->title('Page duplicated')->success()->send();
                        } catch (StaleLessonEditException|AuthoringValidationException $e) {
                            $this->notifyMutationFailure('Duplicate failed', $e);
                        }
                    }),
                Action::make('delete')
                    ->label('Delete')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (LessonPage $record): string => 'Delete page “'.$record->title.'”?')
                    ->modalDescription('Removes this page from the draft only. Published versions and student attempts keep their pinned content.')
                    ->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->action(function (LessonPage $record): void {
                        /** @var Lesson $lesson */
                        $lesson = $this->getOwnerRecord();
                        abort_unless(Gate::allows('update', $lesson), 403);

                        try {
                            app(LessonAuthoringService::class)->deletePage(
                                $lesson,
                                $record,
                                Auth::user(),
                                $this->heldLessonRevision(),
                            );
                            $this->rememberLessonRevision($lesson->fresh());
                            Notification::make()->title('Page deleted')->success()->send();
                        } catch (StaleLessonEditException $e) {
                            $this->notifyStale($e);
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }

    /**
     * @param  array<int|string>  $order  Record primary keys in the new order.
     */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        /** @var Lesson $lesson */
        $lesson = $this->getOwnerRecord();
        Gate::authorize('update', $lesson);

        $orderedPageIds = LessonPage::query()
            ->where('lesson_id', $lesson->getKey())
            ->whereIn('id', $order)
            ->get()
            ->sortBy(fn (LessonPage $page) => array_search($page->getKey(), array_values($order), true))
            ->pluck('page_id')
            ->values()
            ->all();

        try {
            $updated = app(LessonAuthoringService::class)->reorderPages(
                $lesson,
                $orderedPageIds,
                Auth::user(),
                $this->heldLessonRevision(),
            );
            $this->rememberLessonRevision($updated);
        } catch (StaleLessonEditException $e) {
            $this->notifyStale($e);
        }
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord) || Gate::allows('update', $ownerRecord);
    }

    /**
     * Revision the teacher was holding when this table loaded — not a fresh()
     * re-read of the DB, which would always match and defeat the lock.
     */
    private function heldLessonRevision(): string
    {
        $token = $this->lessonRevision ?? $this->getOwnerRecord()->updated_at?->toISOString();

        if ($token === null || $token === '') {
            throw StaleLessonEditException::make();
        }

        return $token;
    }

    private function rememberLessonRevision(Lesson $lesson): void
    {
        $this->ownerRecord = $lesson;
        $this->lessonRevision = $lesson->updated_at?->toISOString();
    }

    private function notifyStale(StaleLessonEditException $e): void
    {
        Notification::make()
            ->title('Stale edit')
            ->body($e->getMessage())
            ->danger()
            ->persistent()
            ->send();
    }

    private function notifyMutationFailure(string $title, StaleLessonEditException|AuthoringValidationException $e): void
    {
        if ($e instanceof StaleLessonEditException) {
            $this->notifyStale($e);

            return;
        }

        Notification::make()
            ->title($title)
            ->body(implode("\n", $e->errors))
            ->danger()
            ->send();
    }
}
