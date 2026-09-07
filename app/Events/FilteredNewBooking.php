<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FilteredNewBooking implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $booking;
    public $user;
    public $targetDriverIds;

    public function __construct(Booking $booking, User $user, array $targetDriverIds = [])
    {
        $this->booking = $booking;
        $this->user = $user;
        $this->targetDriverIds = $targetDriverIds;
    }

    public function broadcastOn(): array
    {
        $channels = [];

        foreach ($this->targetDriverIds as $driverId) {
            $channels[] = new Channel("driver.{$driverId}");
        }

        $channels[] = new Channel("drivers.city.{$this->booking->city_id}");

        if ($this->booking->ride_type_id) {
            $channels[] = new Channel("drivers.ride_type.{$this->booking->ride_type_id}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'new.ride.request';
    }

    public function broadcastWith(): array
    {
        $booking = $this->booking->loadMissing('bookingContact');

        return [
            'booking_id' => $this->booking->id,
            'booking_code' => $this->booking->booking_code,
            'passenger' => [
                'name' => $this->getPassengerName($booking),
                'phone' => $this->getPassengerPhone($booking),
                'rating' => $this->user->rating ?? 0,
                'photo' => $this->getPassengerPhoto($booking),
            ],
            'pickup' => [
                'address' => $this->booking->pickup_address,
                'latitude' => $this->booking->pickup_latitude,
                'longitude' => $this->booking->pickup_longitude,
            ],
            'dropoff' => [
                'address' => $this->booking->dropoff_address,
                'latitude' => $this->booking->dropoff_latitude,
                'longitude' => $this->booking->dropoff_longitude,
            ],
            'trip_details' => [
                'distance' => $this->booking->distance,
                'duration' => $this->booking->duration,
                'fare' => $this->booking->estimated_fare,
            ],
            'acceptance_timer' => 30, // 30 seconds to accept
            'created_at' => now()->toISOString(),
            'city_id' => $this->booking->city_id,
            'ride_type_id' => $this->booking->ride_type_id,
            'filtered_for_drivers' => $this->targetDriverIds,
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

    
    private function getPassengerName($booking): string
    {
        if ($booking->booking_contact_id !== null && $booking->bookingContact) {
            return $booking->bookingContact->name ?? '';
        }

        return $this->user->name ?? '';
    }

    
    private function getPassengerPhone($booking): string
    {
        if ($booking->booking_contact_id !== null && $booking->bookingContact) {
            return $booking->bookingContact->mobile_number ?? '';
        }

        return $this->user->phone ?? '';
    }

    
    private function getPassengerPhoto($booking): string
    {
        if ($booking->booking_contact_id !== null && $booking->bookingContact) {
            $profilePic = $booking->bookingContact->profile_pic ?? null;
            if ($profilePic) {
                return $this->getProfilePhotoUrl($profilePic);
            }
        }

        return $this->getProfilePhotoUrl($this->user->profile_photo ?? '');
    }
}
