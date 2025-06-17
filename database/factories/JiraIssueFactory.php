<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JiraIssue>
 */
class JiraIssueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'jira_id' => $this->faker->unique()->regexify('[A-Z]+-[0-9]+'),
            'jira_project_id' => \App\Models\JiraProject::factory(),
            'issue_key' => $this->faker->unique()->regexify('[A-Z]+-[0-9]+'),
            'summary' => $this->faker->sentence(),
            'status' => $this->faker->randomElement(['Open', 'In Progress', 'Done', 'Closed']),
            'assignee_jira_app_user_id' => \App\Models\JiraAppUser::factory(),
            'original_estimate_seconds' => $this->faker->numberBetween(3600, 28800), // 1-8 hours
        ];
    }
}
