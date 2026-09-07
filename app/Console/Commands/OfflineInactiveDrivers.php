<?php

namespace App\Console\Commands;

use App\Models\DriverAttendance;
use App\Models\DriverLocation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;


class OfflineInactiveDrivers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'drivers:offline-inactive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically mark drivers as offline after 30 minutes of inactivity';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // DISABLED: Drivers should only go offline when attendance/attendance-status API is called
        // This command no longer automatically sets drivers offline
        $this->info('Automatic offline status update is disabled. Drivers will only go offline via attendance-status API.');

        return 0;
    }
}
