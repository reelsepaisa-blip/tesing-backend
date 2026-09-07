<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\DriverProfile;
use Illuminate\Console\Command;

class FixDriverVerification extends Command
{
    
    protected $signature = 'driver:fix-verification {driver_id : The ID of the driver to fix}';

    
    protected $description = 'Fix driver verification status by updating vehicle registration approval';

    
    public function handle()
    {
        $driverId = $this->argument('driver_id');
        
        $driver = User::with(['driverProfile'])->find($driverId);

        if (!$driver) {
            $this->error("Driver with ID {$driverId} not found!");
            return 1;
        }

        if (!$driver->isDriver()) {
            $this->error("User {$driverId} is not a driver!");
            return 1;
        }

        $this->info("Fixing verification for driver: {$driver->name} (ID: {$driverId})");

        if (!$driver->driverProfile) {
            $this->warn("DriverProfile not found. Creating one...");
            $driverProfile = DriverProfile::create([
                'driver_id' => $driver->id,
                'meta_data' => []
            ]);
            $driver->refresh();
        }

        $profile = $driver->driverProfile;
        $meta = $profile->meta_data ?? [];
        
        if (!isset($meta['vehicle_registration_status']) || $meta['vehicle_registration_status'] !== 'approved') {
            $meta['vehicle_registration_status'] = 'approved';
            $meta['vehicle_registration_approved'] = true;
            
            $profile->update(['meta_data' => $meta]);
            $this->info("✓ Updated vehicle_registration_status to 'approved'");
        } else {
            $this->info("✓ Vehicle registration already approved");
        }

        $driver->refresh();
        $result = $driver->updateVerificationStatus();
        
        $driver->refresh();
        
        $this->info("=== RESULT ===");
        $this->line("is_verified: {$driver->is_verified}");
        $this->line("verified_at: " . ($driver->verified_at ?? 'null'));
        
        if ($result) {
            $this->info("✓ Driver verification status updated successfully!");
        } else {
            $this->warn("⚠️ Verification status update returned false. Check documents.");
        }

        return 0;
    }
}

