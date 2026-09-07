<?php

namespace App\Services;

use App\Events\BookingStatusChanged;
use App\Jobs\FindNearbyDrivers;
use App\Models\Booking;
use App\Models\User;
use App\Models\DriverSearchSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;


class DriverMatchingService
{
    protected $searchRadius = 5000; // meters
    protected $maxDrivers = 5;
    protected $waitTime = 30; // seconds

    public function __construct()
    {
        $setting = DriverSearchSetting::getActive();
        $this->searchRadius = (int) round($setting->getRadiusForRound(1) * 1000);
    }

    public function startMatching(Booking $booking): void
    {
        if ($booking->status !== 'pending' || $booking->driver_id) {

            return;
        }

        $booking->update(['status' => 'searching']);

        FindNearbyDrivers::dispatch($booking, $this->searchRadius, $this->maxDrivers, $this->waitTime);


    }

    
    public function findDriversForBooking(Booking $booking): void
    {
        $this->startMatching($booking);
    }

    public function handleDriverResponse(Booking $booking, User $driver, bool $accepted): void
    {
        if (!$this->isDriverEligible($booking, $driver)) {

            return;
        }

        if ($accepted) {
            $this->handleAcceptance($booking, $driver);
        } else {
            $this->handleRejection($booking, $driver);
        }
    }

    protected function isDriverEligible(Booking $booking, User $driver): bool
    {
        $offerKey = "booking_offer_{$booking->id}_{$driver->id}";


        if ($booking->status !== 'searching' || $booking->driver_id) {

            return false;
        }

        if (!$driver->isDriver()) {
            return false;
        }

        if (!$driver->isActive()) {
            return false;
        }

        if (!$driver->is_online) {
            return false;
        }

        if ($driver->current_booking_id) {
            return false;
        }

        $bookingUser = $booking->user;
        if ($bookingUser) {
            if (
                !empty($bookingUser->phone) && !empty($driver->phone) &&
                $bookingUser->phone === $driver->phone
            ) {
                return false;
            }

            if (
                !empty($bookingUser->email) && !empty($driver->email) &&
                $bookingUser->email === $driver->email
            ) {
                return false;
            }
        }

        $hasVehicle = $driver->vehicles()
            ->where('ride_type_id', $booking->ride_type_id)
            ->where('status', 'active')
            ->exists();

        if (!$hasVehicle) {

            return false;
        }

        if ($driver->currentLocation) {
            $distance = $driver->currentLocation->distanceTo($booking->pickup_latitude, $booking->pickup_longitude);
            if ($distance > $this->searchRadius * 1.5) { // Allow some buffer
                return false;
            }
        } else {

            return false;
        }


        return true;
    }

    protected function handleAcceptance(Booking $booking, User $driver): void
    {
        try {
            $updated = Booking::where('id', $booking->id)
                ->where('status', 'searching')
                ->whereNull('driver_id')
                ->update([
                    'driver_id' => $driver->id,
                    'status' => 'accepted',
                ]);

            if (!$updated) {

                return;
            }

            $driver->update([
                'current_booking_id' => $booking->id,
            ]);

            $this->clearBookingCache($booking);

            $freshBooking = $booking->fresh();
            

            event(new BookingStatusChanged($freshBooking, 'searching', 'Driver assigned to your booking'));
        } catch (\Exception $e) {
            throw $e;
        }
    }

    protected function handleRejection(Booking $booking, User $driver): void
    {
        $offerKey = "booking_offer_{$booking->id}_{$driver->id}";
        Cache::forget($offerKey);



        $notifiedDriversKey = "booking_{$booking->id}_notified_drivers";
        $notifiedDrivers = Cache::get($notifiedDriversKey, []);

        if (count($notifiedDrivers) < $this->maxDrivers) {

        }
    }

    protected function clearBookingCache(Booking $booking): void
    {
        $notifiedDriversKey = "booking_{$booking->id}_notified_drivers";
        Cache::forget($notifiedDriversKey);

        $notifiedDrivers = Cache::get($notifiedDriversKey, []);
        foreach ($notifiedDrivers as $driverId) {
            $offerKey = "booking_offer_{$booking->id}_{$driverId}";
            Cache::forget($offerKey);
        }
    }

    public function cancelMatching(Booking $booking, ?string $reason = null): void
    {
        if ($booking->status === 'searching') {
            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason ?? 'Cancelled by user',
                'cancelled_by_type' => 'user',
                'cancelled_by_id' => Auth::check() ? Auth::id() : $booking->user_id,
            ]);
        }

        $this->clearBookingCache($booking);



    }
}
