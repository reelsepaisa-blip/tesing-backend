<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverLocationUpdatedForBooking implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $bookingId;
    public $driverId;
    public $latitude;
    public $longitude;
    public $polyline;

    public function __construct(int $bookingId, int $driverId, float $latitude, float $longitude, ?string $polyline = null)
    {
        $this->bookingId = $bookingId;
        $this->driverId = $driverId;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->polyline = $polyline;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('user.all'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'driver.location.updated';
    }

    public function broadcastWith(): array
    {
        $data = [
            'booking_id' => $this->bookingId,
            'driver_id' => $this->driverId,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'timestamp' => now()->timestamp,
            'updated_at' => now()->toIso8601String(),
        ];

        // Include polyline if available for route visualization
        if ($this->polyline) {
            $data['polyline'] = $this->polyline;
        }

        return $data;
    }
}
