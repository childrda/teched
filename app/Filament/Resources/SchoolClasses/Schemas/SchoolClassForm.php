<?php

namespace App\Filament\Resources\SchoolClasses\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SchoolClassForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Class')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('school_year')
                            ->label('School year')
                            ->required()
                            ->maxLength(32)
                            ->placeholder('2026-2027'),
                        Toggle::make('active')
                            ->label('Active')
                            ->helperText('Inactive classes keep history viewable and in-progress work resumable, but block new assigned starts.')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
