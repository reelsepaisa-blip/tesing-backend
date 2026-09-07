<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;


class DriverLocationByBookingUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $bookingId;
    public $latitude;
    public $longitude;
    public $driverId;

    /**
     * Create a new event instance.
     */
    public function __construct(int $bookingId, float $latitude, float $longitude, int $driverId)
    {
        $this->bookingId = $bookingId;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->driverId = $driverId;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('private-driver-location.booking.' . $this->bookingId),
            new Channel('user.all'), // Broadcast to user.all channel as well
        ];

        

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'driver.location.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'driver_id' => $this->driverId,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'timestamp' => now()->timestamp,
            'updated_at' => now()->toISOString(),
        ];
    }
}
