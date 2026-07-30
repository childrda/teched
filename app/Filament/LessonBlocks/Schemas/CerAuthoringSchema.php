<?php

namespace App\Filament\LessonBlocks\Schemas;

use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

class CerAuthoringSchema implements BlockAuthoringSchema
{
    public function type(): string
    {
        return 'cer';
    }

    public function filamentSchema(): array
    {
        return [
            RichEditor::make('scenario_html')->label('Scenario')->columnSpanFull(),
            Repeater::make('fields')
                ->schema([
                    Hidden::make('id')->default(fn () => (string) Str::ulid()),
                    TextInput::make('label')->required(),
                    TextInput::make('placeholder'),
                    TextInput::make('min_length')->numeric()->minValue(0),
                ])
                ->defaultItems(3)
                ->columnSpanFull(),
        ];
    }
}
