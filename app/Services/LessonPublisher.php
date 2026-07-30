<?php

namespace App\Services;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\LessonVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Compiles a lesson's live authoring tree into an immutable LessonVersion.
 *
 * The only writer of lesson_versions. Compilation lives in LessonCompiler;
 * this class owns the transaction, version numbering, and status flip.
 */
class LessonPublisher
{
    public const SCHEMA_VERSION = LessonCompiler::SCHEMA_VERSION;

    public function __construct(private readonly LessonCompiler $compiler)
    {
    }

    public function publish(Lesson $lesson, User $user): LessonVersion
    {
        return DB::transaction(function () use ($lesson, $user) {
            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            $nextVersion = ((int) $locked->versions()->max('version')) + 1;

            $manifest = $this->compiler->compileManifest($locked, $nextVersion);

            $version = $locked->versions()->create([
                'version' => $nextVersion,
                'schema_version' => self::SCHEMA_VERSION,
                'manifest' => $manifest,
                'published_by' => $user->getKey(),
                'published_at' => now(),
            ]);

            $locked->forceFill([
                'current_version' => $nextVersion,
                'status' => LessonStatus::Published,
            ])->save();

            // Flag only — clearing unpublished must not bump lessons.updated_at.
            // Eloquent Builder::update() would add updated_at; use toBase().
            Lesson::query()
                ->whereKey($locked->getKey())
                ->toBase()
                ->update(['has_unpublished_changes' => false]);

            $lesson->refresh();

            return $version;
        });
    }
}
