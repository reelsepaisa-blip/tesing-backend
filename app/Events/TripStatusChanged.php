<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TripStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $booking;
    public $oldStatus;
    public $newStatus;
    public $metadata;

    public function __construct(Booking $booking, string $oldStatus, string $newStatus, array $metadata = [])
    {
        $this->booking = $booking;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->metadata = $metadata;
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PresenceChannel("trip.{$this->booking->id}"),
        ];

        if ($this->booking->user_id) {
            $channels[] = new Channel("user.{$this->booking->user_id}");
        }
        if ($this->booking->driver_id) {
            $channels[] = new Channel("driver.{$this->booking->driver_id}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'trip.status.changed';
    }

    public function broadcastWith(): array
    {
        $data = [
            'booking_id' => $this->booking->id,
            'booking_code' => $this->booking->booking_code,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'timestamp' => now()->timestamp,
            'metadata' => $this->metadata,
        ];

        switch ($this->newStatus) {
            case 'accepted':
                $data['driver'] = $this->booking->driver ? [
                    'id' => $this->booking->driver->id,
                    'name' => $this->booking->driver->name,
                    'phone' => $this->booking->driver->phone,
                    'rating' => $this->booking->driver->driverProfile->rating ?? 0,
                    'vehicle' => $this->booking->driver->vehicles()
                        ->where('ride_type_id', $this->booking->ride_type_id)
                        ->where('status', 'active')
                        ->first()
                        ->only(['model', 'number', 'color']) ?? null,
                    'photo' => $this->getProfilePhotoUrl($this->booking->driver->profile_photo ?? ''),
                ] : null;
                break;

            case 'arrived':
                $data['otp'] = $this->booking->trip_otp;
                $data['pickup_location'] = [
                    'address' => $this->booking->pickup_address,
                    'coordinates' => [
                        'lat' => $this->booking->pickup_location->latitude,
                        'lng' => $this->booking->pickup_location->longitude,
                    ],
                ];
                break;

            case 'started':
                $data['trip_start_time'] = $this->booking->trip_start_time;
                $data['estimated_duration'] = $this->booking->estimated_duration;
                $data['estimated_distance'] = $this->booking->estimated_distance;
                break;

            case 'completed':
                $data['trip_end_time'] = $this->booking->trip_end_time;
                $data['actual_duration'] = $this->booking->actual_duration;
                $data['actual_distance'] = $this->booking->actual_distance;
                $data['fare_breakdown'] = [
                    'base_fare' => $this->booking->base_fare,
                    'distance_fare' => $this->booking->distance_fare,
                    'time_fare' => $this->booking->time_fare,
                    'waiting_charge' => $this->booking->waiting_charge,
                    'night_charge' => $this->booking->night_charge,
                    'surge_amount' => $this->booking->surge_amount,
                    'subtotal' => $this->booking->subtotal,
                    'tax_amount' => $this->booking->tax_amount,
                    'total_amount' => $this->booking->total_amount,
                ];
                break;
        }

        return $data;
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
