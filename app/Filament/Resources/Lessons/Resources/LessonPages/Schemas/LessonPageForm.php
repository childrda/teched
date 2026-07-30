<?php

namespace App\Filament\Resources\Lessons\Resources\LessonPages\Schemas;

use App\Enums\PageCompletionType;
use App\Filament\LessonBlocks\BlockFormFactory;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LessonPageForm
{
    public static function configure(Schema $schema): Schema
    {
        /** @var BlockFormFactory $blockForms */
        $blockForms = app(BlockFormFactory::class);

        return $schema
            ->components([
                // lesson_pages.updated_at — concurrency token for this page only.
                Hidden::make('updated_at'),
                Section::make('Page')
                    ->schema([
                        TextInput::make('title')->required(),
                        Select::make('completion_type')
                            ->options(collect(PageCompletionType::cases())->mapWithKeys(
                                fn (PageCompletionType $t) => [$t->value => str($t->name)->headline()->toString()]
                            )->all())
                            ->default(PageCompletionType::View->value)
                            ->required(),
                        TextInput::make('estimated_minutes')->numeric()->minValue(0),
                        Toggle::make('settings.allow_read_aloud')
                            ->label('Allow read-aloud')
                            ->default(true),
                        Toggle::make('settings.allow_back_navigation')
                            ->default(true),
                        Toggle::make('settings.allow_skip')
                            ->default(false),
                        Toggle::make('settings.show_in_nav')
                            ->default(true),
                        Toggle::make('settings.require_all_blocks')
                            ->default(false),
                        TextInput::make('settings.minimum_score')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100),
                    ])
                    ->columns(2),
                Section::make('Blocks')
                    ->schema([
                        Builder::make('blocks')
                            ->blocks($blockForms->builderBlocks())
                            ->collapsible()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
