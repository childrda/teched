<?php

namespace App\Support;

/**
 * Unmistakable draft-only asset URLs written by lesson JSON import.
 * Publication must fail until every placeholder is replaced.
 */
final class ImportAssetPlaceholder
{
    public const IMAGE_REQUIRED = '/import-placeholder/image-required';

    public const PREFIX = '/import-placeholder/';

    public static function isPlaceholder(?string $url): bool
    {
        return is_string($url)
            && $url !== ''
            && str_starts_with($url, self::PREFIX);
    }
}
