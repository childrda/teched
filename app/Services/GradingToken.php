<?php

namespace App\Services;

use App\Exceptions\InvalidGradingToken;
use App\Models\Lesson;
use App\Models\LessonVersion;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Opaque, authenticated binding between a student player session and the
 * LessonVersion whose (unredacted) answer key must grade their responses.
 *
 * Deliberately not time-limited and not session-bound: a student holding an
 * old token is graded against that old version even after a republish. That
 * is grading consistency, not a bug.
 */
class GradingToken
{
    public function issue(LessonVersion $version): string
    {
        return Crypt::encryptString(json_encode([
            'lesson_id' => (int) $version->lesson_id,
            'version_id' => (int) $version->id,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param mixed $token whatever the request sent as version_token
     *
     * @throws InvalidGradingToken on every failure mode
     */
    public function resolve(mixed $token, Lesson $lesson): LessonVersion
    {
        if (! is_string($token) || $token === '') {
            throw InvalidGradingToken::make();
        }

        try {
            $decoded = Crypt::decryptString($token);
        } catch (DecryptException) {
            throw InvalidGradingToken::make();
        }

        try {
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidGradingToken::make();
        }

        if (! is_array($payload)) {
            throw InvalidGradingToken::make();
        }

        $lessonId = $payload['lesson_id'] ?? null;
        $versionId = $payload['version_id'] ?? null;

        if (! is_int($lessonId) || ! is_int($versionId)) {
            throw InvalidGradingToken::make();
        }

        if ($lessonId !== (int) $lesson->id) {
            throw InvalidGradingToken::make();
        }

        $version = LessonVersion::query()->find($versionId);

        if ($version === null || (int) $version->lesson_id !== (int) $lesson->id) {
            throw InvalidGradingToken::make();
        }

        return $version;
    }
}
