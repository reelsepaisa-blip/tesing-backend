<?php

namespace App\Events;

use App\Models\SupportChat;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportChatMessage implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $supportChat;
    public $user;
    public $admin;
    public $sender;

    
    public function __construct(SupportChat $supportChat)
    {
        $this->supportChat = $supportChat->load(['user', 'admin']);
        $this->user = $this->supportChat->user;
        $this->admin = $this->supportChat->admin;
        $this->sender = $this->supportChat->sender;
    }

    
    public function broadcastOn(): array
    {
        $channels = [];

        if ($this->supportChat->sender_type !== 'user') {
            $channels[] = new PrivateChannel('support.user.' . $this->user->id);

            if ($this->supportChat->booking_id) {
                $channels[] = new PrivateChannel('support.booking.' . $this->supportChat->booking_id);
            }
        }

        if ($this->admin) {
            $channels[] = new PrivateChannel('support.admin.' . $this->admin->id);
        }

        $channels[] = new PrivateChannel('support.admins');


        return $channels;
    }

    
    public function broadcastAs(): string
    {
        return 'support.chat.message';
    }

    
    public function broadcastWith(): array
    {
        return [
            'support_chat' => [
                'id' => (string) $this->supportChat->id,
                'user_id' => (string) $this->supportChat->user_id,
                'admin_id' => $this->supportChat->admin_id ? (string) $this->supportChat->admin_id : null,
                'message' => $this->supportChat->message,
                'message_type' => $this->supportChat->message_type,
                'metadata' => $this->supportChat->metadata,
                'is_read' => $this->supportChat->is_read,
                'status' => $this->supportChat->status,
                'subject' => $this->supportChat->subject,
                'priority' => $this->supportChat->priority,
                'sender_type' => $this->supportChat->sender_type,
                'sender' => [
                    'id' => (string) $this->sender->id,
                    'name' => $this->sender->name,
                    'phone' => $this->sender->phone,
                    'profile_photo' => $this->getProfilePhotoUrl($this->sender->profile_photo ?? ''),
                    'sender_type' => $this->supportChat->sender_type,
                ],
                'created_at' => $this->supportChat->created_at->toISOString(),
                'updated_at' => $this->supportChat->updated_at->toISOString(),
                'read_at' => $this->supportChat->read_at ? $this->supportChat->read_at->toISOString() : null,
            ],
            'user' => [
                'id' => (string) $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
                'profile_photo' => $this->getProfilePhotoUrl($this->user->profile_photo ?? ''),
            ],
            'admin' => $this->admin ? [
                'id' => (string) $this->admin->id,
                'name' => $this->admin->name,
                'phone' => $this->admin->phone,
                'profile_photo' => $this->getProfilePhotoUrl($this->admin->profile_photo ?? ''),
            ] : null,
            'timestamp' => now()->toISOString(),
        ];
    }

    
    private function getProfilePhotoUrl(?string $profilePhoto): string
    {
        if (empty($profilePhoto)) {
            return '';
        }

        if (filter_var($profilePhoto, FILTER_VALIDATE_URL)) {
            return $profilePhoto;
        }

        return url('storage/' . $profilePhoto);
    }
}
