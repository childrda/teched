<?php

namespace App\Filament\LessonBlocks\Schemas;

use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

class StaticTableAuthoringSchema implements BlockAuthoringSchema
{
    public function type(): string
    {
        return 'static_table';
    }

    public function filamentSchema(): array
    {
        return [
            TextInput::make('caption'),
            TagsInput::make('headers')
                ->placeholder('Add column header')
                ->required(),
            Repeater::make('rows')
                ->schema([
                    TagsInput::make('cells')
                        ->label('Cells (same count as headers)')
                        ->required(),
                ])
                ->defaultItems(0)
                ->columnSpanFull(),
            Toggle::make('first_column_is_header')->default(false),
        ];
    }
}
