<?php

namespace Database\Factories;

use App\Enums\LessonStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\Lesson>
 */
class LessonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'code' => 'LES-' . fake()->unique()->numerify('#.#.###'),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'subject' => fake()->randomElement(['Welding', 'Carpentry', 'Electricity', 'Engineering']),
            'grade_range' => fake()->randomElement(['6-8', '9-12', 'K-5']),
            'estimated_minutes' => fake()->numberBetween(10, 90),
            'learning_target' => fake()->sentence(),
            'success_criteria' => [fake()->sentence(), fake()->sentence()],
            'standards' => [fake()->bothify('STD-##.#')],
            'status' => LessonStatus::Draft,
            'current_version' => 0,
            'has_unpublished_changes' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => LessonStatus::Published,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => LessonStatus::Archived,
        ]);
    }
}
