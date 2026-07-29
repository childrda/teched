<?php

namespace App\Support;

/**
 * Converts author markup into speech-ready plain text. Shared by every
 * block type (via AbstractBlockType) and by RichTextSegmenter, so a
 * segment's spoken text is produced by exactly one implementation.
 */
class PlainText
{
    /** Tags that sit inside a sentence and must not introduce a space. */
    private const INLINE_TAGS = 'a|abbr|b|cite|code|em|i|q|s|small|span|strong|sub|sup|u|var';

    /**
     * Tags removed, entities decoded, whitespace collapsed. Inline tags
     * vanish so words and punctuation stay intact, while every other tag
     * becomes a space so adjacent blocks never run their words together.
     */
    public static function from(?string $html): string
    {
        if ($html === null) {
            return '';
        }

        // Script and style bodies are code, not words. Publishing sanitizes
        // them away, but speech must not read them out even if it is handed
        // markup that never went through the sanitizer.
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;

        $text = preg_replace('#</?(?:' . self::INLINE_TAGS . ')(?:\s[^>]*)?>#i', '', $text) ?? $text;
        $text = preg_replace('/<[^>]*>/', ' ', $text) ?? '';
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim(preg_replace('/ +([.,;:!?])/u', '$1', $text) ?? $text);
    }
}
