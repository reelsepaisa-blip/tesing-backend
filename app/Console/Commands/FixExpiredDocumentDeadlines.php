<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\DriverDocumentNotification;
use Illuminate\Console\Command;

class FixExpiredDocumentDeadlines extends Command
{
    
    protected $signature = 'driver:fix-expired-deadlines {driver_id : The ID of the driver} {--mark-uploaded : Mark expired notifications as uploaded} {--delete : Delete expired notifications}';

    
    protected $description = 'Fix expired document deadlines for a driver';

    
    public function handle()
    {
        $driverId = $this->argument('driver_id');
        
        $driver = User::find($driverId);

        if (!$driver) {
            $this->error("Driver with ID {$driverId} not found!");
            return 1;
        }

        if (!$driver->isDriver()) {
            $this->error("User {$driverId} is not a driver!");
            return 1;
        }

        $this->info("Checking expired document deadlines for driver: {$driver->name} (ID: {$driverId})");
        $this->newLine();

        $expiredNotifications = DriverDocumentNotification::where('driver_id', $driver->id)
            ->where('deadline_at', '<=', now())
            ->where('is_uploaded', false)
            ->whereHas('documentList', function ($query) {
                $query->where('is_new', true)
                    ->where('is_active', true);
            })
            ->with('documentList')
            ->get();

        if ($expiredNotifications->isEmpty()) {
            $this->info("✓ No expired document deadlines found!");
            return 0;
        }

        $this->warn("Found {$expiredNotifications->count()} expired document deadline(s):");
        $this->newLine();

        foreach ($expiredNotifications as $notification) {
            $this->line("Document: {$notification->documentList->name}");
            $this->line("  - Deadline: {$notification->deadline_at}");
            $this->line("  - Is Uploaded: " . ($notification->is_uploaded ? 'Yes' : 'No'));
            $this->line("  - Document List ID: {$notification->document_list_id}");
            $this->line("  - Notification ID: {$notification->id}");
            $this->newLine();
        }

        if ($this->option('mark-uploaded')) {
            $this->info("Marking expired notifications as uploaded...");
            foreach ($expiredNotifications as $notification) {
                $notification->update(['is_uploaded' => true]);
                $this->line("✓ Marked notification #{$notification->id} as uploaded");
            }
            $this->info("✓ All expired notifications marked as uploaded!");
            
            $driver->refresh();
            $driver->updateVerificationStatus();
            $this->info("✓ Verification status updated!");
            
            return 0;
        }

        if ($this->option('delete')) {
            if ($this->confirm('Are you sure you want to delete these expired notifications?')) {
                $count = $expiredNotifications->count();
                foreach ($expiredNotifications as $notification) {
                    $notification->delete();
                }
                $this->info("✓ Deleted {$count} expired notification(s)!");
                
                $driver->refresh();
                $driver->updateVerificationStatus();
                $this->info("✓ Verification status updated!");
                
                return 0;
            }
        }

        $this->info("To fix this issue, run one of the following:");
        $this->line("1. Mark as uploaded: php artisan driver:fix-expired-deadlines {$driverId} --mark-uploaded");
        $this->line("2. Delete notifications: php artisan driver:fix-expired-deadlines {$driverId} --delete");
        $this->newLine();
        $this->warn("Note: Make sure the document 't' is either:");
        $this->line("  - A valid document that should be required (then driver needs to upload it)");
        $this->line("  - A test/incorrect entry (then mark as uploaded or delete the notification)");

        return 0;
    }
}























