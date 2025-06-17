<?php

namespace Database\Factories;

use App\Models\JiraSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JiraSetting>
 */
class JiraSettingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = JiraSetting::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'jira_host' => $this->faker->url(),
            // Use the model's mutator for encryption by default
            'api_token' => 'factory_generated_api_token_'.$this->faker->unique()->regexify('[A-Za-z0-9]{20}'),
            'project_keys' => [$this->faker->lexify('PROJ???'), $this->faker->lexify('ANOTHER???')],
        ];
    }
}
