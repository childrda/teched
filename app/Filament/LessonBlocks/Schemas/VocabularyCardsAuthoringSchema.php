<?php

namespace App\Filament\LessonBlocks\Schemas;

use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

class VocabularyCardsAuthoringSchema implements BlockAuthoringSchema
{
    public function type(): string
    {
        return 'vocabulary_cards';
    }

    public function filamentSchema(): array
    {
        return [
            Select::make('reveal_mode')
                ->options(['tap' => 'Tap to reveal', 'always' => 'Always shown'])
                ->default('tap')
                ->required(),
            Repeater::make('terms')
                ->schema([
                    Hidden::make('id')->default(fn () => (string) Str::ulid()),
                    TextInput::make('term')->required(),
                    Textarea::make('definition')->required()->columnSpanFull(),
                    Textarea::make('analogy')->columnSpanFull(),
                ])
                ->defaultItems(1)
                ->columnSpanFull(),
        ];
    }
}
