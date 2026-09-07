<?php

namespace App\Listeners;

use App\Events\TripCompleted;
use App\Services\DriverIncentiveService;


class ProcessDriverIncentiveOnTripCompleted
{

    public function handle(TripCompleted $event): void
    {
        $booking = $event->booking;

        if ($booking->status !== 'completed' || !$booking->driver_id) {
            return;
        }

        try {
            $incentiveService = app(DriverIncentiveService::class);
            $incentiveService->processRideCompletion($booking->driver_id, $booking->id);
        } catch (\Exception $e) {
            // Error handling
        }
    }
}
