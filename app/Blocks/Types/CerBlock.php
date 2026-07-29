<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;
use Illuminate\Validation\Validator;

/**
 * Claim–Evidence–Reasoning writing scaffold.
 */
class CerBlock extends AbstractBlockType
{
    public function key(): string
    {
        return 'cer';
    }

    public function label(): string
    {
        return 'Claim–Evidence–Reasoning';
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
            'scenario_html' => ['required', 'string'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*.id' => ['required', 'string'],
            'fields.*.label' => ['required', 'string'],
            'fields.*.placeholder' => ['nullable', 'string'],
            'fields.*.min_length' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function afterValidation(Validator $validator, array $config): void
    {
        $this->assertDistinctIds($validator, $config['fields'] ?? [], 'fields');
    }

    public function defaultConfig(): array
    {
        return [
            'scenario_html' => '<p>Consider the following scenario…</p>',
            'fields' => [
                ['id' => 'claim', 'label' => 'Claim', 'placeholder' => null, 'min_length' => null],
                ['id' => 'evidence', 'label' => 'Evidence', 'placeholder' => null, 'min_length' => null],
                ['id' => 'reasoning', 'label' => 'Reasoning', 'placeholder' => null, 'min_length' => null],
            ],
        ];
    }

    public function compileConfig(array $validatedConfig): array
    {
        return [
            'scenario_html' => $validatedConfig['scenario_html'],
            'fields' => array_values(array_map(
                fn (array $f) => [
                    'id' => $f['id'],
                    'label' => $f['label'],
                    'placeholder' => $f['placeholder'] ?? null,
                    'min_length' => $f['min_length'] ?? null,
                ],
                $validatedConfig['fields']
            )),
        ];
    }
}
