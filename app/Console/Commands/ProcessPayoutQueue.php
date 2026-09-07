<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;


class ProcessPayoutQueue extends Command
{
    protected $signature = 'payouts:process-queue {--queue=payouts,default : Comma separated list of queues to process}';

    protected $description = 'Run queue:work --once for payout queues and log the execution result';

    public function handle(): int
    {
        $queueList = $this->option('queue');


        // Check if pcntl extension is available
        // If not, we must use --stop-when-empty to avoid daemon mode
        $hasPcntl = function_exists('pcntl_signal');
        
        if (!$hasPcntl) {

        }

        // Use --stop-when-empty and --max-jobs to prevent daemon mode
        // This works better on shared hosting/Windows environments where pcntl is not available
        $exitCode = Artisan::call('queue:work', [
            '--queue' => $queueList,
            '--stop-when-empty' => true,
            '--tries' => 3,
            '--timeout' => 60,
            '--max-jobs' => 10, // Process up to 10 jobs per run
        ]);

        $output = trim(Artisan::output());

        

        $this->line($output);

        return $exitCode;
    }
}
