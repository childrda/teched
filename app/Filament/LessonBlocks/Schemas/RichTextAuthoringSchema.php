<?php

namespace App\Filament\LessonBlocks\Schemas;

use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use Filament\Forms\Components\RichEditor;

class RichTextAuthoringSchema implements BlockAuthoringSchema
{
    public function type(): string
    {
        return 'rich_text';
    }

    public function filamentSchema(): array
    {
        return [
            RichEditor::make('html')
                ->label('Content')
                ->columnSpanFull(),
        ];
    }
}
