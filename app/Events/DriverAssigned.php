<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverAssigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $booking;
    public $driver;

    
    public function __construct(Booking $booking, User $driver)
    {
        $this->booking = $booking;
        $this->driver = $driver;
    }

    
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->booking->user_id),
            new PrivateChannel('driver.' . $this->driver->id),
            new PresenceChannel('trip.' . $this->booking->id),
            new Channel('public-booking.' . $this->booking->id),
            new Channel('public-trip.' . $this->booking->id),
            new Channel('user.all'),
            new Channel('driver.all'),
        ];
    }

    
    public function broadcastAs(): string
    {
        return 'driver-assigned';
    }

    
    public function broadcastWith(): array
    {
        $booking = $this->booking->load(['user', 'rideType', 'pickupZone', 'dropoffZone']);
        $driver = $this->driver->load(['driverProfile', 'vehicles']);
        
        // Calculate total_trips dynamically from bookings count
        $totalTrips = $driver->bookingsAsDriver()->count();

        return [
            'booking' => [
                'id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'status' => $booking->status,
                'pickup_address' => $booking->pickup_address,
                'dropoff_address' => $booking->dropoff_address,
                'pickup_latitude' => $booking->pickup_latitude,
                'pickup_longitude' => $booking->pickup_longitude,
                'dropoff_latitude' => $booking->dropoff_latitude,
                'dropoff_longitude' => $booking->dropoff_longitude,
                'distance' => $booking->distance,
                'duration' => $booking->duration,
                'estimated_fare' => $booking->estimated_fare,
                'final_fare' => $booking->final_fare,
                'payment_method' => $booking->payment_method,
                'payment_status' => $booking->payment_status,
                'otp' => $booking->otp,
                'created_at' => $booking->created_at,
                'updated_at' => $booking->updated_at,
                'assigned_at' => $booking->accepted_at,
            ],
            'driver' => [
                'id' => $driver->id,
                'name' => $driver->name,
                'phone' => $driver->phone,
                'email' => $driver->email,
                'profile_photo' => $this->getProfilePhotoUrl($driver->profile_photo),
                'rating' => $driver->driverProfile->rating ?? '0',
                'total_trips' => (string) $totalTrips,
                'vehicle' => [
                    'model' => $driver->vehicles->first()?->model ?? '',
                    'make' => $driver->vehicles->first()?->make ?? '',
                    'year' => $driver->vehicles->first()?->year ?? '',
                    'color' => $driver->vehicles->first()?->color ?? '',
                    'plate_number' => $driver->vehicles->first()?->plate_number ?? '',
                ],
                'is_online' => $driver->is_online,
                'last_latitude' => $driver->last_latitude,
                'last_longitude' => $driver->last_longitude,
            ],
            'user' => [
                'id' => $booking->user->id,
                'name' => $booking->user->name,
                'phone' => $booking->user->phone,
                'email' => $booking->user->email,
                'profile_photo' => $this->getProfilePhotoUrl($booking->user->profile_photo),
                'bearer_token' => $booking->user->bearer_token, // For Flutter matching
                'is_verified' => $booking->user->is_verified,
                'status' => $booking->user->status,
            ],
            'event_type' => 'driver_assigned',
            'driver_auth_token' => (string) $driver->id,
            'timestamp' => now()->toISOString(),
        ];
    }

    
    private function getProfilePhotoUrl(?string $profilePhoto): string
    {
        if (empty($profilePhoto)) {
            return '';
        }

        if (filter_var($profilePhoto, FILTER_VALIDATE_URL)) {
            return $profilePhoto;
        }

        return url('storage/' . $profilePhoto);
    }
}
