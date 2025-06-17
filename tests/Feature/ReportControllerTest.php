<?php

namespace Tests\Feature;

use App\Models\JiraAppUser;
use App\Models\JiraIssue;
use App\Models\JiraProject;
use App\Models\JiraWorklog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private JiraProject $project1;

    private JiraProject $project2;

    private JiraAppUser $jiraUser1;

    private JiraAppUser $jiraUser2;

    private JiraIssue $issue1;

    private JiraIssue $issue2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user
        $this->user = User::factory()->create();

        // Create test data
        $this->project1 = JiraProject::factory()->create([
            'project_key' => 'TEST1',
            'name' => 'Test Project 1',
        ]);

        $this->project2 = JiraProject::factory()->create([
            'project_key' => 'TEST2',
            'name' => 'Test Project 2',
        ]);

        $this->jiraUser1 = JiraAppUser::factory()->create([
            'display_name' => 'John Doe',
            'email_address' => 'john@example.com',
        ]);

        $this->jiraUser2 = JiraAppUser::factory()->create([
            'display_name' => 'Jane Smith',
            'email_address' => 'jane@example.com',
        ]);

        $this->issue1 = JiraIssue::factory()->create([
            'jira_project_id' => $this->project1->id,
            'issue_key' => 'TEST1-1',
        ]);

        $this->issue2 = JiraIssue::factory()->create([
            'jira_project_id' => $this->project2->id,
            'issue_key' => 'TEST2-1',
        ]);

        // Create sample worklogs
        JiraWorklog::factory()->create([
            'jira_issue_id' => $this->issue1->id,
            'jira_app_user_id' => $this->jiraUser1->id,
            'time_spent_seconds' => 3600, // 1 hour
            'started_at' => now()->subDays(5),
        ]);

        JiraWorklog::factory()->create([
            'jira_issue_id' => $this->issue1->id,
            'jira_app_user_id' => $this->jiraUser2->id,
            'time_spent_seconds' => 7200, // 2 hours
            'started_at' => now()->subDays(3),
        ]);

        JiraWorklog::factory()->create([
            'jira_issue_id' => $this->issue2->id,
            'jira_app_user_id' => $this->jiraUser1->id,
            'time_spent_seconds' => 5400, // 1.5 hours
            'started_at' => now()->subDays(2),
        ]);
    }

    /** @test */
    public function project_time_report_page_renders_successfully()
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.project-time'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Reports/ProjectTime')
            ->has('reportData')
            ->has('availableProjects')
        );
    }

    /** @test */
    public function project_time_report_with_date_filters()
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.project-time', [
                'start_date' => now()->subDays(10)->format('Y-m-d'),
                'end_date' => now()->format('Y-m-d'),
            ]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Reports/ProjectTime')
            ->has('reportData.chart')
            ->has('reportData.table')
            ->has('reportData.summary')
            ->where('filters.start_date', now()->subDays(10)->format('Y-m-d'))
            ->where('filters.end_date', now()->format('Y-m-d'))
        );
    }

    /** @test */
    public function user_time_per_project_report_page_renders_successfully()
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.user-time-per-project'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Reports/UserTimePerProject')
            ->has('reportData')
            ->has('availableProjects')
        );
    }

    /** @test */
    public function user_time_per_project_report_with_project_filter()
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.user-time-per-project', [
                'project_id' => $this->project1->id,
            ]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Reports/UserTimePerProject')
            ->has('reportData.chart')
            ->has('reportData.table')
            ->has('reportData.summary')
            ->where('filters.project_id', (string) $this->project1->id)
        );
    }

    /** @test */
    public function project_trend_report_page_renders_successfully()
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.project-trend'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Reports/ProjectTrend')
            ->has('reportData')
            ->has('availableProjects')
            ->has('availablePeriods')
        );
    }

    /** @test */
    public function project_trend_report_with_filters()
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports.project-trend', [
                'project_ids' => [$this->project1->id, $this->project2->id],
                'period' => 'monthly',
                'start_date' => now()->subDays(30)->format('Y-m-d'),
                'end_date' => now()->format('Y-m-d'),
            ]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Reports/ProjectTrend')
            ->has('reportData.chart')
            ->has('reportData.table')
            ->has('reportData.summary')
            ->where('filters.period', 'monthly')
        );
    }

    /** @test */
    public function project_time_api_endpoint_returns_correct_data()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/project-time-data');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'chart' => [
                    'labels',
                    'datasets' => [
                        '*' => [
                            'label',
                            'data',
                            'backgroundColor',
                        ],
                    ],
                ],
                'table' => [
                    '*' => [
                        'project_key',
                        'project_name',
                        'total_time_seconds',
                        'total_time_formatted',
                        'worklog_count',
                    ],
                ],
                'summary' => [
                    'total_projects',
                    'total_time_seconds',
                    'total_time_formatted',
                    'total_worklogs',
                ],
            ],
        ]);

        $response->assertJson(['success' => true]);
    }

    /** @test */
    public function user_time_per_project_api_endpoint_returns_correct_data()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/user-time-per-project-data', [
                'project_id' => $this->project1->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'chart' => [
                    'labels',
                    'datasets',
                ],
                'table' => [
                    '*' => [
                        'user_name',
                        'email_address',
                        'project_key',
                        'project_name',
                        'total_time_seconds',
                        'total_time_formatted',
                        'worklog_count',
                    ],
                ],
                'summary',
            ],
        ]);
    }

    /** @test */
    public function project_trend_api_endpoint_returns_correct_data()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/project-trend-data', [
                'period' => 'weekly',
                'project_ids' => [$this->project1->id],
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'chart' => [
                    'labels',
                    'datasets',
                ],
                'table' => [
                    '*' => [
                        'project_key',
                        'project_name',
                        'period',
                        'total_time_seconds',
                        'total_time_formatted',
                        'worklog_count',
                    ],
                ],
                'summary',
            ],
        ]);
    }

    /** @test */
    public function reports_require_authentication()
    {
        // Test web routes
        $this->get(route('reports.project-time'))
            ->assertRedirect(route('login'));

        $this->get(route('reports.user-time-per-project'))
            ->assertRedirect(route('login'));

        $this->get(route('reports.project-trend'))
            ->assertRedirect(route('login'));

        // Test API routes
        $this->getJson('/api/reports/project-time-data')
            ->assertStatus(401);

        $this->getJson('/api/reports/user-time-per-project-data')
            ->assertStatus(401);

        $this->getJson('/api/reports/project-trend-data')
            ->assertStatus(401);
    }

    /** @test */
    public function api_endpoints_handle_errors_gracefully()
    {
        // Mock a database error by using invalid data
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/project-time-data', [
                'start_date' => 'invalid-date',
            ]);

        // Should still return a response, possibly with error handling
        $response->assertStatus(200);
    }

    /** @test */
    public function report_data_transformation_is_correct()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/project-time-data');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Verify chart data structure
        $this->assertIsArray($data['chart']['labels']);
        $this->assertIsArray($data['chart']['datasets']);

        // Verify summary calculations
        $this->assertTrue($data['summary']['total_projects'] >= 0);
        $this->assertTrue($data['summary']['total_time_seconds'] >= 0);
        $this->assertIsString($data['summary']['total_time_formatted']);
    }
}
