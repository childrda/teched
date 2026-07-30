<?php

namespace App\Filament\LessonBlocks\Contracts;

interface BlockAuthoringSchema
{
    public function type(): string;

    /**
     * Filament form components for this block type's config (+ grading when applicable).
     *
     * @return array<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    public function filamentSchema(): array;
}
