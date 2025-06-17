<?php

namespace App\Console\Commands;

use App\Models\JiraIssue;
use App\Models\JiraProject;
use App\Models\JiraAppUser;
use App\Models\JiraWorklog;
use Illuminate\Console\Command;
use Carbon\Carbon;

class TestJiraIssuesData extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'jira:test-data 
                            {--worklogs : Generate test worklogs for existing issues}
                            {--count=10 : Number of test worklogs to generate}';

    /**
     * The console command description.
     */
    protected $description = 'Generate test data for JIRA issues browser';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('worklogs')) {
            $this->generateTestWorklogs();
        } else {
            $this->showCurrentStats();
        }

        return 0;
    }

    /**
     * Generate test worklogs for existing issues.
     */
    private function generateTestWorklogs()
    {
        $count = (int) $this->option('count');
        
        // Get some random issues
        $issues = JiraIssue::inRandomOrder()->limit($count)->get();
        
        if ($issues->isEmpty()) {
            $this->error('No issues found in database. Please sync some JIRA data first.');
            return;
        }

        // Get or create some test users
        $users = $this->getOrCreateTestUsers();
        
        $this->info("Generating {$count} test worklogs...");
        
        $resourceTypes = ['frontend', 'backend', 'qa', 'devops', 'management'];
        
        foreach ($issues as $issue) {
            $user = $users->random();
            $resourceType = $resourceTypes[array_rand($resourceTypes)];
            
            // Generate random worklog data
            $timeSpent = rand(1, 8) * 3600; // 1-8 hours in seconds
            $startedAt = Carbon::now()->subDays(rand(1, 30))->subHours(rand(0, 23));
            
            JiraWorklog::create([
                'jira_issue_id' => $issue->id,
                'jira_app_user_id' => $user->id,
                'jira_id' => 'test-' . uniqid(),
                'time_spent_seconds' => $timeSpent,
                'started_at' => $startedAt,
                'resource_type' => $resourceType,
            ]);
            
            $this->line("Created worklog for {$issue->issue_key} - " . ($timeSpent/3600) . "h by {$user->display_name}");
        }
        
        $this->info("Generated {$count} test worklogs successfully!");
        $this->showCurrentStats();
    }

    /**
     * Get or create test users.
     */
    private function getOrCreateTestUsers()
    {
        $testUsers = [
            ['jira_account_id' => 'test-user-1', 'display_name' => 'John Developer', 'email_address' => 'john@example.com'],
            ['jira_account_id' => 'test-user-2', 'display_name' => 'Jane Designer', 'email_address' => 'jane@example.com'],
            ['jira_account_id' => 'test-user-3', 'display_name' => 'Bob Tester', 'email_address' => 'bob@example.com'],
            ['jira_account_id' => 'test-user-4', 'display_name' => 'Alice Manager', 'email_address' => 'alice@example.com'],
        ];

        $users = collect();
        
        foreach ($testUsers as $userData) {
            $user = JiraAppUser::firstOrCreate(
                ['jira_account_id' => $userData['jira_account_id']],
                $userData
            );
            $users->push($user);
        }
        
        return $users;
    }

    /**
     * Show current database stats.
     */
    private function showCurrentStats()
    {
        $this->info('Current JIRA Data Statistics:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Issues', JiraIssue::count()],
                ['Issues with Worklogs', JiraIssue::has('worklogs')->count()],
                ['Total Worklogs', JiraWorklog::count()],
                ['Total Projects', JiraProject::count()],
                ['Total Users', JiraAppUser::count()],
                ['Total Logged Hours', round(JiraWorklog::sum('time_spent_seconds') / 3600, 2)],
            ]
        );

        if (JiraWorklog::count() === 0) {
            $this->warn('No worklogs found. Run with --worklogs to generate test data.');
        }
    }
} 