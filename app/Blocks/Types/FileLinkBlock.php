<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;

class FileLinkBlock extends AbstractBlockType
{
    public function key(): string
    {
        return 'file_link';
    }

    public function label(): string
    {
        return 'File Link';
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
            'label' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'opens_in_new_tab' => ['required', 'boolean'],
        ];
    }

    public function defaultConfig(): array
    {
        return [
            'url' => 'https://example.com/document.pdf',
            'label' => 'Download file',
            'description' => null,
            'opens_in_new_tab' => true,
        ];
    }

    public function compileConfig(array $validatedConfig): array
    {
        return [
            'url' => $validatedConfig['url'],
            'label' => $validatedConfig['label'],
            'description' => $validatedConfig['description'] ?? null,
            'opens_in_new_tab' => (bool) $validatedConfig['opens_in_new_tab'],
        ];
    }
}
