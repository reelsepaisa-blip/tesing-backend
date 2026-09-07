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

class ChatMessageRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $booking;
    public $user;
    public $messageIds;
    public $readAt;

    
    public function __construct(Booking $booking, User $user, array $messageIds)
    {
        $this->booking = $booking;
        $this->user = $user;
        $this->messageIds = $messageIds;
        $this->readAt = now();
    }

    
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.booking.' . $this->booking->id),
        ];
    }

    
    public function broadcastAs(): string
    {
        return 'chat.message.read';
    }

    
    public function broadcastWith(): array
    {
        return [
            'booking_id' => (string) $this->booking->id,
            'user_id' => (string) $this->user->id,
            'message_ids' => array_map('strval', $this->messageIds),
            'read_at' => $this->readAt->toISOString(),
            'timestamp' => now()->toISOString(),
        ];
    }
}
