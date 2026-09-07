<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CustomDriverBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $eventType;
    public $data;

    
    public function __construct(string $message, string $eventType = 'test.message', array $data = [])
    {
        $this->message = $message;
        $this->eventType = $eventType;
        $this->data = $data;
    }

    
    public function broadcastOn(): array
    {
        return [
            new Channel('driver.all'),
        ];
    }

    
    public function broadcastAs(): string
    {
        return $this->eventType;
    }

    
    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'data' => $this->data,
            'timestamp' => now()->toISOString(),
        ];
    }
}
