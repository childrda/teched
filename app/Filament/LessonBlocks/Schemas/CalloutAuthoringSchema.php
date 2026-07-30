<?php

namespace App\Filament\LessonBlocks\Schemas;

use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class CalloutAuthoringSchema implements BlockAuthoringSchema
{
    public function type(): string
    {
        return 'callout';
    }

    public function filamentSchema(): array
    {
        return [
            Select::make('style')
                ->options([
                    'info' => 'Info',
                    'warning' => 'Warning',
                    'tip' => 'Tip',
                ])
                ->default('info')
                ->required(),
            TextInput::make('heading'),
            RichEditor::make('html')->label('Content')->columnSpanFull(),
        ];
    }
}
