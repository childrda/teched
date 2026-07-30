<?php

namespace App\Filament\LessonBlocks\Schemas;

use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;

class ShortResponseAuthoringSchema implements BlockAuthoringSchema
{
    public function type(): string
    {
        return 'short_response';
    }

    public function filamentSchema(): array
    {
        return [
            RichEditor::make('prompt_html')->label('Prompt')->columnSpanFull(),
            TextInput::make('placeholder'),
            TextInput::make('min_length')->numeric()->minValue(0),
            RichEditor::make('rubric_html')->label('Rubric (author-only until publish redact)')->columnSpanFull(),
        ];
    }
}
