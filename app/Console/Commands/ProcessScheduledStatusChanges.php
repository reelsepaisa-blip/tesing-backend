<?php

namespace App\Console\Commands;

use Exception;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Enums\BookingState;
use Illuminate\Console\Command;

use Illuminate\Support\Facades\DB;

class ProcessScheduledStatusChanges extends Command
{
    
    protected $signature = 'bookings:expire-search-timeout {--timeout=1.5 : Timeout in minutes}';

    
    protected $description = 'Expire bookings that have been searching for too long without driver acceptance';

    
    public function handle()
    {
        $timeoutMinutes = (int) $this->option('timeout');
        $this->info("Processing bookings that have been searching for more than {$timeoutMinutes} minutes...");

        try {
            $expiredCount = $this->expireSearchingBookings($timeoutMinutes);

            if ($expiredCount > 0) {
                $this->info("Successfully expired {$expiredCount} bookings.");

            } else {
                $this->info("No bookings found to expire.");
            }

            return 0;
        } catch (Exception $e) {
            $this->error("Error processing booking expiration: " . $e->getMessage());
            return 1;
        }
    }

    
    private function expireSearchingBookings(int $timeoutMinutes): int
    {
        $timeoutDateTime = now()->subMinutes($timeoutMinutes);

        $searchingBookings = Booking::where('status', 'searching')
            ->whereNull('driver_id')
            ->where('updated_at', '<', $timeoutDateTime)
            ->get();

        if ($searchingBookings->isEmpty()) {
            return 0;
        }

        $expiredCount = 0;

        foreach ($searchingBookings as $booking) {
            try {
                DB::transaction(function () use ($booking, &$expiredCount) {
                    $currentState = BookingState::from($booking->status);

                    if ($currentState->canTransitionTo(BookingState::EXPIRED)) {
                        $booking->update([
                            'status' => BookingState::EXPIRED->value,
                            'cancelled_at' => now(),
                            'cancellation_reason' => 'No driver accepted within timeout period',
                            'cancelled_by_type' => 'system',
                            'cancelled_by_id' => null
                        ]);

                        $expiredCount++;


                        broadcast(new BookingStatusChanged(
                            $booking,
                            BookingState::EXPIRED->value,
                            "Booking expired - no driver accepted within timeout period"
                        ))->toOthers();
                    } else {

                    }
                });
            } catch (Exception $e) {
                $this->error("Failed to expire booking {$booking->id}: " . $e->getMessage());
            }
        }

        return $expiredCount;
    }
}
