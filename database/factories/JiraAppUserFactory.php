<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JiraAppUser>
 */
class JiraAppUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'jira_account_id' => $this->faker->unique()->uuid(),
            'display_name' => $this->faker->name(),
            'email_address' => $this->faker->email(),
        ];
    }
}
