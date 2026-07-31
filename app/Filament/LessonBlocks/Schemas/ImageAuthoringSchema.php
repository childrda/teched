<?php

namespace App\Filament\LessonBlocks\Schemas;

use App\Filament\LessonBlocks\Concerns\HasLessonAssetUpload;
use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

class ImageAuthoringSchema implements BlockAuthoringSchema
{
    use HasLessonAssetUpload;

    public function type(): string
    {
        return 'image';
    }

    public function filamentSchema(): array
    {
        return [
            ...$this->imageAssetFields('url', 'Image URL / path'),
            TextInput::make('alt')->label('Alt text')->required(),
            TextInput::make('caption')->label('Caption'),
            Textarea::make('long_description')->label('Long description')->columnSpanFull(),
        ];
    }
}
