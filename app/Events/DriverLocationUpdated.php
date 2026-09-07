<?php

namespace App\Events;

use Exception;
use App\Models\User;
use App\Models\DriverLocation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverLocationUpdated implements ShouldBroadcastNow
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
            new Channel('admin.driver.locations'),

            new PrivateChannel('driver.' . $this->driver->id . '.location'),

            new Channel('drivers.city.' . ($this->driver->driverProfile->city_id ?? 1)),

            new Channel('drivers.ride_type.' . ($this->driver->vehicles->first()->ride_type_id ?? 1)),
        ];
    }


    public function broadcastWith(): array
    {

        $driverName = '';
        try {
            if (!array_key_exists('name', $this->driver->getAttributes())) {
                $this->driver->refresh();
            }
            $driverName = $this->driver->getAttribute('name') ?? '';
        } catch (Exception $e) {
            $driverName = $this->driver->attributes['name'] ?? '';
        }

        return [
            'driver_id' => $this->driver->id,
            'driver_name' => $driverName,
            'latitude' => $this->location->latitude,
            'longitude' => $this->location->longitude,
            'accuracy' => $this->location->accuracy,
            'speed' => $this->location->speed,
            'heading' => $this->location->heading,
            'battery_level' => $this->location->battery_level,
            'is_charging' => $this->location->is_charging,
            'recorded_at' => $this->location->recorded_at,
            'updated_at' => $this->location->updated_at,
            'city_id' => $this->driver->driverProfile->city_id ?? null,
            'ride_type_id' => $this->driver->vehicles->first()->ride_type_id ?? null,
            'is_online' => $this->driver->is_online,
            'status' => $this->driver->status
        ];
    }


    public function broadcastAs(): string
    {
        return 'driver.location.updated';
    }
}
