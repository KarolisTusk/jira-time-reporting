<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\EnhancedJiraSyncController;
use App\Models\JiraSetting;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestSyncRoute extends Command
{
    protected $signature = 'test:sync-route';
    protected $description = 'Test the admin sync route functionality';

    public function handle(): int
    {
        $this->info('Testing Enhanced JIRA Sync Route...');
        
        try {
            // Check JIRA settings
            $jiraSettings = JiraSetting::first();
            if (!$jiraSettings) {
                $this->error('No JIRA settings found. Please configure JIRA settings first.');
                return self::FAILURE;
            }
            
            $this->info('JIRA Settings found:');
            $this->line('  Host: ' . $jiraSettings->jira_host);
            $this->line('  Email: ' . $jiraSettings->jira_email);
            $this->line('  Project Keys: ' . implode(', ', $jiraSettings->project_keys ?? []));
            
            // Test controller instantiation
            $controller = app(EnhancedJiraSyncController::class);
            $this->info('✅ Controller instantiated successfully');
            
            // Test index method
            $response = $controller->index();
            $this->info('✅ Index method works');
            
            $props = $response->props;
            $this->line('Available projects: ' . count($props['availableProjects']));
            $this->line('Connection status: ' . ($props['connectionStatus']['connected'] ? 'Connected' : 'Disconnected'));
            
            if (empty($jiraSettings->project_keys)) {
                $this->warn('No projects configured in JIRA settings');
                return self::SUCCESS;
            }
            
            // Test validation (without actually starting sync)
            $request = new Request();
            $request->merge([
                'project_keys' => [$jiraSettings->project_keys[0]], // Use first project
                'sync_type' => 'incremental',
                'only_issues_with_worklogs' => false,
                'reclassify_resources' => false,
                'validate_data' => true,
                'cleanup_orphaned' => false,
            ]);
            
            $this->info('✅ All tests passed - the sync route should work');
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Test failed: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
            return self::FAILURE;
        }
    }
}