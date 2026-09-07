<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportChatRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $user;
    public $admin;
    public $messageIds;
    public $readAt;

    
    public function __construct(User $user, ?User $admin, array $messageIds)
    {
        $this->user = $user;
        $this->admin = $admin;
        $this->messageIds = $messageIds;
        $this->readAt = now();
    }

    
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('private-support.user.' . $this->user->id),
        ];

        if ($this->admin) {
            $channels[] = new PrivateChannel('private-support.admin.' . $this->admin->id);
        } else {
            $channels[] = new PrivateChannel('private-support.admins');
        }

        return $channels;
    }

    
    public function broadcastAs(): string
    {
        return 'support.chat.read';
    }

    
    public function broadcastWith(): array
    {
        return [
            'user_id' => (string) $this->user->id,
            'admin_id' => $this->admin ? (string) $this->admin->id : null,
            'message_ids' => array_map('strval', $this->messageIds),
            'read_at' => $this->readAt->toISOString(),
            'timestamp' => now()->toISOString(),
        ];
    }
}
