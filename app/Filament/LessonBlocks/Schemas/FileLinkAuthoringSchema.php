<?php

namespace App\Filament\LessonBlocks\Schemas;

use App\Filament\LessonBlocks\Concerns\HasLessonAssetUpload;
use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;

class FileLinkAuthoringSchema implements BlockAuthoringSchema
{
    use HasLessonAssetUpload;

    public function type(): string
    {
        return 'file_link';
    }

    public function filamentSchema(): array
    {
        return [
            ...$this->documentAssetFields('url'),
            Textarea::make('description')->columnSpanFull(),
            Toggle::make('opens_in_new_tab')->default(true),
        ];
    }
}
