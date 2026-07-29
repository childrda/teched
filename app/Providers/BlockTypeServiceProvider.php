<?php

namespace App\Providers;

use App\Blocks\BlockTypeRegistry;
use App\Blocks\Types\CalloutBlock;
use App\Blocks\Types\CerBlock;
use App\Blocks\Types\FileLinkBlock;
use App\Blocks\Types\ImageBlock;
use App\Blocks\Types\ImageLabelingBlock;
use App\Blocks\Types\MatchingBlock;
use App\Blocks\Types\QuizBlock;
use App\Blocks\Types\RichTextBlock;
use App\Blocks\Types\ShortResponseBlock;
use App\Blocks\Types\StaticTableBlock;
use App\Blocks\Types\VideoBlock;
use App\Blocks\Types\VocabularyCardsBlock;
use Illuminate\Support\ServiceProvider;

class BlockTypeServiceProvider extends ServiceProvider
{
    /**
     * Adding a new block type requires only a new class plus an entry here.
     */
    private const BLOCK_TYPES = [
        RichTextBlock::class,
        ImageBlock::class,
        VideoBlock::class,
        FileLinkBlock::class,
        CalloutBlock::class,
        StaticTableBlock::class,
        VocabularyCardsBlock::class,
        MatchingBlock::class,
        ImageLabelingBlock::class,
        ShortResponseBlock::class,
        CerBlock::class,
        QuizBlock::class,
    ];

    public function register(): void
    {
        $this->app->singleton(BlockTypeRegistry::class, function ($app) {
            $registry = new BlockTypeRegistry();

            foreach (self::BLOCK_TYPES as $class) {
                $registry->register($app->make($class));
            }

            return $registry;
        });
    }
}
