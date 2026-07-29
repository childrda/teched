<?php

namespace App\Services;

use App\Support\PlainText;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * Splits already-sanitized rich text into top-level speech segments.
 *
 * This class is the single source of truth for that split, so the speech
 * segments a block reports and the data-speech-id attributes rendered into
 * the page are produced by the same walk and always line up one-to-one.
 * Segments carrying no spoken words (an empty paragraph, a bare image) are
 * emitted by neither side.
 */
class RichTextSegmenter
{
    /** Marks the wrapper this class adds so it can find the fragment again. */
    private const ROOT_ATTRIBUTE = 'data-rich-text-root';

    /**
     * The spoken segments of a fragment, in document order.
     *
     * @return list<array{id: string, text: string}>
     */
    public function segments(?string $html): array
    {
        if (! $this->hasContent($html)) {
            return [];
        }

        [$document, $root] = $this->parse($html);

        if ($root === null) {
            return [];
        }

        return array_map(
            fn (array $segment) => ['id' => $segment['id'], 'text' => $segment['text']],
            $this->collect($document, $root)
        );
    }

    /**
     * The same fragment with data-speech-id on the element for each segment,
     * so the player can highlight in place without touching text nodes.
     */
    public function tag(?string $html): string
    {
        if (! $this->hasContent($html)) {
            return (string) $html;
        }

        [$document, $root] = $this->parse($html);

        if ($root === null) {
            return (string) $html;
        }

        foreach ($this->collect($document, $root) as $segment) {
            $node = $segment['node'];

            if ($node instanceof DOMElement) {
                $node->setAttribute('data-speech-id', $segment['id']);

                continue;
            }

            // Loose text at the top level has no element to carry the
            // attribute, so it gets an inert inline wrapper instead.
            $wrapper = $document->createElement('span');
            $wrapper->setAttribute('data-speech-id', $segment['id']);

            $node->parentNode?->replaceChild($wrapper, $node);
            $wrapper->appendChild($node);
        }

        return $this->innerHtml($document, $root);
    }

    private function hasContent(?string $html): bool
    {
        return $html !== null && trim($html) !== '';
    }

    /**
     * @return array{0: DOMDocument, 1: ?DOMElement}
     */
    private function parse(string $html): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);

        // The charset meta keeps multi-byte characters intact; the wrapper
        // gives the fragment a stable root to walk and serialize.
        $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
            . '<div ' . self::ROOT_ATTRIBUTE . '>' . $html . '</div>'
            . '</body></html>',
            LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = (new DOMXPath($document))
            ->query('//div[@' . self::ROOT_ATTRIBUTE . ']')
            ->item(0);

        return [$document, $root instanceof DOMElement ? $root : null];
    }

    /**
     * One candidate per top-level node, skipping anything with no words.
     *
     * @return list<array{id: string, text: string, node: DOMNode}>
     */
    private function collect(DOMDocument $document, DOMElement $root): array
    {
        $segments = [];

        // Snapshot the list: tag() replaces nodes while iterating.
        foreach (iterator_to_array($root->childNodes) as $node) {
            if (! $node instanceof DOMElement && ! $node instanceof DOMText) {
                continue;
            }

            $text = PlainText::from($document->saveHTML($node));

            if ($text === '') {
                continue;
            }

            $segments[] = [
                'id' => 'html:' . count($segments),
                'text' => $text,
                'node' => $node,
            ];
        }

        return $segments;
    }

    private function innerHtml(DOMDocument $document, DOMElement $root): string
    {
        $html = '';

        foreach ($root->childNodes as $child) {
            $html .= (string) $document->saveHTML($child);
        }

        return $html;
    }
}
