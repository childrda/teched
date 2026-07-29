<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;

class CalloutBlock extends AbstractBlockType
{
    public function key(): string
    {
        return 'callout';
    }

    public function label(): string
    {
        return 'Callout';
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
            'style' => ['required', 'string', 'in:info,warning,tip'],
            'heading' => ['nullable', 'string'],
            'html' => ['required', 'string'],
        ];
    }

    public function defaultConfig(): array
    {
        return [
            'style' => 'info',
            'heading' => null,
            'html' => '<p>Callout text…</p>',
        ];
    }

    public function compileConfig(array $validatedConfig): array
    {
        return [
            'style' => $validatedConfig['style'],
            'heading' => $validatedConfig['heading'] ?? null,
            'html' => $validatedConfig['html'],
        ];
    }
}
