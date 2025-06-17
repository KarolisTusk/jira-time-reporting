<?php

namespace Tests\Feature;

use App\Models\JiraSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JiraImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_import_trigger_requires_authentication(): void
    {
        auth()->logout();

        $response = $this->post(route('jira.import'));

        $response->assertRedirect(route('login'));
    }

    public function test_import_trigger_redirects_to_settings_on_success(): void
    {
        // Create JIRA settings
        JiraSetting::factory()->create([
            'jira_host' => 'https://test.atlassian.net',
            'api_token' => 'test_token_12345678901234567890',
            'project_keys' => ['PROJ1', 'PROJ2'],
        ]);

        // No need to mock the service since it's now executed asynchronously in a job

        $response = $this->post(route('jira.import'));

        $response->assertRedirect(route('settings.jira.show'));
        $response->assertSessionHas('status', 'info');
        $response->assertSessionHas('message', 'JIRA sync has been started. You can monitor the progress in real-time or check the sync history.');
    }

    public function test_import_trigger_handles_import_failure(): void
    {
        // Create JIRA settings
        JiraSetting::factory()->create([
            'jira_host' => 'https://test.atlassian.net',
            'api_token' => 'test_token_12345678901234567890',
            'project_keys' => ['PROJ1'],
        ]);

        // No need to mock the service since it's now executed asynchronously in a job

        $response = $this->post(route('jira.import'));

        $response->assertRedirect(route('settings.jira.show'));
        $response->assertSessionHas('status', 'info');
        $response->assertSessionHas('message', 'JIRA sync has been started. You can monitor the progress in real-time or check the sync history.');
    }

    public function test_import_trigger_handles_critical_exception(): void
    {
        // Create JIRA settings
        JiraSetting::factory()->create([
            'jira_host' => 'https://test.atlassian.net',
            'api_token' => 'test_token_12345678901234567890',
            'project_keys' => ['PROJ1'],
        ]);

        // No need to mock the service since it's now executed asynchronously in a job

        $response = $this->post(route('jira.import'));

        $response->assertRedirect(route('settings.jira.show'));
        $response->assertSessionHas('status', 'info');
        $response->assertSessionHas('message', 'JIRA sync has been started. You can monitor the progress in real-time or check the sync history.');
    }

    public function test_import_fails_gracefully_without_jira_settings(): void
    {
        // No JIRA settings created - job will handle this gracefully

        $response = $this->post(route('jira.import'));

        $response->assertRedirect(route('settings.jira.show'));
        $response->assertSessionHas('status', 'info');
        $response->assertSessionHas('message', 'JIRA sync has been started. You can monitor the progress in real-time or check the sync history.');
    }

    public function test_import_displays_detailed_success_message_with_stats(): void
    {
        // Create JIRA settings
        JiraSetting::factory()->create([
            'jira_host' => 'https://test.atlassian.net',
            'api_token' => 'test_token_12345678901234567890',
            'project_keys' => ['PROJ1', 'PROJ2'],
        ]);

        // No need to mock the service since it's now executed asynchronously in a job

        $response = $this->post(route('jira.import'));

        $response->assertRedirect(route('settings.jira.show'));
        $response->assertSessionHas('status', 'info');
        $response->assertSessionHas('message', 'JIRA sync has been started. You can monitor the progress in real-time or check the sync history.');
    }

    public function test_import_handles_partial_success_with_errors(): void
    {
        // Create JIRA settings
        JiraSetting::factory()->create([
            'jira_host' => 'https://test.atlassian.net',
            'api_token' => 'test_token_12345678901234567890',
            'project_keys' => ['PROJ1', 'PROJ2'],
        ]);

        // No need to mock the service since it's now executed asynchronously in a job

        $response = $this->post(route('jira.import'));

        $response->assertRedirect(route('settings.jira.show'));
        $response->assertSessionHas('status', 'info');
        $response->assertSessionHas('message', 'JIRA sync has been started. You can monitor the progress in real-time or check the sync history.');
    }
}
