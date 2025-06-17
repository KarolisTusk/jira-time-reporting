<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JiraProject>
 */
class JiraProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'jira_id' => $this->faker->unique()->numberBetween(10000, 99999),
            'project_key' => $this->faker->unique()->regexify('[A-Z]{2,5}'),
            'name' => $this->faker->words(3, true),
        ];
    }
}
