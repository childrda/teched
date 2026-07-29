<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;

class ImageBlock extends AbstractBlockType
{
    public function key(): string
    {
        return 'image';
    }

    public function label(): string
    {
        return 'Image';
    }

    public function isAutoGradable(): bool
    {
        return false;
    }

    public function collectsResponse(): bool
    {
        return false;
    }

    public function configRules(): array
    {
        return [
            'url' => ['required', 'string', 'url:http,https'],
            'alt' => ['required', 'string'],
            'caption' => ['nullable', 'string'],
            'long_description' => ['nullable', 'string'],
        ];
    }

    public function defaultConfig(): array
    {
        return [
            'url' => 'https://example.com/placeholder.png',
            'alt' => 'Placeholder image',
            'caption' => null,
            'long_description' => null,
        ];
    }

    public function compileConfig(array $validatedConfig): array
    {
        return [
            'url' => $validatedConfig['url'],
            'alt' => $validatedConfig['alt'],
            'caption' => $validatedConfig['caption'] ?? null,
            'long_description' => $validatedConfig['long_description'] ?? null,
        ];
    }
}
