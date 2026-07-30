<?php

namespace App\Filament\LessonBlocks\Concerns;

use App\Enums\RevealPolicy;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

trait HasGradingFields
{
    /**
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    protected function gradingFields(bool $includeReveal = true): array
    {
        $fields = [
            Select::make('grading.rule')
                ->label('Grading rule')
                ->options([
                    'all_correct' => 'All correct',
                    'min_score' => 'Minimum score',
                    'completion_only' => 'Completion only',
                ])
                ->default('all_correct'),
            TextInput::make('grading.min_score')
                ->label('Minimum score')
                ->numeric()
                ->minValue(0)
                ->maxValue(100),
            Toggle::make('grading.allow_retry')
                ->label('Allow retry')
                ->default(true),
            TextInput::make('grading.max_attempts')
                ->label('Max attempts')
                ->numeric()
                ->minValue(1),
            Toggle::make('grading.show_feedback')
                ->label('Show feedback')
                ->default(true),
            Toggle::make('grading.record_first_attempt')
                ->label('Record first attempt')
                ->default(true),
            TextInput::make('grading.points')
                ->label('Points')
                ->numeric()
                ->minValue(0),
        ];

        if ($includeReveal) {
            $fields[] = Select::make('grading.reveal_policy')
                ->label('Reveal policy')
                ->options(collect(RevealPolicy::cases())->mapWithKeys(
                    fn (RevealPolicy $p) => [$p->value => str($p->name)->headline()->toString()]
                )->all())
                ->default(RevealPolicy::Never->value);
            $fields[] = Toggle::make('grading.reveal_answers')
                ->label('Reveal answers')
                ->default(false);
        }

        return [
            Section::make('Grading')
                ->collapsed()
                ->schema($fields),
        ];
    }
}
