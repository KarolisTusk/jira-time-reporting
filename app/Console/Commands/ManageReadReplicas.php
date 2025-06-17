<?php

namespace App\Console\Commands;

use App\Services\ReadReplicaService;
use App\Services\OptimizedWorklogQueryService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class ManageReadReplicas extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:replicas:manage 
                            {action : Action: health, routing, test-performance, validate-prd}
                            {--project= : Specific project key for testing}
                            {--format=table : Output format: table, json}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage read replicas for optimal JIRA sync performance (PRD compliance)';

    /**
     * Execute the console command.
     */
    public function handle(ReadReplicaService $replicaService, OptimizedWorklogQueryService $queryService): int
    {
        $action = $this->argument('action');
        
        return match ($action) {
            'health' => $this->checkReplicaHealth($replicaService),
            'routing' => $this->showRoutingRecommendations($replicaService),
            'test-performance' => $this->testPerformance($replicaService, $queryService),
            'validate-prd' => $this->validatePrdCompliance($queryService),
            default => $this->handleInvalidAction($action)
        };
    }
    
    /**
     * Check replica health status.
     */
    private function checkReplicaHealth(ReadReplicaService $replicaService): int
    {
        $this->info('🔍 Checking Read Replica Health Status');
        $this->newLine();
        
        try {
            $health = $replicaService->checkReplicaHealth();
            
            if ($this->option('format') === 'json') {
                $this->line(json_encode($health, JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }
            
            $healthData = [];
            foreach ($health as $replica => $status) {
                $healthData[] = [
                    'Replica' => $replica,
                    'Status' => $status['status'] === 'healthy' ? '✅ Healthy' : '❌ Unhealthy',
                    'Response Time' => isset($status['response_time_ms']) ? $status['response_time_ms'] . 'ms' : 'N/A',
                    'Is Replica' => isset($status['is_replica']) ? ($status['is_replica'] ? 'Yes' : 'No') : 'Unknown',
                    'Estimated Lag' => isset($status['estimated_lag_seconds']) ? $status['estimated_lag_seconds'] . 's' : 'Unknown',
                    'Reporting Ready' => isset($status['suitable_for_reporting']) ? ($status['suitable_for_reporting'] ? '✅' : '❌') : '?',
                    'Dashboard Ready' => isset($status['suitable_for_dashboard']) ? ($status['suitable_for_dashboard'] ? '✅' : '❌') : '?',
                ];
            }
            
            $this->table([
                'Replica', 'Status', 'Response Time', 'Is Replica', 'Estimated Lag', 'Reporting Ready', 'Dashboard Ready'
            ], $healthData);
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Failed to check replica health: " . $e->getMessage());
            return self::FAILURE;
        }
    }
    
    /**
     * Show routing recommendations for PRD use cases.
     */
    private function showRoutingRecommendations(ReadReplicaService $replicaService): int
    {
        $this->info('📊 Read Replica Routing Recommendations (PRD Aligned)');
        $this->newLine();
        
        try {
            $recommendations = $replicaService->getRoutingRecommendations();
            
            if ($this->option('format') === 'json') {
                $this->line(json_encode($recommendations, JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }
            
            foreach ($recommendations as $useCase => $rec) {
                $this->info("📋 Use Case: " . str_replace('_', ' ', ucwords($useCase)));
                $this->line("   Description: " . $rec['requirements']['description']);
                $this->line("   Performance Target: " . $rec['requirements']['performance_target']);
                $this->line("   Data Freshness: " . $rec['requirements']['data_freshness']);
                $this->line("   Recommended Connection: " . $rec['recommended_connection']);
                $this->line("   Reason: " . $rec['reason']);
                $this->line("   Estimated Performance: " . $rec['estimated_performance']);
                $this->line("   Meets Requirements: " . ($rec['meets_requirements'] ? '✅ Yes' : '❌ No'));
                $this->newLine();
            }
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Failed to get routing recommendations: " . $e->getMessage());
            return self::FAILURE;
        }
    }
    
    /**
     * Test performance across different scenarios.
     */
    private function testPerformance(ReadReplicaService $replicaService, OptimizedWorklogQueryService $queryService): int
    {
        $this->info('⚡ Testing Read Replica Performance');
        $this->newLine();
        
        $projectKey = $this->option('project') ?? 'DEMO';
        
        // Test different scenarios from the PRD
        $scenarios = [
            'monthly_report_generation' => 'Monthly Report (< 30 min target)',
            'real_time_sync_monitoring' => 'Real-time Sync (< 5 sec target)', 
            'large_dataset_export' => 'Large Export (119k+ hours)',
            'client_dashboard_access' => 'Client Dashboard (< 10 sec target)',
        ];
        
        $results = [];
        
        foreach ($scenarios as $scenario => $description) {
            $this->line("Testing: {$description}");
            
            try {
                $startTime = microtime(true);
                
                // Get optimization for scenario
                $optimization = $replicaService->optimizeForScenario($scenario, [
                    'project_key' => $projectKey
                ]);
                
                // Test with a sample query
                if ($scenario === 'monthly_report_generation') {
                    $testResult = $queryService->generateMonthlyReportData([$projectKey], now());
                } else {
                    $testResult = $queryService->getProjectWorklogStats($projectKey);
                }
                
                $duration = (microtime(true) - $startTime) * 1000;
                
                $results[] = [
                    'Scenario' => $description,
                    'Duration' => round($duration, 2) . 'ms',
                    'Connection' => $optimization['recommended_connection'],
                    'Performance Est.' => $optimization['performance_estimate'],
                    'Status' => $duration < 5000 ? '✅ Good' : '⚠️ Slow',
                ];
                
            } catch (\Exception $e) {
                $results[] = [
                    'Scenario' => $description,
                    'Duration' => 'Error',
                    'Connection' => 'N/A',
                    'Performance Est.' => 'N/A',
                    'Status' => '❌ Failed: ' . $e->getMessage(),
                ];
            }
        }
        
        $this->newLine();
        $this->table(['Scenario', 'Duration', 'Connection', 'Performance Est.', 'Status'], $results);
        
        return self::SUCCESS;
    }
    
    /**
     * Validate PRD compliance for data integrity and performance.
     */
    private function validatePrdCompliance(OptimizedWorklogQueryService $queryService): int
    {
        $this->info('✅ Validating PRD Compliance (119,033.02 hours baseline)');
        $this->newLine();
        
        try {
            $validation = $queryService->validateDataIntegrity();
            
            if ($this->option('format') === 'json') {
                $this->line(json_encode($validation, JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }
            
            // Show summary first
            $summary = $validation['_summary'];
            $this->info('📊 Overall Data Integrity Summary:');
            $this->table([
                'Metric', 'Value'
            ], [
                ['Total Hours (All Projects)', number_format($summary['total_hours_all_projects'], 2)],
                ['Total Worklogs', number_format($summary['total_worklogs_all_projects'])],
                ['PRD Baseline Hours', number_format($summary['prd_baseline_hours'], 2)],
                ['Coverage Percentage', $summary['coverage_percentage'] . '%'],
                ['Validation Status', $summary['validation_status']],
                ['Validated At', $summary['validated_at']],
            ]);
            
            $this->newLine();
            
            // Show per-project breakdown
            unset($validation['_summary']);
            
            if (!empty($validation)) {
                $this->info('📋 Per-Project Validation:');
                $projectData = [];
                
                foreach ($validation as $projectKey => $data) {
                    $checks = $data['data_quality_checks'];
                    $qualityScore = ($checks['has_data'] ? 1 : 0) + 
                                  ($checks['reasonable_avg_hours'] ? 1 : 0) + 
                                  ($checks['active_users'] ? 1 : 0);
                    
                    $projectData[] = [
                        'Project' => $projectKey,
                        'Total Hours' => number_format($data['total_hours'], 2),
                        'Worklogs' => number_format($data['total_worklogs']),
                        'Users' => $data['unique_users'],
                        'Quality Score' => "{$qualityScore}/3",
                        'Status' => $qualityScore === 3 ? '✅ Excellent' : ($qualityScore >= 2 ? '⚠️ Good' : '❌ Issues'),
                    ];
                }
                
                $this->table(['Project', 'Total Hours', 'Worklogs', 'Users', 'Quality Score', 'Status'], $projectData);
            }
            
            // PRD compliance assessment
            $this->newLine();
            $this->info('🎯 PRD Compliance Assessment:');
            
            $coveragePass = $summary['coverage_percentage'] >= 95;
            $dataQualityPass = $summary['validation_status'] !== 'NEEDS_REVIEW';
            
            $this->line('   ✓ Data Coverage (95%+ target): ' . ($coveragePass ? '✅ PASS' : '❌ FAIL'));
            $this->line('   ✓ Data Quality: ' . ($dataQualityPass ? '✅ PASS' : '❌ FAIL'));
            $this->line('   ✓ Baseline Comparison (119k hours): ' . ($summary['total_hours_all_projects'] >= 100000 ? '✅ PASS' : '❌ FAIL'));
            
            $overallPass = $coveragePass && $dataQualityPass;
            $this->newLine();
            $this->info('🏆 Overall PRD Compliance: ' . ($overallPass ? '✅ COMPLIANT' : '❌ NON-COMPLIANT'));
            
            return $overallPass ? self::SUCCESS : self::FAILURE;
            
        } catch (\Exception $e) {
            $this->error("PRD validation failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }
    
    /**
     * Handle invalid action.
     */
    private function handleInvalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->newLine();
        $this->info('Available actions:');
        $this->line('  health           - Check replica health and lag status');
        $this->line('  routing          - Show routing recommendations for PRD use cases');
        $this->line('  test-performance - Test performance across different scenarios');
        $this->line('  validate-prd     - Validate PRD compliance (119k hours baseline)');
        $this->newLine();
        $this->info('Examples:');
        $this->line('  php artisan db:replicas:manage health');
        $this->line('  php artisan db:replicas:manage test-performance --project=DEMO');
        $this->line('  php artisan db:replicas:manage validate-prd --format=json');
        
        return self::FAILURE;
    }
}
