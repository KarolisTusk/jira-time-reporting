<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JiraProjectSyncStatus>
 */
class JiraProjectSyncStatusFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $projectKeys = ['DEMO', 'TEST', 'PROJ', 'WEB', 'API', 'MOBILE', 'CRM', 'ERP'];
        $statuses = ['pending', 'in_progress', 'completed', 'failed'];
        
        $lastSyncAt = $this->faker->optional(0.8)->dateTimeBetween('-30 days', 'now');
        
        return [
            'project_key' => $this->faker->unique()->randomElement($projectKeys),
            'last_sync_at' => $lastSyncAt,
            'last_sync_status' => $lastSyncAt ? $this->faker->randomElement($statuses) : 'pending',
            'issues_count' => $this->faker->numberBetween(0, 500),
            'last_error' => $this->faker->optional(0.2)->sentence(),
        ];
    }

    /**
     * Indicate that the project has never been synced.
     */
    public function neverSynced(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_sync_at' => null,
            'last_sync_status' => 'pending',
            'issues_count' => 0,
            'last_error' => null,
        ]);
    }

    /**
     * Indicate that the project sync was successful.
     */
    public function successful(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_sync_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'last_sync_status' => 'completed',
            'issues_count' => $this->faker->numberBetween(10, 200),
            'last_error' => null,
        ]);
    }

    /**
     * Indicate that the project sync failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_sync_at' => $this->faker->dateTimeBetween('-3 days', 'now'),
            'last_sync_status' => 'failed',
            'issues_count' => $this->faker->numberBetween(0, 50),
            'last_error' => $this->faker->randomElement([
                'JIRA API rate limit exceeded',
                'Invalid project key',
                'Network connection timeout',
                'Authentication failed',
                'Project not accessible',
            ]),
        ]);
    }

    /**
     * Indicate that the project sync is currently in progress.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_sync_at' => $this->faker->dateTimeBetween('-1 hour', 'now'),
            'last_sync_status' => 'in_progress',
            'issues_count' => $this->faker->numberBetween(0, 100),
            'last_error' => null,
        ]);
    }

    /**
     * Indicate that the project was recently synced.
     */
    public function recentlySync(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_sync_at' => $this->faker->dateTimeBetween('-24 hours', 'now'),
            'last_sync_status' => 'completed',
            'issues_count' => $this->faker->numberBetween(5, 150),
            'last_error' => null,
        ]);
    }

    /**
     * Indicate that the project has a high issue count.
     */
    public function highVolumeProject(): static
    {
        return $this->state(fn (array $attributes) => [
            'issues_count' => $this->faker->numberBetween(200, 1000),
        ]);
    }

    /**
     * Indicate that the project is for a specific project key.
     */
    public function forProject(string $projectKey): static
    {
        return $this->state(fn (array $attributes) => [
            'project_key' => $projectKey,
        ]);
    }
}
