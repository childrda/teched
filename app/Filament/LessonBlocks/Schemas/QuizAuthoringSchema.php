<?php

namespace App\Filament\LessonBlocks\Schemas;

use App\Filament\LessonBlocks\Concerns\HasGradingFields;
use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;

class QuizAuthoringSchema implements BlockAuthoringSchema
{
    use HasGradingFields;

    public function type(): string
    {
        return 'quiz';
    }

    public function filamentSchema(): array
    {
        return [
            Toggle::make('shuffle_questions')->default(false),
            Repeater::make('questions')
                ->schema([
                    Hidden::make('id')->default(fn () => (string) Str::ulid()),
                    Textarea::make('prompt')->required()->columnSpanFull(),
                    Repeater::make('options')
                        ->schema([
                            Hidden::make('id')->default(fn () => (string) Str::ulid()),
                            TextInput::make('text')->required(),
                        ])
                        ->defaultItems(2)
                        ->live()
                        ->columnSpanFull(),
                    Select::make('answer_id')
                        ->label('Correct option')
                        ->options(function (Get $get): array {
                            $options = $get('options') ?? [];

                            return collect(is_array($options) ? $options : [])
                                ->filter(fn ($item) => is_array($item) && filled($item['id'] ?? null))
                                ->mapWithKeys(fn (array $item) => [
                                    $item['id'] => $item['text'] ?: $item['id'],
                                ])
                                ->all();
                        })
                        ->required()
                        ->searchable(),
                    Textarea::make('feedback')->columnSpanFull(),
                    TextInput::make('source_ref.page')->label('Source page'),
                    Textarea::make('source_ref.excerpt')->label('Source excerpt')->columnSpanFull(),
                ])
                ->defaultItems(1)
                ->columnSpanFull(),
            ...$this->gradingFields(includeReveal: true),
        ];
    }
}
