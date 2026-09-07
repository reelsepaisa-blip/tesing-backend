<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverCancelledBooking implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('user.all'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'driver_cancle_booking';
    }

    public function broadcastWith(): array
    {
        $booking = $this->booking->load(['user', 'rideType', 'driver.driverProfile', 'driver.vehicles']);

        return [
            'booking' => [
                'id' => (string) $booking->id,
                'booking_code' => $booking->booking_code ?? '',
                'status' => $booking->status ?? '',
                'old_status' => 'accepted',
                'pickup_address' => $booking->pickup_address ?? '',
                'dropoff_address' => $booking->dropoff_address ?? '',
                'pickup_latitude' => (string) ($booking->pickup_latitude ?? ''),
                'pickup_longitude' => (string) ($booking->pickup_longitude ?? ''),
                'dropoff_latitude' => (string) ($booking->dropoff_latitude ?? ''),
                'dropoff_longitude' => (string) ($booking->dropoff_longitude ?? ''),
                'distance' => (string) ($booking->distance ?? ''),
                'duration' => (string) ($booking->duration ?? ''),
                'estimated_fare' => (string) ($booking->estimated_fare ?? ''),
                'final_fare' => (string) ($booking->final_fare ?? ''),
                'payment_method' => $booking->payment_method ?? '',
                'payment_status' => $booking->payment_status ?? '',
                'otp' => $booking->otp ?? '',
                'created_at' => $booking->created_at ? $booking->created_at->toISOString() : '',
                'updated_at' => $booking->updated_at ? $booking->updated_at->toISOString() : '',
                'started_at' => $booking->started_at ? $booking->started_at->toISOString() : '',
                'completed_at' => $booking->completed_at ? $booking->completed_at->toISOString() : '',
                'cancelled_at' => $booking->cancelled_at ? $booking->cancelled_at->toISOString() : '',
                'cancellation_reason' => $booking->cancellation_reason ?? '',
                'free_waiting_time' => (string) ($booking->rideType->waiting_time_limit ?? '0'),
            ],

            'driver' => $booking->driver ? [
                'id' => (string) $booking->driver->id,
                'name' => $booking->driver->name ?? '',
                'phone' => $booking->driver->phone ?? '',
                'email' => $booking->driver->email ?? '',
                'profile_photo' => $this->getProfilePhotoUrl($booking->driver->profile_photo ?? ''),
                'rating' => (string) ($booking->driver->driverProfile->rating ?? '0'),
                'total_trips' => (string) $booking->driver->bookingsAsDriver()->count(),
                'vehicle' => [
                    'model' => $booking->driver->vehicles->first()?->model ?? '',
                    'make' => $booking->driver->vehicles->first()?->make ?? '',
                    'year' => (string) ($booking->driver->vehicles->first()?->year ?? ''),
                    'color' => $booking->driver->vehicles->first()?->color ?? '',
                    'number_plate' => $booking->driver->vehicles->first()?->registration_number ?? '',
                ],
                'is_online' => (string) ($booking->driver->is_online ?? '0'),
                'last_latitude' => (string) ($booking->driver->last_latitude ?? ''),
                'last_longitude' => (string) ($booking->driver->last_longitude ?? ''),
            ] : [
                'id' => '',
                'name' => '',
                'phone' => '',
                'email' => '',
                'profile_photo' => '',
                'rating' => '0',
                'total_trips' => '0',
                'vehicle' => [
                    'model' => '',
                    'make' => '',
                    'year' => '',
                    'color' => '',
                    'number_plate' => '',
                ],
                'is_online' => '0',
                'last_latitude' => '',
                'last_longitude' => '',
            ],

            'user' => $booking->user ? [
                'id' => (string) $booking->user->id,
                'name' => $booking->user->name ?? '',
                'phone' => $booking->user->phone ?? '',
                'email' => $booking->user->email ?? '',
                'profile_photo' => $this->getProfilePhotoUrl($booking->user->profile_photo ?? ''),
                'bearer_token' => $booking->user->bearer_token ?? '',
                'is_verified' => (string) ($booking->user->is_verified ?? '0'),
                'status' => (string) ($booking->user->status ?? '1'),
            ] : [
                'id' => '',
                'name' => '',
                'phone' => '',
                'email' => '',
                'profile_photo' => '',
                'bearer_token' => '',
                'is_verified' => '0',
                'status' => '1',
            ],

            'ride_type' => $booking->rideType ? [
                'id' => (string) $booking->rideType->id,
                'name' => $booking->rideType->name ?? '',
                'code' => $booking->rideType->code ?? '',
                'free_waiting_time' => (string) ($booking->rideType->waiting_time_limit ?? '0'),
                'waiting_charge_per_minute' => (string) ($booking->rideType->waiting_charge_per_minute ?? '0'),
                'base_price' => (string) ($booking->rideType->base_price ?? '0'),
                'price_per_km' => (string) ($booking->rideType->price_per_km ?? '0'),
                'minimum_fare' => (string) ($booking->rideType->minimum_fare ?? '0'),
            ] : [
                'id' => '',
                'name' => '',
                'code' => '',
                'free_waiting_time' => '0',
                'waiting_charge_per_minute' => '0',
                'base_price' => '0',
                'price_per_km' => '0',
                'minimum_fare' => '0',
            ],

            'event_type' => 'booking_status_changed',
            'driver_auth_token' => $booking->driver ? (string) $booking->driver->id : '',
            'status_change' => [
                'from' => 'searching',
                'to' => $booking->status,
            ],
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
