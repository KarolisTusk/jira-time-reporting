<?php

namespace Tests\Feature;

use App\Models\JiraSetting;
use App\Models\User;
use App\Services\JiraApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http; // For mocking HTTP calls if JiraApiService makes direct calls
use Mockery\MockInterface;
use Tests\TestCase;

class JiraSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_jira_settings_page_can_be_rendered(): void
    {
        $response = $this->get(route('settings.jira.show'));

        $response->assertOk();
        $response->assertInertia(fn ($assert) => $assert
            ->component('settings/Jira')
            ->has('jiraSettings')
        );
    }

    public function test_jira_settings_can_be_stored_successfully(): void
    {
        // Mock JiraApiService to prevent actual API calls during this test
        $this->mock(JiraApiService::class, function (MockInterface $mock) {
            $mock->shouldReceive('testConnection')
                ->once()
                ->andReturn(['success' => true, 'message' => 'Successfully connected to JIRA.', 'data' => []]);
        });

        $response = $this->post(route('settings.jira.store'), [
            'jira_host' => 'https://yourcompany.atlassian.net',
            'jira_email' => 'test@example.com',
            'api_token' => 'this_is_a_valid_looking_api_token_for_test',
            'project_keys' => ['PROJ1', 'PROJ2'],
        ]);

        $response->assertRedirect(route('settings.jira.show'));
        $response->assertSessionHas('status', 'success');
        $response->assertSessionHas('message', 'JIRA settings saved successfully. Connection test successful.');

        $this->assertDatabaseHas('jira_settings', [
            'jira_host' => 'https://yourcompany.atlassian.net',
        ]);

        $settings = JiraSetting::first();
        $this->assertNotNull($settings);
        $this->assertEquals(['PROJ1', 'PROJ2'], $settings->project_keys);
        // $this->assertEquals('this_is_a_valid_looking_api_token_for_test', decrypt($settings->getRawOriginal('api_token'))); // Test encrypted value
    }

    public function test_jira_settings_store_fails_with_invalid_host(): void
    {
        $response = $this->post(route('settings.jira.store'), [
            'jira_host' => 'invalid-url',
            'jira_email' => 'test@example.com',
            'api_token' => 'test_token',
            'project_keys' => ['PROJ1'],
        ]);

        $response->assertSessionHasErrors('jira_host');
        $this->assertDatabaseMissing('jira_settings', [
            'jira_host' => 'invalid-url',
        ]);
    }

    public function test_jira_settings_store_handles_connection_failure(): void
    {
        $this->mock(JiraApiService::class, function (MockInterface $mock) {
            $mock->shouldReceive('testConnection')
                ->once()
                ->andReturn(['success' => false, 'message' => 'Connection failed.', 'data' => null]);
        });

        $response = $this->post(route('settings.jira.store'), [
            'jira_host' => 'https://yourcompany.atlassian.net',
            'jira_email' => 'test@example.com',
            'api_token' => 'this_is_a_valid_looking_api_token_for_test',
            'project_keys' => ['PROJ1'],
        ]);

        $response->assertRedirect(route('settings.jira.show'));
        $response->assertSessionHas('status', 'error');
        $response->assertSessionHas('message', 'Settings saved, but JIRA connection test failed: Connection failed.');

        $this->assertDatabaseHas('jira_settings', [
            'jira_host' => 'https://yourcompany.atlassian.net',
        ]);
    }

    public function test_jira_connection_test_route_succeeds(): void
    {
        JiraSetting::factory()->create([
            'jira_host' => 'https://test.atlassian.net',
            'api_token' => 'test_token_12345678901234567890',
        ]);

        $this->mock(JiraApiService::class, function (MockInterface $mock) {
            $mock->shouldReceive('testConnection')
                ->once()
                ->andReturn(['success' => true, 'message' => 'Successfully connected.', 'data' => []]);
        });

        $response = $this->post(route('settings.jira.test'));

        $response->assertRedirect(route('settings.jira.show'));
        $response->assertSessionHas('status', 'success');
        $response->assertSessionHas('message', 'Successfully connected.');
    }

    public function test_jira_connection_test_route_fails(): void
    {
        JiraSetting::factory()->create([
            'jira_host' => 'https://test.atlassian.net',
            'api_token' => 'test_token_12345678901234567890',
        ]);

        $this->mock(JiraApiService::class, function (MockInterface $mock) {
            $mock->shouldReceive('testConnection')
                ->once()
                ->andReturn(['success' => false, 'message' => 'Connection failed badly.', 'data' => null]);
        });

        $response = $this->post(route('settings.jira.test'));

        $response->assertRedirect(route('settings.jira.show'));
        $response->assertSessionHas('status', 'error');
        $response->assertSessionHas('message', 'Connection failed badly.');
    }
}
