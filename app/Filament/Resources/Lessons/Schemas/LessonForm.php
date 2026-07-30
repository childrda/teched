<?php

namespace App\Filament\Resources\Lessons\Schemas;

use App\Enums\PageCompletionType;
use App\Filament\LessonBlocks\BlockFormFactory;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class LessonForm
{
    public static function configure(Schema $schema): Schema
    {
        /** @var BlockFormFactory $blockForms */
        $blockForms = app(BlockFormFactory::class);

        return $schema
            ->components([
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
                Section::make('Pages')
                    ->schema([
                        Repeater::make('pages')
                            ->orderColumn(null)
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? 'Page')
                            ->schema([
                                Hidden::make('page_id')->default(fn () => (string) Str::ulid()),
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
                                Builder::make('blocks')
                                    ->blocks($blockForms->builderBlocks())
                                    ->collapsible()
                                    ->columnSpanFull(),
                            ])
                            ->defaultItems(1)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
