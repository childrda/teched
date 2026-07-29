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
            'first_column_is_header' => ['sometimes', 'boolean'],
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
            'first_column_is_header' => false,
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
            // Authors opt in; a plain data grid has column headers only.
            'first_column_is_header' => (bool) ($validatedConfig['first_column_is_header'] ?? false),
        ];
    }

    /**
     * Linearizes each row so it makes sense without the visual grid:
     * "<row header>. <column header>: <cell>. <column header>: <cell>."
     */
    public function speakableText(array $redactedConfig): array
    {
        $segments = [];

        $this->pushSegment($segments, 'caption', null, $redactedConfig['caption'] ?? null);

        $headers = array_values($redactedConfig['headers'] ?? []);

        foreach (array_values($redactedConfig['rows'] ?? []) as $rowIndex => $row) {
            $cells = array_values(is_array($row) ? $row : []);

            if ($cells === []) {
                continue;
            }

            // The first cell names the row; the rest are paired with their
            // column header.
            $sentence = rtrim($this->toPlainText($cells[0]), ' .') . '.';

            foreach (array_slice($cells, 1) as $offset => $cell) {
                $columnHeader = $this->toPlainText($headers[$offset + 1] ?? '');

                $sentence .= ' ' . $columnHeader . ': ' . rtrim($this->toPlainText($cell), ' .') . '.';
            }

            $this->pushSegment($segments, "row:{$rowIndex}", 'Row ' . ($rowIndex + 1), $sentence);
        }

        return $segments;
    }
}
