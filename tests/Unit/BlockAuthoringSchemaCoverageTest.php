<?php

use App\Blocks\BlockTypeRegistry;
use App\Filament\LessonBlocks\BlockFormFactory;

test('every registered block type has an authoring schema', function () {
    $registry = app(BlockTypeRegistry::class);
    $factory = app(BlockFormFactory::class);

    foreach ($registry->all() as $key => $type) {
        expect($factory->hasSchema($key))
            ->toBeTrue("Missing authoring schema for registered type [{$key}]");

        $schema = $factory->schemaFor($key);
        expect($schema->type())->toBe($key)
            ->and($schema->filamentSchema())->toBeArray()
            ->and($schema->filamentSchema())->not->toBeEmpty();
    }
});
