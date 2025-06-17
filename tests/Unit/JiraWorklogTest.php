<?php

namespace Tests\Unit;

use App\Models\JiraAppUser;
use App\Models\JiraIssue;
use App\Models\JiraProject;
use App\Models\JiraWorklog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JiraWorklogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Remove the manual migrate:fresh call - RefreshDatabase trait handles this
    }

    public function test_in_date_range_scope_filters_by_start_date(): void
    {
        $project = JiraProject::factory()->create();
        $issue = JiraIssue::factory()->create(['jira_project_id' => $project->id]);
        $user = JiraAppUser::factory()->create();

        // Create worklogs with different dates
        $oldWorklog = JiraWorklog::factory()->create([
            'jira_issue_id' => $issue->id,
            'jira_app_user_id' => $user->id,
            'started_at' => Carbon::now()->subDays(10),
            'time_spent_seconds' => 3600,
        ]);

        $newWorklog = JiraWorklog::factory()->create([
            'jira_issue_id' => $issue->id,
            'jira_app_user_id' => $user->id,
            'started_at' => Carbon::now()->subDays(2),
            'time_spent_seconds' => 7200,
        ]);

        // Filter by start date (last 5 days)
        $filteredWorklogs = JiraWorklog::inDateRange(Carbon::now()->subDays(5))->get();

        $this->assertCount(1, $filteredWorklogs);
        $this->assertEquals($newWorklog->id, $filteredWorklogs->first()->id);
    }

    public function test_in_date_range_scope_filters_by_end_date(): void
    {
        $project = JiraProject::factory()->create();
        $issue = JiraIssue::factory()->create(['jira_project_id' => $project->id]);
        $user = JiraAppUser::factory()->create();

        // Create worklogs with different dates
        $oldWorklog = JiraWorklog::factory()->create([
            'jira_issue_id' => $issue->id,
            'jira_app_user_id' => $user->id,
            'started_at' => Carbon::now()->subDays(10),
            'time_spent_seconds' => 3600,
        ]);

        $newWorklog = JiraWorklog::factory()->create([
            'jira_issue_id' => $issue->id,
            'jira_app_user_id' => $user->id,
            'started_at' => Carbon::now()->subDays(2),
            'time_spent_seconds' => 7200,
        ]);

        // Filter by end date (up to 5 days ago)
        $filteredWorklogs = JiraWorklog::inDateRange(null, Carbon::now()->subDays(5))->get();

        $this->assertCount(1, $filteredWorklogs);
        $this->assertEquals($oldWorklog->id, $filteredWorklogs->first()->id);
    }

    public function test_total_time_per_project_scope_aggregates_correctly(): void
    {
        // Create test data
        $project1 = JiraProject::factory()->create(['project_key' => 'PROJ1', 'name' => 'Project 1']);
        $project2 = JiraProject::factory()->create(['project_key' => 'PROJ2', 'name' => 'Project 2']);

        $issue1 = JiraIssue::factory()->create(['jira_project_id' => $project1->id]);
        $issue2 = JiraIssue::factory()->create(['jira_project_id' => $project1->id]);
        $issue3 = JiraIssue::factory()->create(['jira_project_id' => $project2->id]);

        $user = JiraAppUser::factory()->create();

        // Create worklogs for project 1 (total: 10800 seconds = 3 hours)
        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue1->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 3600, // 1 hour
            'started_at' => Carbon::now()->subDays(1),
        ]);

        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue2->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 7200, // 2 hours
            'started_at' => Carbon::now()->subDays(2),
        ]);

        // Create worklogs for project 2 (total: 1800 seconds = 0.5 hours)
        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue3->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 1800, // 0.5 hour
            'started_at' => Carbon::now()->subDays(1),
        ]);

        $results = JiraWorklog::totalTimePerProject()->get();

        $this->assertCount(2, $results);

        // Results should be ordered by total time desc (project1 first)
        $this->assertEquals('PROJ1', $results[0]->project_key);
        $this->assertEquals('Project 1', $results[0]->project_name);
        $this->assertEquals(10800, $results[0]->total_time_seconds);
        $this->assertEquals(2, $results[0]->worklog_count);

        $this->assertEquals('PROJ2', $results[1]->project_key);
        $this->assertEquals('Project 2', $results[1]->project_name);
        $this->assertEquals(1800, $results[1]->total_time_seconds);
        $this->assertEquals(1, $results[1]->worklog_count);
    }

    public function test_total_time_per_project_scope_with_date_filtering(): void
    {
        $project = JiraProject::factory()->create(['project_key' => 'PROJ1', 'name' => 'Project 1']);
        $issue = JiraIssue::factory()->create(['jira_project_id' => $project->id]);
        $user = JiraAppUser::factory()->create();

        // Create worklogs - one old, one recent
        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 3600, // Should be excluded
            'started_at' => Carbon::now()->subDays(10),
        ]);

        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 7200, // Should be included
            'started_at' => Carbon::now()->subDays(2),
        ]);

        // Filter for last 5 days
        $startDate = Carbon::now()->subDays(5);
        $results = JiraWorklog::totalTimePerProject($startDate)->get();

        $this->assertCount(1, $results);
        $this->assertEquals(7200, $results[0]->total_time_seconds);
        $this->assertEquals(1, $results[0]->worklog_count);
    }

    public function test_format_seconds_helper_formats_correctly(): void
    {
        $this->assertEquals('1h 30m', JiraWorklog::formatSeconds(5400)); // 1.5 hours
        $this->assertEquals('2h 0m', JiraWorklog::formatSeconds(7200)); // 2 hours
        $this->assertEquals('45m 30s', JiraWorklog::formatSeconds(2730)); // 45.5 minutes
        $this->assertEquals('30s', JiraWorklog::formatSeconds(30)); // 30 seconds
        $this->assertEquals('0s', JiraWorklog::formatSeconds(0)); // 0 seconds
    }

    public function test_get_formatted_time_spent_method(): void
    {
        $project = JiraProject::factory()->create();
        $issue = JiraIssue::factory()->create(['jira_project_id' => $project->id]);
        $user = JiraAppUser::factory()->create();

        $worklog = JiraWorklog::factory()->create([
            'jira_issue_id' => $issue->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 3600, // 1 hour
        ]);

        $this->assertEquals('1h 0m', $worklog->getFormattedTimeSpent());
    }

    public function test_total_time_by_user_for_project_scope_aggregates_correctly(): void
    {
        // Create test data
        $project1 = JiraProject::factory()->create(['project_key' => 'PROJ1', 'name' => 'Project 1']);
        $project2 = JiraProject::factory()->create(['project_key' => 'PROJ2', 'name' => 'Project 2']);

        $issue1 = JiraIssue::factory()->create(['jira_project_id' => $project1->id]);
        $issue2 = JiraIssue::factory()->create(['jira_project_id' => $project2->id]);

        $user1 = JiraAppUser::factory()->create(['display_name' => 'John Doe']);
        $user2 = JiraAppUser::factory()->create(['display_name' => 'Jane Smith']);

        // Create worklogs for user1 on project1 (total: 10800 seconds = 3 hours)
        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue1->id,
            'jira_app_user_id' => $user1->id,
            'time_spent_seconds' => 3600, // 1 hour
            'started_at' => Carbon::now()->subDays(1),
        ]);

        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue1->id,
            'jira_app_user_id' => $user1->id,
            'time_spent_seconds' => 7200, // 2 hours
            'started_at' => Carbon::now()->subDays(2),
        ]);

        // Create worklogs for user2 on project1 (total: 1800 seconds = 0.5 hours)
        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue1->id,
            'jira_app_user_id' => $user2->id,
            'time_spent_seconds' => 1800, // 0.5 hour
            'started_at' => Carbon::now()->subDays(1),
        ]);

        // Create worklogs for user1 on project2 (should be excluded when filtering by project1)
        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue2->id,
            'jira_app_user_id' => $user1->id,
            'time_spent_seconds' => 3600, // 1 hour
            'started_at' => Carbon::now()->subDays(1),
        ]);

        // Test without project filter (should include all projects)
        $allResults = JiraWorklog::totalTimeByUserForProject()->get();
        $this->assertCount(3, $allResults); // user1 on proj1, user2 on proj1, user1 on proj2

        // Test with project filter (should only include project1)
        $filteredResults = JiraWorklog::totalTimeByUserForProject($project1->id)->get();
        $this->assertCount(2, $filteredResults);

        // Results should be ordered by total time desc (user1 first with 3 hours)
        $this->assertEquals('John Doe', $filteredResults[0]->user_name);
        $this->assertEquals('PROJ1', $filteredResults[0]->project_key);
        $this->assertEquals(10800, $filteredResults[0]->total_time_seconds);
        $this->assertEquals(2, $filteredResults[0]->worklog_count);

        $this->assertEquals('Jane Smith', $filteredResults[1]->user_name);
        $this->assertEquals('PROJ1', $filteredResults[1]->project_key);
        $this->assertEquals(1800, $filteredResults[1]->total_time_seconds);
        $this->assertEquals(1, $filteredResults[1]->worklog_count);
    }

    public function test_total_time_by_user_for_project_scope_with_date_filtering(): void
    {
        $project = JiraProject::factory()->create(['project_key' => 'PROJ1', 'name' => 'Project 1']);
        $issue = JiraIssue::factory()->create(['jira_project_id' => $project->id]);
        $user = JiraAppUser::factory()->create(['display_name' => 'John Doe']);

        // Create worklogs - one old, one recent
        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 3600, // Should be excluded
            'started_at' => Carbon::now()->subDays(10),
        ]);

        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 7200, // Should be included
            'started_at' => Carbon::now()->subDays(2),
        ]);

        // Filter for last 5 days and specific project
        $startDate = Carbon::now()->subDays(5);
        $results = JiraWorklog::totalTimeByUserForProject($project->id, $startDate)->get();

        $this->assertCount(1, $results);
        $this->assertEquals('John Doe', $results[0]->user_name);
        $this->assertEquals(7200, $results[0]->total_time_seconds);
        $this->assertEquals(1, $results[0]->worklog_count);
    }

    public function test_project_time_trend_scope_weekly_aggregation(): void
    {
        // Create test data across different weeks
        $project1 = JiraProject::factory()->create(['project_key' => 'PROJ1', 'name' => 'Project 1']);
        $project2 = JiraProject::factory()->create(['project_key' => 'PROJ2', 'name' => 'Project 2']);

        $issue1 = JiraIssue::factory()->create(['jira_project_id' => $project1->id]);
        $issue2 = JiraIssue::factory()->create(['jira_project_id' => $project2->id]);

        $user = JiraAppUser::factory()->create();

        // Week 1 - Project 1: 7200 seconds
        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue1->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 3600,
            'started_at' => Carbon::now()->startOfWeek()->subWeek(),
        ]);

        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue1->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 3600,
            'started_at' => Carbon::now()->startOfWeek()->subWeek()->addDay(),
        ]);

        // Week 1 - Project 2: 1800 seconds
        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue2->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 1800,
            'started_at' => Carbon::now()->startOfWeek()->subWeek()->addDays(2),
        ]);

        // Current week - Project 1: 10800 seconds
        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue1->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 5400,
            'started_at' => Carbon::now()->startOfWeek(),
        ]);

        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue1->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 5400,
            'started_at' => Carbon::now()->startOfWeek()->addDay(),
        ]);

        // Test weekly trend for all projects
        $results = JiraWorklog::projectTimeTrend('weekly')->get();

        // Should have 3 records: PROJ1 in 2 weeks, PROJ2 in 1 week
        $this->assertCount(3, $results);

        // Group results by project for easier testing
        $proj1Results = $results->where('project_key', 'PROJ1');
        $proj2Results = $results->where('project_key', 'PROJ2');

        $this->assertCount(2, $proj1Results); // PROJ1 in 2 weeks
        $this->assertCount(1, $proj2Results); // PROJ2 in 1 week

        // Verify total times are aggregated correctly per week
        $proj1TotalTime = $proj1Results->sum('total_time_seconds');
        $proj2TotalTime = $proj2Results->sum('total_time_seconds');

        $this->assertEquals(18000, $proj1TotalTime); // 7200 + 10800
        $this->assertEquals(1800, $proj2TotalTime);
    }

    public function test_project_time_trend_scope_monthly_aggregation(): void
    {
        $project = JiraProject::factory()->create(['project_key' => 'PROJ1', 'name' => 'Project 1']);
        $issue = JiraIssue::factory()->create(['jira_project_id' => $project->id]);
        $user = JiraAppUser::factory()->create();

        // Current month
        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 3600,
            'started_at' => Carbon::now()->startOfMonth(),
        ]);

        // Previous month
        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 7200,
            'started_at' => Carbon::now()->startOfMonth()->subMonth(),
        ]);

        $results = JiraWorklog::projectTimeTrend('monthly')->get();

        $this->assertCount(2, $results);

        // Results should be ordered by period (month), so previous month first
        $this->assertEquals(7200, $results[0]->total_time_seconds);
        $this->assertEquals(3600, $results[1]->total_time_seconds);
    }

    public function test_project_time_trend_scope_with_project_filtering(): void
    {
        $project1 = JiraProject::factory()->create(['project_key' => 'PROJ1', 'name' => 'Project 1']);
        $project2 = JiraProject::factory()->create(['project_key' => 'PROJ2', 'name' => 'Project 2']);

        $issue1 = JiraIssue::factory()->create(['jira_project_id' => $project1->id]);
        $issue2 = JiraIssue::factory()->create(['jira_project_id' => $project2->id]);

        $user = JiraAppUser::factory()->create();

        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue1->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 3600,
            'started_at' => Carbon::now(),
        ]);

        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue2->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 7200,
            'started_at' => Carbon::now(),
        ]);

        // Filter by single project ID
        $filteredResults = JiraWorklog::projectTimeTrend('weekly', $project1->id)->get();
        $this->assertCount(1, $filteredResults);
        $this->assertEquals('PROJ1', $filteredResults[0]->project_key);

        // Filter by array of project IDs
        $multiFilterResults = JiraWorklog::projectTimeTrend('weekly', [$project1->id, $project2->id])->get();
        $this->assertCount(2, $multiFilterResults);
    }

    public function test_project_time_trend_scope_with_date_filtering(): void
    {
        $project = JiraProject::factory()->create(['project_key' => 'PROJ1', 'name' => 'Project 1']);
        $issue = JiraIssue::factory()->create(['jira_project_id' => $project->id]);
        $user = JiraAppUser::factory()->create();

        // Old worklog (should be excluded)
        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 3600,
            'started_at' => Carbon::now()->subMonths(2),
        ]);

        // Recent worklog (should be included)
        JiraWorklog::factory()->create([
            'jira_issue_id' => $issue->id,
            'jira_app_user_id' => $user->id,
            'time_spent_seconds' => 7200,
            'started_at' => Carbon::now()->subDays(5),
        ]);

        // Filter for last month
        $startDate = Carbon::now()->subMonth();
        $results = JiraWorklog::projectTimeTrend('weekly', null, $startDate)->get();

        $this->assertCount(1, $results);
        $this->assertEquals(7200, $results[0]->total_time_seconds);
    }
}
