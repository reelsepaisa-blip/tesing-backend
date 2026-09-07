<?php

namespace App\Console\Commands;

use Exception;
use App\Services\DriverAutoLocationService;
use Illuminate\Console\Command;


class ProcessDriverAutoLocations extends Command
{
    
    protected $signature = 'drivers:process-auto-locations 
                           {--interval=10 : Update interval in seconds}
                           {--continuous : Run continuously}';

    
    protected $description = 'Process automatic location updates for online drivers every 10 seconds';

    protected $driverAutoLocationService;

    
    public function __construct(DriverAutoLocationService $driverAutoLocationService)
    {
        parent::__construct();
        $this->driverAutoLocationService = $driverAutoLocationService;
    }

    
    public function handle()
    {
        $interval = (int) $this->option('interval');
        $continuous = $this->option('continuous');

        $this->info("Starting driver auto location processing...");
        $this->info("Update interval: {$interval} seconds");

        if ($continuous) {
            $this->info("Running continuously. Press Ctrl+C to stop.");

            while (true) {
                $this->processLocations();
                sleep($interval);
            }
        } else {
            $this->processLocations();
        }

        $this->info("Driver auto location processing completed.");
    }

    
    private function processLocations()
    {
        try {
            $startTime = microtime(true);

            $updatedCount = $this->driverAutoLocationService->processAutoLocationUpdates();

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->line(sprintf(
                "[%s] Updated %d drivers in %sms",
                now()->format('H:i:s'),
                $updatedCount,
                $executionTime
            ));
        } catch (Exception $e) {
            $this->error("Error processing auto locations: " . $e->getMessage());
        }
    }
}
