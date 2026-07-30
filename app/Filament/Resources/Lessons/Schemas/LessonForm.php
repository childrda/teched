<?php

namespace App\Filament\Resources\Lessons\Schemas;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LessonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // lessons.updated_at — concurrency token for metadata + page order.
                Hidden::make('updated_at'),
                Section::make('Lesson')
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(64),
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->columnSpanFull(),
                        TextInput::make('subject'),
                        TextInput::make('grade_range'),
                        TextInput::make('estimated_minutes')
                            ->numeric()
                            ->minValue(0),
                        Textarea::make('learning_target')
                            ->columnSpanFull(),
                        Textarea::make('success_criteria')
                            ->label('Success criteria (one per line)')
                            ->columnSpanFull(),
                        Textarea::make('standards')
                            ->label('Standards (one per line)')
                            ->columnSpanFull(),
                        Toggle::make('settings.default_allow_read_aloud')
                            ->label('Default allow read-aloud for new pages')
                            ->helperText('Applies only when a page is created; existing pages are unchanged.')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
