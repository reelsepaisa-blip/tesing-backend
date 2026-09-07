<?php

namespace App\Console\Commands;

use App\Services\DriverIncentiveService;
use Illuminate\Console\Command;

class ProcessDriverIncentives extends Command
{
    
    protected $signature = 'incentives:process {driver_id} {--incentive_id= : Process specific incentive ID}';

    
    protected $description = 'Retroactively process completed rides for driver incentives';

    
    public function handle()
    {
        $driverId = $this->argument('driver_id');
        $incentiveId = $this->option('incentive_id');

        $this->info("Processing incentives for driver ID: {$driverId}");

        $service = app(DriverIncentiveService::class);
        $result = $service->retroactivelyProcessRides($driverId, $incentiveId);

        if ($result['processed'] > 0) {
            $this->info("✅ Successfully processed {$result['processed']} qualifying rides");
            $this->info("   Incentives processed: {$result['incentives_processed']}");
        } else {
            $this->warn($result['message']);
        }

        return 0;
    }
}

