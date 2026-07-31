<?php

namespace App\Rules;

use App\Support\ImportAssetPlaceholder;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Authoring-time confinement for manual asset path fields.
 *
 * AssetUrl still governs publish-time shape (http(s) or root-relative). This
 * rule additionally refuses cross-lesson /storage/ paths, traversal, and
 * absolute filesystem paths so the manual field cannot defeat LessonAssetService.
 *
 * Draft-only import placeholders under /import-placeholder/ are allowed here;
 * assertPublishReady refuses them before a version is created.
 */
class LessonScopedAssetUrl implements ValidationRule
{
    public function __construct(private readonly string $lessonUuid) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be a non-empty string.');

            return;
        }

        // Absolute filesystem paths (Windows drive or *nix root outside URL space).
        if (preg_match('#^[a-zA-Z]:[\\\\/]#', $value) === 1 || str_starts_with($value, '\\\\')) {
            $fail('The :attribute must not be an absolute filesystem path.');

            return;
        }

        if (str_contains($value, '..')) {
            $fail('The :attribute must not contain path traversal.');

            return;
        }

        // External URLs stay under AssetUrl's http(s) rules.
        if (preg_match('#^https?://#i', $value) === 1) {
            if (filter_var($value, FILTER_VALIDATE_URL) === false) {
                $fail('The :attribute must be a valid http/https URL.');
            }

            return;
        }

        (new AssetUrl)->validate($attribute, $value, $fail);

        if (! str_starts_with($value, '/')) {
            return;
        }

        // Repository fixtures under public/lessons/... remain usable.
        if (str_starts_with($value, '/lessons/')) {
            return;
        }

        // Import placeholders are draft-only; publication checks reject them.
        if (ImportAssetPlaceholder::isPlaceholder($value)) {
            return;
        }

        $prefix = '/storage/lessons/'.$this->lessonUuid.'/';
        if (str_starts_with($value, $prefix)) {
            return;
        }

        if (str_starts_with($value, '/storage/lessons/')) {
            $fail('The :attribute must reference an asset under this lesson.');

            return;
        }

        $fail('The :attribute must be an http(s) URL, a /lessons/... fixture path, a /import-placeholder/... draft placeholder, or a /storage/lessons/{lesson}/... upload path.');
    }
}
