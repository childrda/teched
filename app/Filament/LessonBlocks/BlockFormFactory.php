<?php

namespace App\Filament\LessonBlocks;

use App\Blocks\BlockTypeRegistry;
use App\Filament\LessonBlocks\Contracts\BlockAuthoringSchema;
use App\Filament\LessonBlocks\Schemas\CalloutAuthoringSchema;
use App\Filament\LessonBlocks\Schemas\CerAuthoringSchema;
use App\Filament\LessonBlocks\Schemas\FileLinkAuthoringSchema;
use App\Filament\LessonBlocks\Schemas\ImageAuthoringSchema;
use App\Filament\LessonBlocks\Schemas\ImageLabelingAuthoringSchema;
use App\Filament\LessonBlocks\Schemas\MatchingAuthoringSchema;
use App\Filament\LessonBlocks\Schemas\QuizAuthoringSchema;
use App\Filament\LessonBlocks\Schemas\RichTextAuthoringSchema;
use App\Filament\LessonBlocks\Schemas\ShortResponseAuthoringSchema;
use App\Filament\LessonBlocks\Schemas\StaticTableAuthoringSchema;
use App\Filament\LessonBlocks\Schemas\VideoAuthoringSchema;
use App\Filament\LessonBlocks\Schemas\VocabularyCardsAuthoringSchema;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Hidden;
use Illuminate\Support\Str;
use RuntimeException;

class BlockFormFactory
{
    /** @var array<string, class-string<BlockAuthoringSchema>> */
    private const SCHEMAS = [
        'rich_text' => RichTextAuthoringSchema::class,
        'image' => ImageAuthoringSchema::class,
        'video' => VideoAuthoringSchema::class,
        'file_link' => FileLinkAuthoringSchema::class,
        'callout' => CalloutAuthoringSchema::class,
        'static_table' => StaticTableAuthoringSchema::class,
        'vocabulary_cards' => VocabularyCardsAuthoringSchema::class,
        'matching' => MatchingAuthoringSchema::class,
        'image_labeling' => ImageLabelingAuthoringSchema::class,
        'short_response' => ShortResponseAuthoringSchema::class,
        'cer' => CerAuthoringSchema::class,
        'quiz' => QuizAuthoringSchema::class,
    ];

    public function __construct(private readonly BlockTypeRegistry $registry)
    {
    }

    public function hasSchema(string $type): bool
    {
        return isset(self::SCHEMAS[$type]);
    }

    public function schemaFor(string $type): BlockAuthoringSchema
    {
        $class = self::SCHEMAS[$type] ?? throw new RuntimeException(
            "No authoring schema registered for block type \"{$type}\"."
        );

        return app($class);
    }

    /**
     * @return list<Block>
     */
    public function builderBlocks(): array
    {
        $blocks = [];

        foreach ($this->registry->all() as $type) {
            $schema = $this->schemaFor($type->key());

            $blocks[] = Block::make($type->key())
                ->label($type->label())
                ->schema([
                    Hidden::make('block_id')->default(fn () => (string) Str::ulid()),
                    ...$schema->filamentSchema(),
                ]);
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    public function registeredSchemaTypes(): array
    {
        return array_keys(self::SCHEMAS);
    }
}
