<?php

namespace App\Console\Commands;

use App\Models\JiraProject;
use App\Models\JiraWorklogSyncStatus;
use App\Services\JiraWorklogSyncValidationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ViewWorklogSyncValidation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jira:worklog-validation 
                            {--projects=* : Specific project keys to check (optional)}
                            {--detailed : Show detailed validation results}
                            {--summary : Show only summary statistics}
                            {--export= : Export results to file (json, csv)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'View worklog sync validation results and data quality metrics';

    /**
     * Execute the console command.
     */
    public function handle(JiraWorklogSyncValidationService $validationService): int
    {
        $this->info('📊 JIRA Worklog Sync Validation Report');
        $this->line('');

        try {
            // Determine projects to check
            $projectKeys = $this->getProjectsToCheck();
            if (empty($projectKeys)) {
                $this->warn('⚠️ No projects found to validate.');
                return self::SUCCESS;
            }

            $this->info("🔍 Checking validation for projects: " . implode(', ', $projectKeys));
            $this->line('');

            // Get validation results from sync status metadata
            $validationResults = $this->getStoredValidationResults($projectKeys);

            if (empty($validationResults)) {
                $this->warn('⚠️ No validation results found. Run worklog sync first.');
                return self::SUCCESS;
            }

            // Show results based on options
            if ($this->option('summary')) {
                $this->showSummaryReport($validationResults, $validationService);
            } else {
                $this->showDetailedReport($validationResults);
            }

            // Export if requested
            $exportFormat = $this->option('export');
            if ($exportFormat) {
                $this->exportResults($validationResults, $exportFormat);
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Validation report failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Get projects to check based on command options.
     */
    protected function getProjectsToCheck(): array
    {
        $specifiedProjects = $this->option('projects');
        
        if (!empty($specifiedProjects)) {
            return $specifiedProjects;
        }

        // Get all available projects
        return JiraProject::pluck('project_key')->toArray();
    }

    /**
     * Get stored validation results from sync status metadata.
     */
    protected function getStoredValidationResults(array $projectKeys): array
    {
        $results = [];

        foreach ($projectKeys as $projectKey) {
            $syncStatus = JiraWorklogSyncStatus::where('project_key', $projectKey)->first();
            
            if ($syncStatus && $syncStatus->sync_metadata) {
                $metadata = $syncStatus->sync_metadata;
                
                if (isset($metadata['validation'])) {
                    $results[$projectKey] = [
                        'project_key' => $projectKey,
                        'last_sync_at' => $syncStatus->last_sync_at,
                        'validation_passed' => $metadata['validation']['validation_passed'],
                        'sync_completeness_score' => $metadata['validation']['completeness_score'],
                        'discrepancy_percentage' => $metadata['validation']['discrepancy_percentage'],
                        'data_quality_score' => $metadata['validation']['data_quality_score'],
                        'resource_type_distribution' => $metadata['validation']['resource_type_distribution'] ?? [],
                        'validation_timestamp' => $metadata['validation']['validation_timestamp'],
                        'worklogs_processed' => $syncStatus->worklogs_processed,
                        'worklogs_added' => $syncStatus->worklogs_added,
                        'worklogs_updated' => $syncStatus->worklogs_updated,
                        'last_sync_status' => $syncStatus->last_sync_status,
                        'last_error' => $syncStatus->last_error,
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Show detailed validation report.
     */
    protected function showDetailedReport(array $validationResults): void
    {
        foreach ($validationResults as $projectKey => $result) {
            $this->line("📁 Project: {$projectKey}");
            $this->line("  Last Sync: " . ($result['last_sync_at'] ? $result['last_sync_at']->format('Y-m-d H:i:s') : 'Never'));
            $this->line("  Sync Status: " . $this->formatSyncStatus($result['last_sync_status']));
            $this->line("  Validation: " . ($result['validation_passed'] ? '✅ Passed' : '❌ Failed'));
            $this->line("  Completeness Score: " . $result['sync_completeness_score'] . '%');
            $this->line("  Discrepancy: " . round($result['discrepancy_percentage'], 2) . '%');
            
            if ($result['data_quality_score']) {
                $this->line("  Data Quality: " . round($result['data_quality_score'], 1) . '%');
            }

            $this->line("  Worklogs Processed: " . number_format($result['worklogs_processed']));
            $this->line("  Worklogs Added: " . number_format($result['worklogs_added']));
            $this->line("  Worklogs Updated: " . number_format($result['worklogs_updated']));

            // Show resource type distribution if detailed option is used
            if ($this->option('detailed') && !empty($result['resource_type_distribution'])) {
                $this->line("  Resource Type Distribution:");
                foreach ($result['resource_type_distribution'] as $type => $count) {
                    $this->line("    {$type}: " . number_format($count));
                }
            }

            if ($result['last_error']) {
                $this->line("  Last Error: " . $result['last_error']);
            }

            $this->line('');
        }
    }

    /**
     * Show summary validation report.
     */
    protected function showSummaryReport(array $validationResults, JiraWorklogSyncValidationService $validationService): void
    {
        // Convert to format expected by validation service
        $validationData = array_map(function ($result) {
            return [
                'project_key' => $result['project_key'],
                'validation_passed' => $result['validation_passed'],
                'sync_completeness_score' => $result['sync_completeness_score'],
                'discrepancy_percentage' => $result['discrepancy_percentage'],
                'validation_errors' => $result['last_error'] ? [$result['last_error']] : [],
                'validation_warnings' => [],
            ];
        }, $validationResults);

        $summary = $validationService->generateValidationSummary($validationData);

        $this->info('📈 Validation Summary:');
        $this->line("  Total Projects: " . $summary['total_projects']);
        $this->line("  Projects Passed: " . $summary['projects_passed'] . " (" . 
                   round(($summary['projects_passed'] / max(1, $summary['total_projects'])) * 100, 1) . "%)");
        $this->line("  Projects Failed: " . $summary['projects_failed']);
        $this->line("  Average Completeness Score: " . round($summary['average_completeness_score'], 1) . '%');
        $this->line("  Overall Discrepancy: " . round($summary['overall_discrepancy_percentage'], 2) . '%');
        $this->line("  Total Errors: " . $summary['total_errors']);
        $this->line("  Total Warnings: " . $summary['total_warnings']);

        if (!empty($summary['critical_issues'])) {
            $this->line('');
            $this->warn('⚠️ Critical Issues:');
            foreach ($summary['critical_issues'] as $issue) {
                $this->line("  • {$issue}");
            }
        }

        if (!empty($summary['recommendations'])) {
            $this->line('');
            $this->info('💡 Recommendations:');
            foreach ($summary['recommendations'] as $recommendation) {
                $this->line("  • {$recommendation}");
            }
        }
    }

    /**
     * Export validation results to file.
     */
    protected function exportResults(array $validationResults, string $format): void
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        $filename = "worklog_validation_report_{$timestamp}.{$format}";

        try {
            switch (strtolower($format)) {
                case 'json':
                    file_put_contents($filename, json_encode($validationResults, JSON_PRETTY_PRINT));
                    break;
                
                case 'csv':
                    $handle = fopen($filename, 'w');
                    
                    // CSV headers
                    fputcsv($handle, [
                        'Project Key', 'Last Sync', 'Validation Passed', 'Completeness Score', 
                        'Discrepancy %', 'Data Quality Score', 'Worklogs Processed', 
                        'Worklogs Added', 'Worklogs Updated', 'Sync Status', 'Last Error'
                    ]);
                    
                    // CSV data
                    foreach ($validationResults as $result) {
                        fputcsv($handle, [
                            $result['project_key'],
                            $result['last_sync_at'] ? $result['last_sync_at']->format('Y-m-d H:i:s') : '',
                            $result['validation_passed'] ? 'Yes' : 'No',
                            $result['sync_completeness_score'],
                            round($result['discrepancy_percentage'], 2),
                            $result['data_quality_score'] ? round($result['data_quality_score'], 1) : '',
                            $result['worklogs_processed'],
                            $result['worklogs_added'],
                            $result['worklogs_updated'],
                            $result['last_sync_status'],
                            $result['last_error'] ?? '',
                        ]);
                    }
                    
                    fclose($handle);
                    break;
                
                default:
                    $this->error("Unsupported export format: {$format}");
                    return;
            }

            $this->info("✅ Results exported to: {$filename}");

        } catch (\Exception $e) {
            $this->error("Failed to export results: " . $e->getMessage());
        }
    }

    /**
     * Format sync status for display.
     */
    protected function formatSyncStatus(string $status): string
    {
        return match ($status) {
            'completed' => '✅ Completed',
            'completed_with_errors' => '⚠️ Completed with errors',
            'in_progress' => '🔄 In Progress',
            'failed' => '❌ Failed',
            'pending' => '⏳ Pending',
            default => "❓ {$status}",
        };
    }
}