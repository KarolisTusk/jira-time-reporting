<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class StartQueueWorker extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'queue:start-worker 
                            {--queue=jira-sync : The queue to process}
                            {--timeout=60 : The timeout for each job}
                            {--memory=512 : The memory limit in MB}
                            {--daemon : Run as daemon}';

    /**
     * The console command description.
     */
    protected $description = 'Start a queue worker for JIRA sync operations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queue = $this->option('queue');
        $timeout = $this->option('timeout');
        $memory = $this->option('memory');
        $daemon = $this->option('daemon');

        $this->info("Starting queue worker for queue: {$queue}");
        
        if ($daemon) {
            $this->info('Running as daemon...');
            $command = "php artisan queue:work --queue={$queue} --timeout={$timeout} --memory={$memory} --daemon";
        } else {
            $this->info('Running in foreground...');
            $command = "php artisan queue:work --queue={$queue} --timeout={$timeout} --memory={$memory}";
        }

        $this->line("Command: {$command}");
        $this->newLine();

        // Execute the queue worker
        $result = Process::run($command);
        
        if ($result->successful()) {
            $this->info('Queue worker completed successfully');
            return self::SUCCESS;
        } else {
            $this->error('Queue worker failed');
            $this->error($result->errorOutput());
            return self::FAILURE;
        }
    }
} 