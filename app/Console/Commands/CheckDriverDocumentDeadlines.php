<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DriverDocumentNotification;
use App\Models\User;
use App\Models\SystemConfiguration;


class CheckDriverDocumentDeadlines extends Command
{

    protected $signature = 'driver:check-document-deadlines';


    protected $description = 'Check driver document upload deadlines and block drivers who have not uploaded required documents';


    public function handle()
    {
        $this->info('Checking driver document deadlines...');

        $expiredNotifications = DriverDocumentNotification::where('deadline_at', '<=', now())
            ->where('is_uploaded', false)
            ->whereHas('documentList', function ($query) {
                $query->where('is_new', true);
            })
            ->with(['driver.vehicles', 'documentList'])
            ->get();

        $blockedCount = 0;

        foreach ($expiredNotifications as $notification) {
            $driver = $notification->driver;

            if (!$driver || !$driver->isDriver()) {
                continue;
            }

            $hasDocument = $this->checkDriverHasDocument($driver, $notification->documentList);

            if (!$hasDocument) {
                $driver->update(['is_verified' => 0]);

                $driver->updateVerificationStatus();

                $blockedCount++;

                $this->warn("Blocked driver {$driver->id} ({$driver->name}) - Missing document: {$notification->documentList->name}");

                
            } else {
                $notification->markAsUploaded();
            }
        }

        $this->info("Processed {$expiredNotifications->count()} expired notifications. Blocked {$blockedCount} drivers.");

        return Command::SUCCESS;
    }


    protected function checkDriverHasDocument(User $driver, $documentList): bool
    {
        $fieldName = $this->getDocumentFieldName($documentList->name);

        if ($documentList->type === 'driver') {
            $hasFront = $driver->documents()->where('type', $fieldName . '_front')->exists();
            $hasBack = $driver->documents()->where('type', $fieldName . '_back')->exists();
            $hasSingle = $driver->documents()->where('type', $fieldName)->exists();

            return $hasFront || $hasBack || $hasSingle;
        } else {
            foreach ($driver->vehicles as $vehicle) {
                if ($vehicle->documents()->where('type', $fieldName)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }


    protected function getDocumentFieldName(string $documentName): string
    {
        $fieldName = strtolower(str_replace(' ', '_', $documentName));
        $fieldName = str_replace(['_certificate', '_cert'], '', $fieldName);
        return $fieldName;
    }
}
