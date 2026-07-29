<?php

namespace App\Blocks;

use App\Blocks\Contracts\BlockType;
use App\Exceptions\UnknownBlockTypeException;

class BlockTypeRegistry
{
    /** @var array<string, BlockType> */
    private array $types = [];

    public function register(BlockType $type): void
    {
        $this->types[$type->key()] = $type;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    /**
     * @throws UnknownBlockTypeException when the key is not registered
     */
    public function get(string $key): BlockType
    {
        return $this->types[$key]
            ?? throw UnknownBlockTypeException::forKey($key, array_keys($this->types));
    }

    /** @return array<string, BlockType> keyed by type key */
    public function all(): array
    {
        return $this->types;
    }
}
