<?php

namespace App\Filament\Resources\Lessons\RelationManagers;

use App\Exceptions\AuthoringValidationException;
use App\Exceptions\StaleLessonEditException;
use App\Filament\Resources\Lessons\Resources\LessonPages\LessonPageResource;
use App\Models\Lesson;
use App\Models\LessonPage;
use App\Services\LessonAuthoringService;
use App\Services\LessonContentDuplicator;
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

class PagesRelationManager extends RelationManager
{
    protected static string $relationship = 'pages';

    protected static ?string $relatedResource = LessonPageResource::class;

    protected static ?string $title = 'Pages';

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
                        $lesson = $this->getOwnerRecord()->fresh();

                        try {
                            $page = app(LessonAuthoringService::class)->createPage(
                                $lesson,
                                Auth::user(),
                                $lesson->updated_at?->toISOString(),
                            );

                            $this->redirect(LessonPageResource::getUrl('edit', [
                                'lesson' => $lesson,
                                'record' => $page,
                            ]));
                        } catch (StaleLessonEditException $e) {
                            Notification::make()
                                ->title('Stale edit')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
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
                        Gate::authorize('update', $this->getOwnerRecord());
                        app(LessonContentDuplicator::class)->copyPageInto($source, $this->getOwnerRecord());
                        Notification::make()->title('Page copied into lesson')->success()->send();
                        /** @var Lesson $lesson */
                        $lesson = $this->getOwnerRecord();
                        $lesson->forceFill(['updated_by' => Auth::id()])->save();
                        $lesson->markUnpublishedChanges();
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
                        $lesson = $this->getOwnerRecord()->fresh();

                        try {
                            app(LessonAuthoringService::class)->duplicatePage(
                                $lesson,
                                $record,
                                Auth::user(),
                                $lesson->updated_at?->toISOString(),
                            );
                            Notification::make()->title('Page duplicated')->success()->send();
                        } catch (StaleLessonEditException|AuthoringValidationException $e) {
                            $body = $e instanceof AuthoringValidationException
                                ? implode("\n", $e->errors)
                                : $e->getMessage();
                            Notification::make()
                                ->title('Duplicate failed')
                                ->body($body)
                                ->danger()
                                ->send();
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
                        $lesson = $this->getOwnerRecord()->fresh();

                        try {
                            app(LessonAuthoringService::class)->deletePage(
                                $lesson,
                                $record,
                                Auth::user(),
                                $lesson->updated_at?->toISOString(),
                            );
                            Notification::make()->title('Page deleted')->success()->send();
                        } catch (StaleLessonEditException $e) {
                            Notification::make()
                                ->title('Stale edit')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
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
        $lesson = $this->getOwnerRecord()->fresh();
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
            app(LessonAuthoringService::class)->reorderPages(
                $lesson,
                $orderedPageIds,
                Auth::user(),
                $lesson->updated_at?->toISOString(),
            );
        } catch (StaleLessonEditException $e) {
            Notification::make()
                ->title('Stale edit')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord) || Gate::allows('update', $ownerRecord);
    }
}
