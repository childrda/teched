<?php

namespace App\Filament\LessonBlocks\Schemas;

use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

class ImageAuthoringSchema implements BlockAuthoringSchema
{
    public function type(): string
    {
        return 'image';
    }

    public function filamentSchema(): array
    {
        return [
            TextInput::make('url')->label('Image URL / path')->required(),
            TextInput::make('alt')->label('Alt text')->required(),
            TextInput::make('caption')->label('Caption'),
            Textarea::make('long_description')->label('Long description')->columnSpanFull(),
        ];
    }
}
