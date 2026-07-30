<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;
use App\Blocks\Concerns\ValidatesStudentTextState;
use App\Services\HtmlSanitizer;
use Illuminate\Validation\ValidationException;

class ShortResponseBlock extends AbstractBlockType
{
    use ValidatesStudentTextState;

    public function __construct(private readonly HtmlSanitizer $sanitizer)
    {
    }

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
            'prompt_html' => $this->sanitizer->sanitize($validatedConfig['prompt_html']),
            'placeholder' => $validatedConfig['placeholder'] ?? null,
            'min_length' => $validatedConfig['min_length'] ?? null,
            'rubric_html' => $this->sanitizer->sanitize($validatedConfig['rubric_html'] ?? null),
        ];
    }

    public function redactConfig(array $compiledConfig): array
    {
        $redacted = $compiledConfig;
        unset($redacted['rubric_html']);

        return $redacted;
    }

    public function holdsStudentState(): bool
    {
        return true;
    }

    public function validateState(array $state, array $compiledConfig): array
    {
        if (! array_key_exists('value', $state)) {
            throw ValidationException::withMessages([
                'state.value' => 'Short response state must include a value.',
            ]);
        }

        // Reject unrecognized keys rather than silently dropping them.
        foreach (array_keys($state) as $key) {
            if ($key !== 'value') {
                throw ValidationException::withMessages([
                    "state.{$key}" => 'Unrecognized short response state key.',
                ]);
            }
        }

        return [
            'value' => $this->normalizeStudentText($state['value'], 'state.value'),
        ];
    }

    public function isStateSatisfied(array $state, array $compiledConfig): bool
    {
        $value = $state['value'] ?? null;

        if (! is_string($value)) {
            return false;
        }

        return $this->textMeetsMinLength($value, $compiledConfig['min_length'] ?? null);
    }

    public function speakableText(array $redactedConfig): array
    {
        $segments = [];

        // rubric_html is already absent from a redacted config, so a rubric
        // can never be spoken to a student.
        $this->pushSegment($segments, 'prompt', null, $redactedConfig['prompt_html'] ?? null);

        return $segments;
    }
}
