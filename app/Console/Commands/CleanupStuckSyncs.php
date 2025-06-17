<?php

namespace App\Console\Commands;

use App\Models\JiraSyncHistory;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanupStuckSyncs extends Command
{
    protected $signature = 'jira:cleanup-stuck-syncs {--force : Force cleanup without confirmation}';
    protected $description = 'Clean up stuck JIRA sync operations';

    public function handle(): int
    {
        $this->info('🔍 Checking for stuck JIRA sync operations...');
        
        // Find syncs that are stuck (older than 2 hours and still pending/in_progress)
        $stuckSyncs = JiraSyncHistory::whereIn('status', ['pending', 'in_progress'])
            ->where('started_at', '<', now()->subHours(2))
            ->get();
            
        $recentSyncs = JiraSyncHistory::whereIn('status', ['pending', 'in_progress'])
            ->where('started_at', '>=', now()->subHours(2))
            ->get();
        
        if ($stuckSyncs->isEmpty() && $recentSyncs->isEmpty()) {
            $this->info('✅ No stuck sync operations found.');
            return self::SUCCESS;
        }
        
        if ($stuckSyncs->isNotEmpty()) {
            $this->warn("Found {$stuckSyncs->count()} stuck sync operation(s):");
            
            foreach ($stuckSyncs as $sync) {
                $duration = Carbon::parse($sync->started_at)->diffForHumans();
                $this->line("  ID: {$sync->id}, Status: {$sync->status}, Started: {$duration}");
            }
        }
        
        if ($recentSyncs->isNotEmpty()) {
            $this->info("Found {$recentSyncs->count()} recent sync operation(s) that might still be active:");
            
            foreach ($recentSyncs as $sync) {
                $duration = Carbon::parse($sync->started_at)->diffForHumans();
                $this->line("  ID: {$sync->id}, Status: {$sync->status}, Started: {$duration}");
            }
        }
        
        if ($stuckSyncs->isEmpty()) {
            $this->warn('No stuck syncs found, but there are recent syncs. Use --force to clean all active syncs.');
            return self::SUCCESS;
        }
        
        $force = $this->option('force');
        
        if (!$force && !$this->confirm('Mark stuck sync operations as failed?')) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }
        
        // Mark stuck syncs as failed
        $updatedCount = JiraSyncHistory::whereIn('id', $stuckSyncs->pluck('id'))
            ->update([
                'status' => 'failed',
                'completed_at' => now(),
                'current_operation' => 'Marked as failed due to timeout',
                'error_count' => 1,
            ]);
        
        $this->info("✅ Marked {$updatedCount} stuck sync operation(s) as failed.");
        
        // If force flag is used, also clean recent syncs
        if ($force && $recentSyncs->isNotEmpty()) {
            if ($this->confirm('Also mark recent syncs as failed? (Use with caution)')) {
                $recentUpdatedCount = JiraSyncHistory::whereIn('id', $recentSyncs->pluck('id'))
                    ->update([
                        'status' => 'failed',
                        'completed_at' => now(),
                        'current_operation' => 'Marked as failed (forced cleanup)',
                        'error_count' => 1,
                    ]);
                
                $this->info("✅ Marked {$recentUpdatedCount} recent sync operation(s) as failed.");
            }
        }
        
        return self::SUCCESS;
    }
}