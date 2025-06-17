<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JiraWorklog>
 */
class JiraWorklogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $resourceTypes = ['frontend', 'backend', 'qa', 'devops', 'management', 'architect', 'content management', 'development'];
        
        return [
            'jira_id' => $this->faker->unique()->uuid(),
            'jira_issue_id' => \App\Models\JiraIssue::factory(),
            'jira_app_user_id' => \App\Models\JiraAppUser::factory(),
            'time_spent_seconds' => $this->faker->numberBetween(900, 14400), // 15 minutes to 4 hours
            'started_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'resource_type' => $this->faker->randomElement($resourceTypes),
        ];
    }

    /**
     * Indicate that the worklog is for frontend development.
     */
    public function frontend(): static
    {
        return $this->state(fn (array $attributes) => [
            'resource_type' => 'frontend',
            'time_spent_seconds' => $this->faker->numberBetween(1800, 21600), // 30min to 6 hours
        ]);
    }

    /**
     * Indicate that the worklog is for backend development.
     */
    public function backend(): static
    {
        return $this->state(fn (array $attributes) => [
            'resource_type' => 'backend',
            'time_spent_seconds' => $this->faker->numberBetween(1800, 28800), // 30min to 8 hours
        ]);
    }

    /**
     * Indicate that the worklog is for QA testing.
     */
    public function qa(): static
    {
        return $this->state(fn (array $attributes) => [
            'resource_type' => 'qa',
            'time_spent_seconds' => $this->faker->numberBetween(900, 14400), // 15min to 4 hours
        ]);
    }

    /**
     * Indicate that the worklog is for DevOps work.
     */
    public function devops(): static
    {
        return $this->state(fn (array $attributes) => [
            'resource_type' => 'devops',
            'time_spent_seconds' => $this->faker->numberBetween(3600, 14400), // 1 to 4 hours
        ]);
    }

    /**
     * Indicate that the worklog is for management activities.
     */
    public function management(): static
    {
        return $this->state(fn (array $attributes) => [
            'resource_type' => 'management',
            'time_spent_seconds' => $this->faker->numberBetween(1800, 7200), // 30min to 2 hours
        ]);
    }

    /**
     * Indicate that the worklog is for a specific resource type.
     */
    public function resourceType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'resource_type' => $type,
        ]);
    }

    /**
     * Indicate that the worklog is for a short task.
     */
    public function shortTask(): static
    {
        return $this->state(fn (array $attributes) => [
            'time_spent_seconds' => $this->faker->numberBetween(900, 3600), // 15min to 1 hour
        ]);
    }

    /**
     * Indicate that the worklog is for a long task.
     */
    public function longTask(): static
    {
        return $this->state(fn (array $attributes) => [
            'time_spent_seconds' => $this->faker->numberBetween(14400, 28800), // 4 to 8 hours
        ]);
    }

    /**
     * Indicate that the worklog was logged during business hours.
     */
    public function businessHours(): static
    {
        return $this->state(function (array $attributes) {
            $startDate = $this->faker->dateTimeBetween('-30 days', 'now');
            $businessHour = $this->faker->numberBetween(9, 17);
            $startDate->setTime($businessHour, $this->faker->numberBetween(0, 59));
            
            return [
                'started_at' => $startDate,
            ];
        });
    }

    /**
     * Indicate that the worklog was logged outside business hours.
     */
    public function afterHours(): static
    {
        return $this->state(function (array $attributes) {
            $startDate = $this->faker->dateTimeBetween('-30 days', 'now');
            $hour = $this->faker->randomElement([6, 7, 8, 18, 19, 20, 21, 22]);
            $startDate->setTime($hour, $this->faker->numberBetween(0, 59));
            
            return [
                'started_at' => $startDate,
            ];
        });
    }
}
