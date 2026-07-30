<?php

namespace App\Filament\LessonBlocks\Schemas;

use App\Filament\LessonBlocks\Concerns\HasGradingFields;
use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Illuminate\Support\Str;

class ImageLabelingAuthoringSchema implements BlockAuthoringSchema
{
    use HasGradingFields;

    public function type(): string
    {
        return 'image_labeling';
    }

    public function filamentSchema(): array
    {
        return [
            TextInput::make('image_url')->label('Image URL / path')->required(),
            TextInput::make('image_alt')->label('Alt text')->required(),
            Textarea::make('long_description')->columnSpanFull(),
            Textarea::make('instructions')->columnSpanFull(),
            Repeater::make('bank')
                ->label('Bank items')
                ->schema([
                    Hidden::make('id')->default(fn () => (string) Str::ulid()),
                    TextInput::make('label')->required(),
                ])
                ->defaultItems(1)
                ->live()
                ->columnSpanFull(),
            Repeater::make('hotspots')
                ->schema([
                    Hidden::make('id')->default(fn () => (string) Str::ulid()),
                    TextInput::make('number')->numeric()->minValue(1)->required(),
                    TextInput::make('x_pct')
                        ->label('X % (temporary — hotspot editor is 5B)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->required(),
                    TextInput::make('y_pct')
                        ->label('Y % (temporary — hotspot editor is 5B)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->required(),
                    Select::make('answer_id')
                        ->label('Correct bank item')
                        ->options(function (Get $get): array {
                            $bank = $get('../bank') ?? [];

                            return collect(is_array($bank) ? $bank : [])
                                ->filter(fn ($item) => is_array($item) && filled($item['id'] ?? null))
                                ->mapWithKeys(fn (array $item) => [
                                    $item['id'] => $item['label'] ?: $item['id'],
                                ])
                                ->all();
                        })
                        ->required()
                        ->searchable(),
                    Textarea::make('description'),
                ])
                ->defaultItems(1)
                ->columnSpanFull(),
            ...$this->gradingFields(includeReveal: true),
        ];
    }
}
