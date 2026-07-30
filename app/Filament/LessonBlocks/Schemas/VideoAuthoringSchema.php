<?php

namespace App\Filament\LessonBlocks\Schemas;

use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Str;

class VideoAuthoringSchema implements BlockAuthoringSchema
{
    public function type(): string
    {
        return 'video';
    }

    public function filamentSchema(): array
    {
        return [
            Select::make('provider')
                ->options(['youtube' => 'YouTube'])
                ->default('youtube')
                ->required(),
            TextInput::make('video_id')->label('Video ID')->required(),
            TextInput::make('title'),
            Textarea::make('instructions')->columnSpanFull(),
            Toggle::make('require_confirmation')->default(true),
            Repeater::make('focus_questions')
                ->schema([
                    Hidden::make('id')->default(fn () => (string) Str::ulid()),
                    TextInput::make('text')->required(),
                ])
                ->defaultItems(0)
                ->columnSpanFull(),
        ];
    }
}
