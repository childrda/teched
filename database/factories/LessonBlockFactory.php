<?php

namespace Database\Factories;

use App\Enums\BlockType;
use App\Models\LessonPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\LessonBlock>
 */
class LessonBlockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'block_id' => (string) Str::ulid(),
            'lesson_page_id' => LessonPage::factory(),
            'type' => BlockType::RichText,
            'position' => fake()->unique()->numberBetween(1, 1000000),
            'config' => ['html' => '<p>' . fake()->sentence() . '</p>'],
            'grading' => null,
        ];
    }
}
