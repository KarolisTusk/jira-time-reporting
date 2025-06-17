<?php

namespace Tests\Feature;

use App\Console\Commands\JiraSyncDebug;
use App\Jobs\ProcessEnhancedJiraSync;
use App\Models\JiraSyncHistory;
use App\Models\JiraSyncLog;
use App\Models\JiraSetting;
use App\Services\SyncErrorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Exception;

class SyncDebugTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create basic JIRA settings
        JiraSetting::create([
            'jira_host' => 'https://test.atlassian.net',
            'username' => 'test@example.com',
            'api_token' => 'test-token',
            'project_keys' => ['TEST'],
        ]);
    }

    /** @test */
    public function it_can_identify_stuck_sync_processes()
    {
        // Create a sync that hasn't been updated in 20 minutes (stuck)
        $stuckSync = JiraSyncHistory::create([
            'started_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(20),
            'status' => 'in_progress',
            'sync_type' => 'manual',
            'triggered_by' => 1,
            'total_projects' => 1,
            'processed_projects' => 0,
            'progress_percentage' => 0,
            'current_operation' => 'Initializing...',
        ]);

        // Create a normal active sync
        $activeSync = JiraSyncHistory::create([
            'started_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(1),
            'status' => 'in_progress',
            'sync_type' => 'manual',
            'triggered_by' => 1,
            'total_projects' => 1,
            'processed_projects' => 0,
            'progress_percentage' => 25,
            'current_operation' => 'Processing project...',
        ]);

        $output = $this->artisan('jira:sync-debug', ['action' => 'status', '--details' => true]);
        
        $output->expectsOutput(function($output) {
            return str_contains($output, 'STUCK');
        });
        
        $output->assertExitCode(0);
    }

    /** @test */
    public function it_can_cleanup_stuck_sync_processes()
    {
        // Create multiple stuck syncs
        $stuckSyncs = collect();
        for ($i = 0; $i < 3; $i++) {
            $stuckSyncs->push(JiraSyncHistory::create([
                'started_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(20),
                'status' => 'in_progress',
                'sync_type' => 'manual',
                'triggered_by' => 1,
                'total_projects' => 1,
                'processed_projects' => 0,
                'progress_percentage' => 0,
                'current_operation' => 'Stuck process...',
            ]));
        }

        $this->artisan('jira:sync-debug', [
            'action' => 'cleanup',
            '--force' => true
        ])->assertExitCode(0);

        // Check that stuck syncs were marked as failed
        foreach ($stuckSyncs as $sync) {
            $sync->refresh();
            $this->assertEquals('failed', $sync->status);
            $this->assertNotNull($sync->completed_at);
        }
    }

    /** @test */
    public function it_can_show_detailed_sync_logs()
    {
        $sync = JiraSyncHistory::create([
            'started_at' => now()->subMinutes(10),
            'status' => 'failed',
            'sync_type' => 'manual',
            'triggered_by' => 1,
            'total_projects' => 1,
            'processed_projects' => 0,
            'error_count' => 2,
            'current_operation' => 'Failed during initialization',
        ]);

        // Create some log entries
        JiraSyncLog::create([
            'jira_sync_history_id' => $sync->id,
            'timestamp' => now(),
            'level' => 'error',
            'message' => 'Connection timeout to JIRA API',
            'context' => json_encode(['category' => 'network', 'severity' => 'high']),
        ]);

        JiraSyncLog::create([
            'jira_sync_history_id' => $sync->id,
            'timestamp' => now(),
            'level' => 'error',
            'message' => 'Authentication failed',
            'context' => json_encode(['category' => 'jira_api', 'severity' => 'critical']),
        ]);

                 $output = $this->artisan('jira:sync-debug', [
             'action' => 'logs',
             '--sync-id' => $sync->id,
             '--details' => true
         ]);

        $output->expectsOutput(function($output) {
            return str_contains($output, 'Connection timeout to JIRA API') &&
                   str_contains($output, 'Authentication failed');
        });

        $output->assertExitCode(0);
    }

    /** @test */
    public function it_can_run_diagnostic_tests()
    {
        $this->artisan('jira:sync-debug', ['action' => 'test'])
            ->expectsOutput(function($output) {
                return str_contains($output, 'Running Diagnostic Tests') &&
                       str_contains($output, 'Database Connection') &&
                       str_contains($output, 'Test Summary');
            })
            ->assertExitCode(0);
    }

    /** @test */
    public function sync_error_service_can_analyze_exceptions()
    {
        $errorService = new SyncErrorService();
        
        // Test network exception
        $networkException = new Exception('Connection timeout after 30 seconds');
        $analysis = $errorService->analyzeException($networkException);
        
        $this->assertEquals('network', $analysis['category']);
        $this->assertEquals('medium', $analysis['severity']);
        $this->assertTrue($analysis['is_retryable']);
        $this->assertNotEmpty($analysis['suggestions']);

        // Test API authentication exception
        $authException = new Exception('HTTP 401: Unauthorized access to JIRA API');
        $analysis = $errorService->analyzeException($authException);
        
        $this->assertEquals('jira_api', $analysis['category']);
        $this->assertEquals('high', $analysis['severity']);
        $this->assertFalse($analysis['is_retryable']);
        $this->assertContains('Verify JIRA API token is correct and not expired', $analysis['suggestions']);

        // Test memory exception
        $memoryException = new Exception('Fatal error: Allowed memory size of 128M exhausted');
        $analysis = $errorService->analyzeException($memoryException);
        
        $this->assertEquals('memory', $analysis['category']);
        $this->assertEquals('critical', $analysis['severity']);
        $this->assertContains('Reduce batch size in sync configuration', $analysis['suggestions']);
    }

    /** @test */
    public function sync_error_service_can_log_detailed_errors()
    {
        $sync = JiraSyncHistory::create([
            'started_at' => now(),
            'status' => 'in_progress',
            'sync_type' => 'manual',
            'triggered_by' => 1,
            'total_projects' => 1,
            'processed_projects' => 0,
            'error_count' => 0,
        ]);

        $errorService = new SyncErrorService();
        $exception = new Exception('JIRA API rate limit exceeded (HTTP 429)');
        
        $errorService->logSyncError($sync, $exception, [
            'entity_type' => 'project',
            'entity_id' => 'TEST',
        ]);

        // Check that error was logged
        $this->assertEquals(1, $sync->fresh()->error_count);
        
        $logEntry = JiraSyncLog::where('jira_sync_history_id', $sync->id)->first();
        $this->assertNotNull($logEntry);
        $this->assertEquals('error', $logEntry->level);
        
        $context = json_decode($logEntry->context, true);
        $this->assertEquals('jira_api', $context['category']);
        $this->assertEquals('medium', $context['severity']);
    }

    /** @test */
    public function it_can_generate_error_reports()
    {
        $sync = JiraSyncHistory::create([
            'started_at' => now()->subHour(),
            'status' => 'failed',
            'sync_type' => 'manual',
            'triggered_by' => 1,
            'total_projects' => 1,
            'processed_projects' => 0,
            'error_count' => 3,
        ]);

        // Create various error types
        $errors = [
            ['category' => 'network', 'severity' => 'medium', 'retryable' => true],
            ['category' => 'jira_api', 'severity' => 'high', 'retryable' => false],
            ['category' => 'memory', 'severity' => 'critical', 'retryable' => true],
        ];

        foreach ($errors as $i => $error) {
            JiraSyncLog::create([
                'jira_sync_history_id' => $sync->id,
                'timestamp' => now(),
                'level' => 'error',
                'message' => "Error message {$i}",
                'context' => json_encode([
                    'category' => $error['category'],
                    'severity' => $error['severity'],
                    'is_retryable' => $error['retryable'],
                ]),
            ]);
        }

        $errorService = new SyncErrorService();
        $report = $errorService->generateErrorReport($sync);

        $this->assertEquals($sync->id, $report['sync_id']);
        $this->assertEquals(3, $report['error_stats']['total_errors']);
        $this->assertEquals(2, $report['error_stats']['retryable_errors']);
        $this->assertEquals(1, $report['error_stats']['critical_errors']);
        $this->assertNotEmpty($report['recommendations']);
        $this->assertNotEmpty($report['next_steps']);
    }

    /** @test */
    public function it_can_recover_failed_syncs()
    {
        Queue::fake();

        $failedSync = JiraSyncHistory::create([
            'started_at' => now()->subHour(),
            'status' => 'failed',
            'sync_type' => 'manual',
            'triggered_by' => 1,
            'total_projects' => 1,
            'processed_projects' => 0,
            'error_count' => 1,
        ]);

        $this->artisan('jira:sync-debug', [
            'action' => 'recover',
            '--sync-id' => $failedSync->id,
            '--force' => true
        ])->assertExitCode(0);

        // Check that sync was reset to pending
        $failedSync->refresh();
        $this->assertEquals('pending', $failedSync->status);
        $this->assertEquals(0, $failedSync->progress_percentage);

        // Check that job was dispatched
        Queue::assertPushed(ProcessEnhancedJiraSync::class);
    }

    /** @test */
    public function it_detects_queue_job_issues()
    {
        $sync = JiraSyncHistory::create([
            'started_at' => now()->subMinutes(5),
            'status' => 'pending',
            'sync_type' => 'manual',
            'triggered_by' => 1,
            'total_projects' => 1,
            'processed_projects' => 0,
        ]);

        // Simulate a failed job in the failed_jobs table
        DB::table('failed_jobs')->insert([
            'uuid' => 'test-uuid',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['data' => ['commandName' => ProcessEnhancedJiraSync::class, 'command' => serialize(['jira_sync_history_id' => $sync->id])]]),
            'exception' => 'Test exception',
            'failed_at' => now(),
        ]);

                 $output = $this->artisan('jira:sync-debug', [
             'action' => 'status',
             '--sync-id' => $sync->id,
             '--details' => true
         ]);

        $output->expectsOutput(function($output) {
            return str_contains($output, 'failed jobs');
        });

        $output->assertExitCode(0);
    }

    /** @test */
    public function it_provides_sync_summary_statistics()
    {
        // Create syncs with different statuses over the past week
        $statuses = ['completed', 'failed', 'in_progress', 'pending'];
        
        foreach ($statuses as $status) {
            for ($i = 0; $i < 2; $i++) {
                JiraSyncHistory::create([
                    'started_at' => now()->subDays(rand(0, 6)),
                    'status' => $status,
                    'sync_type' => 'manual',
                    'triggered_by' => 1,
                    'total_projects' => 1,
                    'processed_projects' => $status === 'completed' ? 1 : 0,
                    'progress_percentage' => $status === 'completed' ? 100 : rand(0, 50),
                ]);
            }
        }

        $output = $this->artisan('jira:sync-debug', ['action' => 'status']);

        $output->expectsOutput(function($output) {
            return str_contains($output, 'Sync Summary') &&
                   str_contains($output, 'completed') &&
                   str_contains($output, 'failed');
        });

        $output->assertExitCode(0);
    }

    /** @test */
    public function it_can_test_system_prerequisites()
    {
        $command = new JiraSyncDebug();
        
        // Test database connection
        $result = $this->callMethod($command, 'testDatabaseConnection');
        $this->assertEquals('pass', $result['status']);

        // Test sync prerequisites
        $result = $this->callMethod($command, 'testSyncPrerequisites');
        $this->assertEquals('pass', $result['status']);
    }

    /** @test */
    public function it_identifies_memory_and_disk_issues()
    {
        $command = new JiraSyncDebug();
        
        // Test memory usage
        $result = $this->callMethod($command, 'testMemoryUsage');
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('message', $result);

        // Test disk space
        $result = $this->callMethod($command, 'testDiskSpace');
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('message', $result);
    }

    /**
     * Call a protected method on an object.
     */
    protected function callMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
} 