<?php

namespace App\Filament\LessonBlocks\Schemas;

use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

class FileLinkAuthoringSchema implements BlockAuthoringSchema
{
    public function type(): string
    {
        return 'file_link';
    }

    public function filamentSchema(): array
    {
        return [
            TextInput::make('url')->label('URL / path')->required(),
            TextInput::make('label')->required(),
            Textarea::make('description')->columnSpanFull(),
            Toggle::make('opens_in_new_tab')->default(true),
        ];
    }
}
