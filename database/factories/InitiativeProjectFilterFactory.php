<?php

namespace Database\Factories;

use App\Models\Initiative;
use App\Models\JiraProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InitiativeProjectFilter>
 */
class InitiativeProjectFilterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $labels = $this->faker->randomElements([
            'frontend', 'backend', 'bug', 'feature', 'urgent', 'enhancement', 
            'documentation', 'testing', 'performance', 'security'
        ], $this->faker->numberBetween(0, 3));

        return [
            'initiative_id' => Initiative::factory(),
            'jira_project_id' => JiraProject::factory(),
            'required_labels' => $labels,
            'epic_key' => $this->faker->optional()->bothify('PROJ-###'),
        ];
    }
}
