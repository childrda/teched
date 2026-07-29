<?php

namespace Database\Factories;

use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\LessonVersion>
 */
class LessonVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'version' => 1,
            'schema_version' => 1,
            'manifest' => [
                'schema_version' => 1,
                'code' => 'LES-0.0.000',
                'title' => 'Placeholder',
                'version' => 1,
                'estimated_minutes' => null,
                'learning_target' => null,
                'success_criteria' => null,
                'pages' => [],
            ],
            'published_at' => now(),
        ];
    }
}
