<?php

namespace App\Filament\LessonBlocks\Schemas;

use App\Filament\LessonBlocks\Concerns\HasGradingFields;
use App\Filament\LessonBlocks\Concerns\HasLessonAssetUpload;
use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;

class ImageLabelingAuthoringSchema implements BlockAuthoringSchema
{
    use HasGradingFields;
    use HasLessonAssetUpload;

    public function type(): string
    {
        return 'image_labeling';
    }

    public function filamentSchema(): array
    {
        $imageFields = $this->imageAssetFields('image_url', 'Image URL / path');
        // Hotspot map watches image_url live; keep that reactivity on the path field.
        $imageFields[0] = $imageFields[0]->live();

        return [
            ...$imageFields,
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
            ViewField::make('hotspot_map')
                ->label('Hotspot map')
                ->view('filament.lesson-blocks.hotspot-map')
                ->dehydrated(false)
                ->columnSpanFull(),
            Repeater::make('hotspots')
                ->schema([
                    Hidden::make('id')->default(fn () => (string) Str::ulid()),
                    TextInput::make('number')->numeric()->minValue(1)->required(),
                    TextInput::make('x_pct')
                        ->label('X %')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->required()
                        ->helperText('Precision path / fallback when the image cannot load.'),
                    TextInput::make('y_pct')
                        ->label('Y %')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->required(),
                    Select::make('answer_id')
                        ->label('Correct bank item')
                        // Container is hotspots.{itemKey}; bank is a sibling of
                        // hotspots under block data — two levels up, not one.
                        ->options(function (Get $get): array {
                            $bank = $get('../../bank') ?? [];

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
                // Add/remove only via the hotspot map component so selection
                // and coordinates stay in sync (see hotspot-editor.js).
                ->addable(false)
                ->deletable(false)
                ->defaultItems(1)
                ->live()
                ->columnSpanFull(),
            ...$this->gradingFields(includeReveal: true),
        ];
    }
}
