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

class SupportTyping implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $user;
    public $admin;
    public $isTyping;

    
    public function __construct(User $user, ?User $admin, bool $isTyping = true)
    {
        $this->user = $user;
        $this->admin = $admin;
        $this->isTyping = $isTyping;
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
        return 'support.typing';
    }

    
    public function broadcastWith(): array
    {
        return [
            'user' => [
                'id' => (string) $this->user->id,
                'name' => $this->user->name,
                'sender_type' => 'user',
            ],
            'admin' => $this->admin ? [
                'id' => (string) $this->admin->id,
                'name' => $this->admin->name,
                'sender_type' => 'admin',
            ] : null,
            'is_typing' => $this->isTyping,
            'timestamp' => now()->toISOString(),
        ];
    }
}
