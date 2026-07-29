<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;
use App\Services\HtmlSanitizer;
use App\Services\RichTextSegmenter;

class RichTextBlock extends AbstractBlockType
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly RichTextSegmenter $segmenter,
    ) {
    }

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
            'html' => $this->sanitizer->sanitize($validatedConfig['html']),
        ];
    }

    /**
     * One segment per top-level element, matching the data-speech-id
     * attributes RichTextSegmenter renders, so the player can highlight
     * each segment in place as it is spoken.
     */
    public function speakableText(array $redactedConfig): array
    {
        $segments = [];

        foreach ($this->segmenter->segments($redactedConfig['html'] ?? null) as $segment) {
            $this->pushSegment($segments, $segment['id'], null, $segment['text']);
        }

        return $segments;
    }
}
