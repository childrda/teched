<?php

namespace App\Http\Controllers\Api;

use App\Blocks\BlockTypeRegistry;
use App\Enums\LessonStatus;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;

/**
 * Serves the published, redacted manifest students consume. Students
 * never see live authoring rows: only a compiled LessonVersion manifest
 * with every block config passed through its type's redactConfig().
 */
class LessonManifestController extends Controller
{
    public function __construct(private readonly BlockTypeRegistry $registry)
    {
    }

    public function __invoke(string $code): JsonResponse
    {
        $lesson = Lesson::query()->where('code', $code)->first();

        // 404 unless status is exactly "published" (drafts and archived
        // lessons are invisible even when prior versions exist).
        if ($lesson === null || $lesson->status !== LessonStatus::Published) {
            abort(404);
        }

        $version = $lesson->currentVersion();

        if ($version === null) {
            abort(404);
        }

        // The array cast returns a fresh decoded copy, so redaction below
        // never mutates the stored manifest or the model's attribute.
        $manifest = $version->manifest;

        $manifest['pages'] = array_map(function (array $page) {
            $page['blocks'] = array_map(function (array $block) {
                $type = $this->registry->get($block['type']);

                $block['config'] = $type->redactConfig($block['config']);

                return $block;
            }, $page['blocks']);

            return $page;
        }, $manifest['pages']);

        return response()->json($manifest);
    }
}
