<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;

class ShortResponseBlock extends AbstractBlockType
{
    public function key(): string
    {
        return 'short_response';
    }

    public function label(): string
    {
        return 'Short Response';
    }

    public function isAutoGradable(): bool
    {
        return false;
    }

    public function collectsResponse(): bool
    {
        return true;
    }

    public function configRules(): array
    {
        return [
            'prompt_html' => ['required', 'string'],
            'placeholder' => ['nullable', 'string'],
            'min_length' => ['nullable', 'integer', 'min:0'],
            'rubric_html' => ['nullable', 'string'],
        ];
    }

    public function defaultConfig(): array
    {
        return [
            'prompt_html' => '<p>Write your response below.</p>',
            'placeholder' => null,
            'min_length' => null,
            'rubric_html' => null,
        ];
    }

    public function compileConfig(array $validatedConfig): array
    {
        return [
            'prompt_html' => $validatedConfig['prompt_html'],
            'placeholder' => $validatedConfig['placeholder'] ?? null,
            'min_length' => $validatedConfig['min_length'] ?? null,
            'rubric_html' => $validatedConfig['rubric_html'] ?? null,
        ];
    }

    public function redactConfig(array $compiledConfig): array
    {
        $redacted = $compiledConfig;
        unset($redacted['rubric_html']);

        return $redacted;
    }
}
