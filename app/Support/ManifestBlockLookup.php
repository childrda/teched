<?php

namespace App\Support;

/**
 * Finds a block or page in a compiled (or redacted) manifest by ULID.
 * Uses strict === so a type-coerced id never matches the wrong row.
 */
final class ManifestBlockLookup
{
    /**
     * @param  array<string, mixed>|null  $manifest
     * @return array<string, mixed>|null
     */
    public function findBlock(?array $manifest, string $blockId): ?array
    {
        foreach ($manifest['pages'] ?? [] as $page) {
            if (! is_array($page)) {
                continue;
            }

            foreach ($page['blocks'] ?? [] as $block) {
                if (! is_array($block)) {
                    continue;
                }

                if (($block['block_id'] ?? null) === $blockId) {
                    return $block;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @return array<string, mixed>|null
     */
    public function findPage(?array $manifest, string $pageId): ?array
    {
        foreach ($manifest['pages'] ?? [] as $page) {
            if (! is_array($page)) {
                continue;
            }

            if (($page['page_id'] ?? null) === $pageId) {
                return $page;
            }
        }

        return null;
    }
}
