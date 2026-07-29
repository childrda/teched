<?php

namespace App\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonyHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Compile-time sanitizer for author-supplied HTML. Every HTML-bearing
 * config field is passed through here during publish, so only sanitized
 * markup is ever stored in a manifest.
 *
 * Policy:
 * - Allowed: headings, paragraphs, lists, strong/em, links (http/https/
 *   mailto only, forced rel="noopener"), tables, br, blockquote, code.
 * - Removed: script (with contents), iframes/embeds/objects (with
 *   contents), event handler attributes, style attributes,
 *   javascript:/data: URLs, and all unsupported tags.
 */
class HtmlSanitizer
{
    private const ALLOWED_ELEMENTS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'p', 'br', 'blockquote', 'code',
        'ul', 'ol', 'li',
        'strong', 'em',
        'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    ];

    /**
     * Benign wrappers whose tags are removed but whose children are kept.
     * Everything else that is not allowed is dropped with its children
     * (script, iframe, object, embed, style, ...).
     */
    private const BLOCKED_WRAPPERS = [
        'div', 'span', 'section', 'article', 'header', 'footer', 'main',
        'b', 'i', 'u', 'small', 'sub', 'sup', 'font', 'center',
    ];

    private SymfonyHtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->forceAttribute('a', 'rel', 'noopener')
            ->allowElement('a', ['href', 'title'])
            ->withMaxInputLength(1_000_000);

        foreach (self::ALLOWED_ELEMENTS as $element) {
            $config = $config->allowElement($element);
        }

        foreach (self::BLOCKED_WRAPPERS as $element) {
            $config = $config->blockElement($element);
        }

        $this->sanitizer = new SymfonyHtmlSanitizer($config);
    }

    public function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        return $this->sanitizer->sanitize($html);
    }
}
