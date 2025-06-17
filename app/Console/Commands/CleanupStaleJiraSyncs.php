<?php

namespace App\Console\Commands;

use App\Models\JiraSyncHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupStaleJiraSyncs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jira:cleanup-stale-syncs 
                            {--hours=2 : Hours after which a sync is considered stale}
                            {--dry-run : Only show what would be cleaned up}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup stale JIRA sync records that are stuck in progress';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hours = (int) $this->option('hours');
        $dryRun = $this->option('dry-run');
        
        $this->info("Looking for stale JIRA syncs (older than {$hours} hours)...");
        
        // Find syncs that are in progress or pending but haven't been updated recently
        $cutoffTime = now()->subHours($hours);
        
        $staleInProgress = JiraSyncHistory::where('status', 'in_progress')
            ->where('updated_at', '<', $cutoffTime)
            ->get();
            
        $stalePending = JiraSyncHistory::where('status', 'pending')
            ->where('created_at', '<', $cutoffTime)
            ->get();
            
        $staleSyncs = $staleInProgress->merge($stalePending);
        
        if ($staleSyncs->isEmpty()) {
            $this->info('✅ No stale syncs found.');
            return 0;
        }
        
        $this->warn("Found {$staleSyncs->count()} stale sync(s):");
        
        $table = [];
        foreach ($staleSyncs as $sync) {
            // Check if there's a corresponding job in the queue
            $hasJob = DB::table('jobs')
                ->where('payload', 'LIKE', "%ProcessJiraSync%")
                ->where('payload', 'LIKE', "%{$sync->id}%")
                ->exists();
                
            $table[] = [
                'ID' => $sync->id,
                'Status' => $sync->status,
                'Started' => $sync->started_at?->format('Y-m-d H:i:s') ?? 'Never',
                'Updated' => $sync->updated_at->format('Y-m-d H:i:s'),
                'Progress' => $sync->progress_percentage . '%',
                'Has Job' => $hasJob ? 'Yes' : 'No',
                'Action' => $hasJob ? 'Skip (has job)' : 'Mark as failed',
            ];
        }
        
        $this->table([
            'ID', 'Status', 'Started', 'Updated', 'Progress', 'Has Job', 'Action'
        ], $table);
        
        if ($dryRun) {
            $this->warn('🔍 Dry run mode - no changes will be made.');
            return 0;
        }
        
        $cleanedCount = 0;
        foreach ($staleSyncs as $sync) {
            // Only clean up syncs that don't have corresponding jobs
            $hasJob = DB::table('jobs')
                ->where('payload', 'LIKE', "%ProcessJiraSync%")
                ->where('payload', 'LIKE', "%{$sync->id}%")
                ->exists();
                
            if (!$hasJob) {
                $sync->markAsFailed([
                    'reason' => 'Stale sync cleanup',
                    'cleanup_command' => true,
                    'hours_stale' => $hours,
                    'cleaned_at' => now(),
                ]);
                
                $this->line("  ✅ Marked sync {$sync->id} as failed (stale for {$hours}+ hours)");
                $cleanedCount++;
            } else {
                $this->line("  ⏳ Skipped sync {$sync->id} (has active job)");
            }
        }
        
        if ($cleanedCount > 0) {
            $this->info("🧹 Cleaned up {$cleanedCount} stale sync(s).");
        } else {
            $this->info("ℹ️  No stale syncs needed cleanup (all have active jobs).");
        }
        
        return 0;
    }
}
