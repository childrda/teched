<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;
use App\Services\HtmlSanitizer;
use Illuminate\Validation\Validator;

class VideoBlock extends AbstractBlockType
{
    public function __construct(private readonly HtmlSanitizer $sanitizer)
    {
    }

    public function key(): string
    {
        return 'video';
    }

    public function label(): string
    {
        return 'Video';
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
            'provider' => ['required', 'string', 'in:youtube'],
            'video_id' => ['required', 'string'],
            'title' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'focus_questions' => ['present', 'array'],
            'focus_questions.*.id' => ['required', 'string'],
            'focus_questions.*.text' => ['required', 'string'],
            'require_confirmation' => ['required', 'boolean'],
            'captions_available' => ['required', 'boolean'],
            'transcript_html' => ['nullable', 'string'],
        ];
    }

    protected function afterValidation(Validator $validator, array $config): void
    {
        $this->assertDistinctIds($validator, $config['focus_questions'] ?? [], 'focus_questions');
    }

    public function defaultConfig(): array
    {
        return [
            'provider' => 'youtube',
            'video_id' => 'VIDEO_ID',
            'title' => null,
            'instructions' => null,
            'focus_questions' => [],
            'require_confirmation' => false,
            'captions_available' => false,
            'transcript_html' => null,
        ];
    }

    public function compileConfig(array $validatedConfig): array
    {
        return [
            'provider' => $validatedConfig['provider'],
            'video_id' => $validatedConfig['video_id'],
            'title' => $validatedConfig['title'] ?? null,
            'instructions' => $validatedConfig['instructions'] ?? null,
            'focus_questions' => array_values(array_map(
                fn (array $q) => ['id' => $q['id'], 'text' => $q['text']],
                $validatedConfig['focus_questions'] ?? []
            )),
            'require_confirmation' => (bool) $validatedConfig['require_confirmation'],
            'captions_available' => (bool) $validatedConfig['captions_available'],
            'transcript_html' => $this->sanitizer->sanitize($validatedConfig['transcript_html'] ?? null),
        ];
    }

    /**
     * Reads the framing around the video — never the video itself, and not
     * its transcript, which the player surfaces separately as captions.
     */
    public function speakableText(array $redactedConfig): array
    {
        $segments = [];

        $this->pushSegment($segments, 'title', null, $redactedConfig['title'] ?? null);
        $this->pushSegment($segments, 'instructions', 'Instructions', $redactedConfig['instructions'] ?? null);

        foreach (array_values($redactedConfig['focus_questions'] ?? []) as $index => $question) {
            $this->pushSegment(
                $segments,
                'focus_question:' . ($question['id'] ?? $index),
                'Focus question ' . ($index + 1),
                $question['text'] ?? null
            );
        }

        return $segments;
    }
}
