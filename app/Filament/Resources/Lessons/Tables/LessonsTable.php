<?php

namespace App\Filament\Resources\Lessons\Tables;

use App\Models\Lesson;
use App\Support\DisplayTime;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class LessonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('library')
                    ->label('Source')
                    ->state(function (Lesson $record): string {
                        $user = Auth::user();
                        if ($user === null || $user->isAdmin()) {
                            return '—';
                        }

                        return (int) $record->created_by_user_id === (int) $user->getKey()
                            ? 'Mine'
                            : 'District library';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'District library' => 'info',
                        'Mine' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('current_version')->label('Version')->sortable(),
                IconColumn::make('has_unpublished_changes')
                    ->label('Unpublished')
                    ->boolean(),
                TextColumn::make('creator.name')->label('Owner')->toggleable(),
                TextColumn::make('access')
                    ->label('Access')
                    ->state(function (Lesson $record): string {
                        return Gate::allows('update', $record) ? 'Editable' : 'Read-only';
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Editable' ? 'success' : 'gray'),
                TextColumn::make('updated_at')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => DisplayTime::toDayDateTimeString($state)),
            ])
            ->recordActions([
                Action::make('previewPublished')
                    ->label('Preview published')
                    ->url(fn (Lesson $record): string => route('authoring.lessons.preview-published', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Lesson $record): bool => Gate::allows('previewPublished', $record))
                    ->authorize(fn (Lesson $record): bool => Gate::allows('previewPublished', $record)),
                EditAction::make()
                    ->visible(fn (Lesson $record): bool => Gate::allows('update', $record))
                    ->authorize(fn (Lesson $record): bool => Gate::allows('update', $record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
