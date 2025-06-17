<?php

namespace Tests\Unit;

use App\Models\JiraAppUser;
use App\Models\JiraIssue;
use App\Models\JiraProject;
// Added
use App\Models\JiraWorklog;
use App\Services\JiraApiService;
use App\Services\JiraImportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase; // Added

class JiraImportServiceTest extends TestCase
{
    // use RefreshDatabase; // Commented out to avoid transaction conflicts

    protected JiraApiService $jiraApiServiceMock;

    protected JiraImportService $jiraImportService;

    protected function setUp(): void
    {
        parent::setUp();

        // Manually clear and migrate database
        $this->artisan('migrate:fresh');

        // Mock JiraApiService
        $this->jiraApiServiceMock = Mockery::mock(JiraApiService::class);
        $this->jiraImportService = new JiraImportService($this->jiraApiServiceMock);

        // Don't set up global log mocking here - let individual tests handle it
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_store_project_data()
    {
        // Set up log mock for this test
        Log::shouldReceive('info', 'warning', 'error', 'debug')->andReturnNull();

        $projectDataFromApi = [
            'id' => '10001',
            'key' => 'TESTPROJ',
            'name' => 'Test Project',
        ];

        $project = $this->jiraImportService->storeProject($projectDataFromApi);

        $this->assertInstanceOf(JiraProject::class, $project);
        $this->assertEquals('10001', $project->jira_id);
        $this->assertEquals('TESTPROJ', $project->project_key);
        $this->assertEquals('Test Project', $project->name);
        $this->assertDatabaseHas('jira_projects', [
            'jira_id' => '10001',
            'project_key' => 'TESTPROJ',
        ]);

        // Test update
        $updatedProjectData = [
            'id' => '10001',
            'key' => 'TESTPROJ',
            'name' => 'Test Project Updated Name',
        ];
        $updatedProject = $this->jiraImportService->storeProject($updatedProjectData);
        $this->assertEquals('Test Project Updated Name', $updatedProject->name);
        $this->assertDatabaseHas('jira_projects', [
            'jira_id' => '10001',
            'name' => 'Test Project Updated Name',
        ]);
        $this->assertDatabaseCount('jira_projects', 1);
    }

    /** @test */
    public function store_project_throws_exception_for_missing_data()
    {
        // Set up log mock for this test
        Log::shouldReceive('info', 'warning', 'error', 'debug')->andReturnNull();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Project data from API is missing the required key field.');

        $projectDataFromApi = ['id' => '10001']; // Missing key and name
        $this->jiraImportService->storeProject($projectDataFromApi);
    }

    /** @test */
    public function it_can_store_user_data()
    {
        // Set up log mock for this test
        Log::shouldReceive('info', 'warning', 'error', 'debug')->andReturnNull();

        $userDataFromApi = [
            'accountId' => 'user-account-id-123',
            'displayName' => 'Test User',
            'emailAddress' => 'testuser@example.com',
        ];
        $results = ['users_processed' => 0]; // Mock results array

        $user = $this->jiraImportService->storeUser($userDataFromApi, $results);

        $this->assertInstanceOf(JiraAppUser::class, $user);
        $this->assertEquals('user-account-id-123', $user->jira_account_id);
        $this->assertEquals('Test User', $user->display_name);
        $this->assertEquals('testuser@example.com', $user->email_address);
        $this->assertEquals(1, $results['users_processed']);
        $this->assertDatabaseHas('jira_app_users', [
            'jira_account_id' => 'user-account-id-123',
        ]);

        // Test update
        $updatedUserData = [
            'accountId' => 'user-account-id-123',
            'displayName' => 'Test User Updated',
            'emailAddress' => 'testuser.updated@example.com',
        ];
        $updatedUser = $this->jiraImportService->storeUser($updatedUserData, $results);
        $this->assertEquals('Test User Updated', $updatedUser->display_name);
        $this->assertEquals(2, $results['users_processed']); // Assuming results are cumulative for the test
        $this->assertDatabaseHas('jira_app_users', [
            'jira_account_id' => 'user-account-id-123',
            'display_name' => 'Test User Updated',
        ]);
        $this->assertDatabaseCount('jira_app_users', 1);
    }

    /** @test */
    public function store_user_returns_null_if_account_id_is_missing()
    {
        $userDataFromApi = [
            // 'accountId' => 'user-account-id-123', // Missing accountId
            'displayName' => 'Test User',
            'emailAddress' => 'testuser@example.com',
        ];
        $results = ['users_processed' => 0];

        // Set up specific log expectations for this test
        Log::shouldReceive('info', 'error', 'debug')->andReturnNull();
        Log::shouldReceive('warning')->once()->with('Skipping user creation/update due to missing accountId. This user cannot be reliably identified or stored.', ['userData' => $userDataFromApi]);

        $user = $this->jiraImportService->storeUser($userDataFromApi, $results);

        $this->assertNull($user);
        $this->assertEquals(0, $results['users_processed']);
        $this->assertDatabaseCount('jira_app_users', 0);
    }

    /** @test */
    public function store_user_throws_exception_if_display_name_is_missing_but_account_id_exists()
    {
        // Set up log mock for this test
        Log::shouldReceive('info', 'warning', 'error', 'debug')->andReturnNull();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('User data from API is missing the required displayName field for accountId: user-account-id-123');

        $userDataFromApi = [
            'accountId' => 'user-account-id-123',
            // 'displayName' => 'Test User', // Missing displayName
            'emailAddress' => 'testuser@example.com',
        ];
        $results = ['users_processed' => 0];

        $this->jiraImportService->storeUser($userDataFromApi, $results);
    }

    /** @test */
    public function it_can_store_issue_data()
    {
        // Set up log mock for this test
        Log::shouldReceive('info', 'warning', 'error', 'debug')->andReturnNull();

        // Requires a project and an assignee (user) to exist or be passed as null
        $project = JiraProject::factory()->create();
        $assignee = JiraAppUser::factory()->create(['jira_account_id' => 'assignee-id']);

        $issueDataFromApi = [
            'id' => 'ISSUE-101',
            'key' => 'PROJ-1',
            'fields' => [
                'summary' => 'Test Issue Summary',
                'status' => ['name' => 'Open'],
                'timetracking' => ['originalEstimateSeconds' => 3600],
                // 'assignee' is handled by passing $assignee->id
            ],
        ];

        $issue = $this->jiraImportService->storeIssue($issueDataFromApi, $project->id, $assignee->id);

        $this->assertInstanceOf(JiraIssue::class, $issue);
        $this->assertEquals('ISSUE-101', $issue->jira_id);
        $this->assertEquals('PROJ-1', $issue->issue_key);
        $this->assertEquals('Test Issue Summary', $issue->summary);
        $this->assertEquals('Open', $issue->status);
        $this->assertEquals(3600, $issue->original_estimate_seconds);
        $this->assertEquals($project->id, $issue->jira_project_id);
        $this->assertEquals($assignee->id, $issue->assignee_jira_app_user_id);
        $this->assertDatabaseHas('jira_issues', ['jira_id' => 'ISSUE-101']);

        // Test update
        $updatedIssueData = [
            'id' => 'ISSUE-101',
            'key' => 'PROJ-1',
            'fields' => [
                'summary' => 'Test Issue Summary Updated',
                'status' => ['name' => 'In Progress'],
                'timetracking' => ['originalEstimateSeconds' => 7200],
            ],
        ];
        $updatedIssue = $this->jiraImportService->storeIssue($updatedIssueData, $project->id, null); // Test with null assignee
        $this->assertEquals('Test Issue Summary Updated', $updatedIssue->summary);
        $this->assertEquals('In Progress', $updatedIssue->status);
        $this->assertEquals(7200, $updatedIssue->original_estimate_seconds);
        $this->assertNull($updatedIssue->assignee_jira_app_user_id);
        $this->assertDatabaseCount('jira_issues', 1);
    }

    /** @test */
    public function store_issue_throws_exception_for_missing_id_or_key()
    {
        // Set up log mock for this test
        Log::shouldReceive('info', 'warning', 'error', 'debug')->andReturnNull();

        $this->expectException(InvalidArgumentException::class);
        $project = JiraProject::factory()->create();
        $issueDataFromApi = ['fields' => ['summary' => 'Test', 'status' => ['name' => 'Open']]]; // Missing id and key
        $this->jiraImportService->storeIssue($issueDataFromApi, $project->id, null);
    }

    /** @test */
    public function store_issue_throws_exception_for_missing_fields()
    {
        // Set up log mock for this test
        Log::shouldReceive('info', 'warning', 'error', 'debug')->andReturnNull();

        $this->expectException(InvalidArgumentException::class);
        $project = JiraProject::factory()->create();
        $issueDataFromApi = ['id' => 'ID-1', 'key' => 'KEY-1']; // Missing fields
        $this->jiraImportService->storeIssue($issueDataFromApi, $project->id, null);
    }

    /** @test */
    public function it_can_store_worklog_data()
    {
        // Set up log mock for this test
        Log::shouldReceive('info', 'warning', 'error', 'debug')->andReturnNull();

        $issue = JiraIssue::factory()->create();
        $author = JiraAppUser::factory()->create(['jira_account_id' => 'author-id']);
        $startedTime = Carbon::now()->subHour()->toIso8601String();

        $worklogDataFromApi = [
            'id' => 'worklog-777',
            'timeSpentSeconds' => 1800,
            'started' => $startedTime,
            // 'author' is handled by passing $author->id
        ];

        $worklog = $this->jiraImportService->storeWorklog($worklogDataFromApi, $issue->id, $author->id);

        $this->assertInstanceOf(JiraWorklog::class, $worklog);
        $this->assertEquals('worklog-777', $worklog->jira_id);
        $this->assertEquals(1800, $worklog->time_spent_seconds);
        $this->assertEquals(Carbon::parse($startedTime)->timestamp, $worklog->started_at->timestamp);
        $this->assertEquals($issue->id, $worklog->jira_issue_id);
        $this->assertEquals($author->id, $worklog->jira_app_user_id);
        $this->assertDatabaseHas('jira_worklogs', ['jira_id' => 'worklog-777']);

        // Test update
        $updatedStartedTime = Carbon::now()->subMinutes(30)->toIso8601String();
        $updatedWorklogData = [
            'id' => 'worklog-777',
            'timeSpentSeconds' => 3600,
            'started' => $updatedStartedTime,
        ];
        $updatedWorklog = $this->jiraImportService->storeWorklog($updatedWorklogData, $issue->id, $author->id);
        $this->assertEquals(3600, $updatedWorklog->time_spent_seconds);
        $this->assertEquals(Carbon::parse($updatedStartedTime)->timestamp, $updatedWorklog->started_at->timestamp);
        $this->assertDatabaseCount('jira_worklogs', 1);
    }

    /** @test */
    public function store_worklog_throws_exception_for_missing_data()
    {
        // Set up log mock for this test
        Log::shouldReceive('info', 'warning', 'error', 'debug')->andReturnNull();

        $this->expectException(InvalidArgumentException::class);
        $issue = JiraIssue::factory()->create();
        $author = JiraAppUser::factory()->create();
        $worklogDataFromApi = ['id' => 'wl-1']; // Missing timeSpentSeconds and started
        $this->jiraImportService->storeWorklog($worklogDataFromApi, $issue->id, $author->id);
    }

    /** @test */
    public function test_import_data_for_all_configured_projects_happy_path()
    {
        // Set up log mock for this test
        Log::shouldReceive('info', 'warning', 'error', 'debug')->andReturnNull();

        // 1. Setup: Create JIRA settings with the actual schema
        \App\Models\JiraSetting::factory()->create([
            'project_keys' => ['TESTPROJ'],
        ]);

        $projectKey = 'TESTPROJ';
        // Use a fixed timestamp string to avoid timing issues
        $fixedTimestamp = '2023-12-01T10:30:00+00:00';

        // Mock API responses matching actual method signatures
        $apiProjectData = [
            ['id' => '10001', 'key' => $projectKey, 'name' => 'Test Project'],
        ];

        $apiUserData_Assignee = [
            'accountId' => 'user-assignee-id',
            'displayName' => 'Assignee User',
            'emailAddress' => 'assignee@example.com',
        ];
        $apiUserData_WorklogAuthor = [
            'accountId' => 'user-author-id',
            'displayName' => 'Worklog Author User',
            'emailAddress' => 'author@example.com',
        ];

        $apiWorklogData = [
            'id' => '30001',
            'author' => $apiUserData_WorklogAuthor,
            'timeSpentSeconds' => 3600,
            'started' => $fixedTimestamp,
        ];

        $apiIssueData = [
            [
                'id' => '20001',
                'key' => $projectKey.'-1',
                'fields' => [
                    'summary' => 'Test Issue 1',
                    'status' => ['name' => 'Open'],
                    'assignee' => $apiUserData_Assignee,
                    'project' => ['key' => $projectKey, 'id' => '10001'],
                    'issuetype' => ['name' => 'Task'],
                    'created' => '2023-11-29T09:00:00+00:00',
                    'updated' => '2023-11-30T15:00:00+00:00',
                    'timetracking' => ['originalEstimateSeconds' => 7200],
                ],
            ],
        ];

        // Set up mocks to match actual method signatures
        $this->jiraApiServiceMock->shouldReceive('getProjects')
            ->once()
            ->with(['TESTPROJ'])
            ->andReturn($apiProjectData);

        $this->jiraApiServiceMock->shouldReceive('getIssuesForProject')
            ->once()
            ->with($projectKey, [
                'fields' => 'key,summary,status,assignee,project,issuetype,created,updated,timetracking,worklog',
                'maxResults' => 50,
            ])
            ->andReturn($apiIssueData);

        $this->jiraApiServiceMock->shouldReceive('getWorklogsForIssue')
            ->once()
            ->with($projectKey.'-1')
            ->andReturn([$apiWorklogData]);

        // 2. Action
        $results = $this->jiraImportService->importDataForAllConfiguredProjects();

        // 3. Assertions
        $this->assertTrue($results['success']);
        $this->assertEquals(1, $results['projects_processed']);
        $this->assertEquals(1, $results['issues_processed']);
        $this->assertEquals(1, $results['worklogs_imported']);
        // users_processed should be 2 (one assignee, one distinct worklog author)
        $this->assertEquals(2, $results['users_processed']);
        $this->assertEmpty($results['errors']);

        // Assert database records
        $this->assertDatabaseHas('jira_projects', ['project_key' => $projectKey]);
        $this->assertDatabaseHas('jira_app_users', ['jira_account_id' => 'user-assignee-id']);
        $this->assertDatabaseHas('jira_app_users', ['jira_account_id' => 'user-author-id']);
        $this->assertDatabaseCount('jira_app_users', 2); // Ensure only two users were created

        $this->assertDatabaseHas('jira_issues', ['issue_key' => $projectKey.'-1']);
        $dbIssue = JiraIssue::where('issue_key', $projectKey.'-1')->first();
        $this->assertNotNull($dbIssue);

        $this->assertDatabaseHas('jira_worklogs', [
            'jira_id' => '30001',
            'jira_issue_id' => $dbIssue->id,
            'time_spent_seconds' => 3600,
        ]);
        $dbWorklog = JiraWorklog::where('jira_id', '30001')->first();
        $this->assertNotNull($dbWorklog);

        // Compare timestamps using Carbon::parse to ensure consistent parsing
        $expectedTimestamp = Carbon::parse($fixedTimestamp);
        $actualTimestamp = $dbWorklog->started_at;
        $this->assertTrue($expectedTimestamp->equalTo($actualTimestamp),
            "Timestamps don't match. Expected: {$expectedTimestamp->toIso8601String()}, Actual: {$actualTimestamp->toIso8601String()}");

        // Verify assignee and author relationships
        $dbAssignee = JiraAppUser::where('jira_account_id', 'user-assignee-id')->first();
        $this->assertEquals($dbAssignee->id, $dbIssue->assignee_jira_app_user_id);

        $dbWorklogAuthor = JiraAppUser::where('jira_account_id', 'user-author-id')->first();
        $this->assertEquals($dbWorklogAuthor->id, $dbWorklog->jira_app_user_id);
    }
}
