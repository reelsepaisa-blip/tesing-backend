<?php

namespace App\Services;

use App\Events\BookingStatusChanged;
use App\Events\DriverLocationUpdated;
use App\Events\FilteredNewBooking;
use App\Events\NewBooking;
use App\Events\TripStatusChanged;
use App\Models\Booking;
use App\Models\User;

use MatanYadaev\EloquentSpatial\Objects\Point;

class RealTimeService
{
    
    public function broadcastBookingStatusChange(Booking $booking, string $oldStatus, array $metadata = []): void
    {
        try {
            event(new BookingStatusChanged($booking, $booking->status, "Status changed from {$oldStatus}"));

            event(new TripStatusChanged($booking, $oldStatus, $booking->status, $metadata));

            
        } catch (\Exception $e) {
        }
    }

    
    public function broadcastDriverLocationUpdate(
        Booking $booking,
        User $driver,
        Point $location,
        float $heading,
        ?float $speed = null,
        ?int $eta = null
    ): void {
        try {
            event(new DriverLocationUpdated($booking, $driver, $location, $heading, $speed, $eta));

            
        } catch (\Exception $e) {
        }
    }

    
    public function broadcastTripStatusChange(Booking $booking, string $oldStatus, string $newStatus, array $metadata = []): void
    {
        try {
            event(new TripStatusChanged($booking, $oldStatus, $newStatus, $metadata));

            
        } catch (\Exception $e) {
        }
    }

    
    public function getBookingChannels(Booking $booking): array
    {
        $channels = [
            "trip.{$booking->id}",
        ];

        if ($booking->user_id) {
            $channels[] = "user.{$booking->user_id}";
        }

        if ($booking->driver_id) {
            $channels[] = "driver.{$booking->driver_id}";
        }

        return $channels;
    }

    
    public function canAccessChannel(User $user, string $channelName): bool
    {
        if (preg_match('/^trip\.(\d+)$/', $channelName, $matches)) {
            $bookingId = (int) $matches[1];
            $booking = Booking::find($bookingId);

            if (!$booking) {
                return false;
            }

            return $user->id === $booking->user_id || $user->id === $booking->driver_id;
        }

        if (preg_match('/^user\.(\d+)$/', $channelName, $matches)) {
            $userId = (int) $matches[1];
            return $user->id === $userId;
        }

        if (preg_match('/^driver\.(\d+)$/', $channelName, $matches)) {
            $driverId = (int) $matches[1];
            return $user->id === $driverId && $user->hasRole('driver');
        }

        return false;
    }

    
    public function getUserActiveChannels(User $user): array
    {
        $channels = [
            "user.{$user->id}",
        ];

        if ($user->hasRole('driver')) {
            $channels[] = "driver.{$user->id}";
        }

        $activeBookings = Booking::where(function ($query) use ($user) {
            $query->where('user_id', $user->id)
                ->orWhere('driver_id', $user->id);
        })
            ->whereIn('status', ['pending', 'accepted', 'arrived', 'started'])
            ->get();

        foreach ($activeBookings as $booking) {
            $channels[] = "trip.{$booking->id}";
        }

        return $channels;
    }

    
    public function sendToChannel(string $channelName, string $event, array $data): void
    {
        try {
            broadcast(new \App\Events\CustomMessage($channelName, $event, $data));

            
        } catch (\Exception $e) {
        }
    }

    
    public function sendToChannels(array $channelNames, string $event, array $data): void
    {
        foreach ($channelNames as $channelName) {
            $this->sendToChannel($channelName, $event, $data);
        }
    }

    
    public function broadcastNewBooking(Booking $booking, User $user): void
    {
        try {
            event(new NewBooking($booking, $user));

            
        } catch (\Exception $e) {
        }
    }

    
    public function sendToRole(string $role, string $event, array $data): void
    {
        try {
            $users = User::role($role)->get();

            foreach ($users as $user) {
                $channelName = $role === 'driver' ? "driver.{$user->id}" : "user.{$user->id}";
                $this->sendToChannel($channelName, $event, $data);
            }

        } catch (\Exception $e) {
        }
    }

    
    public function getFilteredDriversForBooking($booking): array
    {
        try {
            $query = User::drivers()
                ->active()
                ->online()
                ->whereHas('driverProfile', function ($q) use ($booking) {
                    $q->where('city_id', $booking->city_id);
                })
                ->whereHas('vehicles', function ($q) {
                    $q->where('status', 'active');
                });

            if ($booking->ride_type_id) {
                $query->whereHas('activeRideTypes', function ($q) use ($booking) {
                    $q->where('ride_type_id', $booking->ride_type_id);
                });
            }

            $drivers = $query->with([
                'driverProfile',
                'vehicles',
                'activeRideTypes',
                'currentLocation'
            ])->get();

            $availableDrivers = $drivers->filter(function ($driver) {
                return $driver->canGoOnline() &&
                    $driver->driverProfile &&
                    $driver->vehicles->isNotEmpty();
            });


            return $availableDrivers->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    
    public function broadcastNewBookingToFilteredDrivers($booking, $user): void
    {
        try {
            $filteredDrivers = $this->getFilteredDriversForBooking($booking);

            if (empty($filteredDrivers)) {
                
                return;
            }

            $this->broadcastFilteredNewBooking($booking, $user, $filteredDrivers);

        } catch (\Exception $e) {
        }
    }

    
    private function broadcastFilteredNewBooking($booking, $user, array $filteredDrivers): void
    {
        try {
            $driverIds = collect($filteredDrivers)->pluck('id')->toArray();

            event(new FilteredNewBooking($booking, $user, $driverIds));

        } catch (\Exception $e) {
        }
    }
}
