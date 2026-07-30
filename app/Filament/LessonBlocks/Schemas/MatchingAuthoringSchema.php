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

class MatchingAuthoringSchema implements BlockAuthoringSchema
{
    use HasGradingFields;

    public function type(): string
    {
        return 'matching';
    }

    public function filamentSchema(): array
    {
        return [
            Textarea::make('instructions')->columnSpanFull(),
            Toggle::make('shuffle')->default(true),
            Repeater::make('bank')
                ->label('Bank items')
                ->schema([
                    Hidden::make('id')->default(fn () => (string) Str::ulid()),
                    TextInput::make('label')->required(),
                ])
                ->defaultItems(2)
                ->live()
                ->columnSpanFull(),
            Repeater::make('slots')
                ->schema([
                    Hidden::make('id')->default(fn () => (string) Str::ulid()),
                    TextInput::make('description')->required(),
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
                ])
                ->defaultItems(2)
                ->columnSpanFull(),
            ...$this->gradingFields(includeReveal: true),
        ];
    }
}
