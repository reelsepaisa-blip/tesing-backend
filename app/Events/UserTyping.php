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

class UserTyping implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $booking;
    public $user;
    public $isTyping;

    
    public function __construct(Booking $booking, User $user, bool $isTyping = true)
    {
        $this->booking = $booking;
        $this->user = $user;
        $this->isTyping = $isTyping;
    }

    
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.booking.' . $this->booking->id),
        ];
    }

    
    public function broadcastAs(): string
    {
        return 'chat.user.typing';
    }

    
    public function broadcastWith(): array
    {
        return [
            'booking_id' => (string) $this->booking->id,
            'user' => [
                'id' => (string) $this->user->id,
                'name' => $this->user->name,
                'sender_type' => $this->user->hasRole('driver') ? 'driver' : 'user',
            ],
            'is_typing' => $this->isTyping,
            'timestamp' => now()->toISOString(),
        ];
    }
}
