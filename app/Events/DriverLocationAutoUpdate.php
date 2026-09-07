<?php

namespace App\Events;

use App\Models\User;
use App\Models\DriverLocation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverLocationAutoUpdate implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $driver;
    public $location;

    
    public function __construct(User $driver, DriverLocation $location)
    {
        $this->driver = $driver;
        $this->location = $location;
    }

    
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('driver.' . $this->driver->id),
            new Channel('driver.location.updates'),
            new Channel('driver.location.' . $this->driver->id),
        ];
    }

    
    public function broadcastAs(): string
    {
        return 'driver.location.auto.updated';
    }

    
    public function broadcastWith(): array
    {
        return [
            'driver_id' => $this->driver->id,
            'location' => [
                'latitude' => $this->location->latitude,
                'longitude' => $this->location->longitude,
                'heading' => $this->location->heading,
                'speed' => $this->location->speed,
                'accuracy' => $this->location->accuracy,
                'address' => $this->location->address,
            ],
            'is_online' => $this->driver->is_online,
            'recorded_at' => $this->location->recorded_at,
            'timestamp' => now()->timestamp,
        ];
    }
}
