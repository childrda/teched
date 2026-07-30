<?php

namespace App\Filament\Resources\SchoolClasses\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SchoolClassesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('school_year')->label('School year')->sortable(),
                IconColumn::make('active')->boolean()->sortable(),
                TextColumn::make('memberships_count')
                    ->counts('memberships')
                    ->label('Members'),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                // Default: active classes. Inactive filter recovers deactivated ones.
                TernaryFilter::make('active')
                    ->label('Status')
                    ->placeholder('Active')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive')
                    ->queries(
                        true: fn ($query) => $query->where('active', true),
                        false: fn ($query) => $query->where('active', false),
                        blank: fn ($query) => $query->where('active', true),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
