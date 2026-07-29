<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;
use App\Services\HtmlSanitizer;

class CalloutBlock extends AbstractBlockType
{
    public function __construct(private readonly HtmlSanitizer $sanitizer)
    {
    }

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
            'html' => $this->sanitizer->sanitize($validatedConfig['html']),
        ];
    }

    public function speakableText(array $redactedConfig): array
    {
        $segments = [];

        $this->pushSegment(
            $segments,
            'html',
            $redactedConfig['heading'] ?? null,
            $redactedConfig['html'] ?? null
        );

        return $segments;
    }
}
