<?php

namespace Database\Factories;

use App\Models\JiraSyncHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JiraSyncCheckpoint>
 */
class JiraSyncCheckpointFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $projectKeys = ['DEMO', 'TEST', 'PROJ', 'WEB', 'API', 'MOBILE'];
        $statuses = ['pending', 'in_progress', 'completed', 'failed'];
        
        return [
            'jira_sync_history_id' => JiraSyncHistory::factory(),
            'project_key' => $this->faker->randomElement($projectKeys),
            'status' => $this->faker->randomElement($statuses),
            'checkpoint_data' => [
                'project_stored' => $this->faker->boolean(80),
                'issues_processed' => $this->faker->numberBetween(0, 100),
                'total_issues' => $this->faker->numberBetween(10, 200),
                'worklogs_processed' => $this->faker->numberBetween(0, 500),
                'last_sync_time' => $this->faker->dateTimeThisMonth()->format('c'),
                'batch_size' => $this->faker->numberBetween(10, 100),
                'api_requests_made' => $this->faker->numberBetween(1, 50),
            ],
        ];
    }

    /**
     * Indicate that the checkpoint is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'checkpoint_data' => array_merge($attributes['checkpoint_data'] ?? [], [
                'project_stored' => false,
                'issues_processed' => 0,
                'worklogs_processed' => 0,
            ]),
        ]);
    }

    /**
     * Indicate that the checkpoint is in progress.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
            'checkpoint_data' => array_merge($attributes['checkpoint_data'] ?? [], [
                'project_stored' => true,
                'issues_processed' => $this->faker->numberBetween(1, 50),
                'worklogs_processed' => $this->faker->numberBetween(1, 200),
            ]),
        ]);
    }

    /**
     * Indicate that the checkpoint is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'checkpoint_data' => array_merge($attributes['checkpoint_data'] ?? [], [
                'project_stored' => true,
                'issues_processed' => $attributes['checkpoint_data']['total_issues'] ?? 100,
                'worklogs_processed' => $this->faker->numberBetween(100, 500),
                'completion_time' => now()->toISOString(),
            ]),
        ]);
    }

    /**
     * Indicate that the checkpoint failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'checkpoint_data' => array_merge($attributes['checkpoint_data'] ?? [], [
                'error_message' => $this->faker->sentence(),
                'error_code' => $this->faker->randomElement(['API_LIMIT', 'NETWORK_ERROR', 'INVALID_DATA']),
                'retry_count' => $this->faker->numberBetween(1, 3),
            ]),
        ]);
    }

    /**
     * Indicate that the checkpoint is for a specific project.
     */
    public function forProject(string $projectKey): static
    {
        return $this->state(fn (array $attributes) => [
            'project_key' => $projectKey,
        ]);
    }
}
