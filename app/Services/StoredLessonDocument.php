<?php

namespace App\Services;

final readonly class StoredLessonDocument
{
    public function __construct(
        public string $url,
        public string $originalName,
    ) {}
}
