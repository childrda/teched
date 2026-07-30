<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;
use App\Blocks\Concerns\ValidatesStudentTextState;
use App\Services\HtmlSanitizer;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Claim–Evidence–Reasoning writing scaffold.
 */
class CerBlock extends AbstractBlockType
{
    use ValidatesStudentTextState;

    public function __construct(private readonly HtmlSanitizer $sanitizer)
    {
    }

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
            'scenario_html' => $this->sanitizer->sanitize($validatedConfig['scenario_html']),
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

    public function holdsStudentState(): bool
    {
        return true;
    }

    public function validateState(array $state, array $compiledConfig): array
    {
        if (! array_key_exists('values', $state) || ! is_array($state['values'])) {
            throw ValidationException::withMessages([
                'state.values' => 'CER state must include a values object.',
            ]);
        }

        foreach (array_keys($state) as $key) {
            if ($key !== 'values') {
                throw ValidationException::withMessages([
                    "state.{$key}" => 'Unrecognized CER state key.',
                ]);
            }
        }

        $fields = is_array($compiledConfig['fields'] ?? null) ? $compiledConfig['fields'] : [];
        $fieldIds = [];

        foreach ($fields as $field) {
            if (is_array($field) && is_string($field['id'] ?? null)) {
                $fieldIds[$field['id']] = true;
            }
        }

        $normalized = [];

        foreach ($state['values'] as $fieldId => $value) {
            if (! is_string($fieldId) || ! isset($fieldIds[$fieldId])) {
                throw ValidationException::withMessages([
                    'state.values' => "Unknown CER field id \"{$fieldId}\".",
                ]);
            }

            $normalized[$fieldId] = $this->normalizeStudentText($value, "state.values.{$fieldId}");
        }

        foreach (array_keys($fieldIds) as $fieldId) {
            if (! array_key_exists($fieldId, $normalized)) {
                throw ValidationException::withMessages([
                    "state.values.{$fieldId}" => 'Every CER field must be present in the state map.',
                ]);
            }
        }

        return ['values' => $normalized];
    }

    public function isStateSatisfied(array $state, array $compiledConfig): bool
    {
        $values = is_array($state['values'] ?? null) ? $state['values'] : [];
        $fields = is_array($compiledConfig['fields'] ?? null) ? $compiledConfig['fields'] : [];

        if ($fields === []) {
            return false;
        }

        foreach ($fields as $field) {
            if (! is_array($field) || ! is_string($field['id'] ?? null)) {
                return false;
            }

            $value = $values[$field['id']] ?? null;

            if (! is_string($value) || ! $this->textMeetsMinLength($value, $field['min_length'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public function speakableText(array $redactedConfig): array
    {
        $segments = [];

        $this->pushSegment($segments, 'scenario', null, $redactedConfig['scenario_html'] ?? null);

        foreach (array_values($redactedConfig['fields'] ?? []) as $index => $field) {
            $this->pushSegment(
                $segments,
                ($field['id'] ?? $index) . ':label',
                null,
                $field['label'] ?? null
            );
        }

        return $segments;
    }
}
