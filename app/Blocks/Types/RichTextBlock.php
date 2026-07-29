<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;

class RichTextBlock extends AbstractBlockType
{
    public function key(): string
    {
        return 'rich_text';
    }

    public function label(): string
    {
        return 'Rich Text';
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
            'html' => ['required', 'string'],
        ];
    }

    public function defaultConfig(): array
    {
        return [
            'html' => '<p>Start writing…</p>',
        ];
    }

    public function compileConfig(array $validatedConfig): array
    {
        return [
            'html' => $validatedConfig['html'],
        ];
    }
}
