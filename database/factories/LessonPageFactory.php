<?php

namespace Database\Factories;

use App\Enums\PageCompletionType;
use App\Models\Lesson;
use App\Models\LessonPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LessonPage>
 */
class LessonPageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'page_id' => (string) Str::ulid(),
            'lesson_id' => Lesson::factory(),
            'title' => fake()->sentence(3),
            'position' => fake()->unique()->numberBetween(1, 1000000),
            'completion_type' => PageCompletionType::View,
            'estimated_minutes' => fake()->numberBetween(2, 15),
            'settings' => LessonPage::DEFAULT_SETTINGS,
        ];
    }
}
