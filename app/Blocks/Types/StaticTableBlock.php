<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;
use Illuminate\Validation\Validator;

class StaticTableBlock extends AbstractBlockType
{
    public function key(): string
    {
        return 'static_table';
    }

    public function label(): string
    {
        return 'Static Table';
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
            'caption' => ['nullable', 'string'],
            'headers' => ['required', 'array', 'min:1'],
            'headers.*' => ['required', 'string'],
            'rows' => ['present', 'array'],
            'rows.*' => ['array'],
            'rows.*.*' => ['present', 'string'],
        ];
    }

    protected function afterValidation(Validator $validator, array $config): void
    {
        $headerCount = is_array($config['headers'] ?? null) ? count($config['headers']) : 0;

        foreach (($config['rows'] ?? []) as $index => $row) {
            if (is_array($row) && count($row) !== $headerCount) {
                $validator->errors()->add(
                    "rows.{$index}",
                    "Row {$index} has " . count($row) . " cells but the table has {$headerCount} headers."
                );
            }
        }
    }

    public function defaultConfig(): array
    {
        return [
            'caption' => null,
            'headers' => ['Column 1', 'Column 2'],
            'rows' => [
                ['Cell', 'Cell'],
            ],
        ];
    }

    public function compileConfig(array $validatedConfig): array
    {
        return [
            'caption' => $validatedConfig['caption'] ?? null,
            'headers' => array_values($validatedConfig['headers']),
            'rows' => array_values(array_map(
                fn (array $row) => array_values($row),
                $validatedConfig['rows'] ?? []
            )),
        ];
    }
}
