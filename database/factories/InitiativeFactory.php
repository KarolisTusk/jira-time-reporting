<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Initiative>
 */
class InitiativeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true) . ' Initiative',
            'description' => $this->faker->paragraph(),
            'hourly_rate' => $this->faker->randomFloat(2, 50, 150),
            'is_active' => $this->faker->boolean(80), // 80% chance of being active
        ];
    }
}
