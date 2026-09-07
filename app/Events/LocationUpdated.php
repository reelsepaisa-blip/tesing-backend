<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use MatanYadaev\EloquentSpatial\Objects\Point;

class LocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $booking;
    public $location;
    public $heading;
    public $eta;

    public function __construct(Booking $booking, Point $location, float $heading, ?int $eta = null)
    {
        $this->booking = $booking;
        $this->location = [
            'lat' => $location->latitude,
            'lng' => $location->longitude,
        ];
        $this->heading = $heading;
        $this->eta = $eta;
    }

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("booking.{$this->booking->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'location.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->booking->id,
            'location' => $this->location,
            'heading' => $this->heading,
            'eta' => $this->eta,
            'status' => $this->booking->status,
            'timestamp' => now()->timestamp,
        ];
    }
}








