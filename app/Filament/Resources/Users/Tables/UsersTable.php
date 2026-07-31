<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Services\UserAccountService;
use App\Support\DisplayTime;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('role')->badge()->sortable(),
                TextColumn::make('sign_in')
                    ->label('Sign-in')
                    ->state(function (User $record): string {
                        if ($record->deactivated_at !== null) {
                            return 'Deactivated';
                        }

                        if (app(UserAccountService::class)->awaitingGoogleSignIn($record)) {
                            return 'Awaiting Google';
                        }

                        if ($record->google_id !== null && $record->password !== null) {
                            return 'Google + local';
                        }

                        if ($record->google_id !== null) {
                            return 'Google';
                        }

                        return 'Local';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Awaiting Google' => 'warning',
                        'Deactivated' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('deactivated_at')
                    ->label('Deactivated')
                    ->formatStateUsing(fn ($state) => DisplayTime::toDayDateTimeString($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->formatStateUsing(fn ($state) => DisplayTime::toDayDateTimeString($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('role')
                    ->options([
                        'teacher' => 'Teacher',
                        'admin' => 'Admin',
                        'student' => 'Student',
                    ]),
                TernaryFilter::make('deactivated')
                    ->label('Status')
                    ->placeholder('Active')
                    ->trueLabel('Deactivated')
                    ->falseLabel('Active')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('deactivated_at'),
                        false: fn ($query) => $query->whereNull('deactivated_at'),
                        blank: fn ($query) => $query->whereNull('deactivated_at'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
