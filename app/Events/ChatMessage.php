<?php

namespace App\Events;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessage implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chat;
    public $booking;
    public $sender;
    public $receiver;

    
    public function __construct(Chat $chat)
    {
        $this->chat = $chat->load(['booking', 'sender', 'receiver']);
        $this->booking = $this->chat->booking;
        $this->sender = $this->chat->sender;
        $this->receiver = $this->chat->receiver;
    }

    
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.booking.' . $this->booking->id),
            new PrivateChannel('user.' . $this->receiver->id),
            new PrivateChannel('user.' . $this->sender->id),
        ];
    }

    
    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    
    public function broadcastWith(): array
    {
        return [
            'chat' => [
                'id' => (string) $this->chat->id,
                'booking_id' => (string) $this->chat->booking_id,
                'message' => $this->chat->message,
                'message_type' => $this->chat->message_type,
                'metadata' => $this->chat->metadata,
                'is_read' => $this->chat->is_read,
                'sender' => [
                    'id' => (string) $this->sender->id,
                    'name' => $this->sender->name,
                    'phone' => $this->sender->phone,
                    'profile_photo' => $this->getProfilePhotoUrl($this->sender->profile_photo ?? ''),
                    'sender_type' => $this->chat->sender_type,
                ],
                'receiver' => [
                    'id' => (string) $this->receiver->id,
                    'name' => $this->receiver->name,
                    'phone' => $this->receiver->phone,
                    'profile_photo' => $this->getProfilePhotoUrl($this->receiver->profile_photo ?? ''),
                ],
                'created_at' => $this->chat->created_at->toISOString(),
                'updated_at' => $this->chat->updated_at->toISOString(),
                'read_at' => $this->chat->read_at ? $this->chat->read_at->toISOString() : null,
            ],
            'booking' => [
                'id' => (string) $this->booking->id,
                'booking_code' => $this->booking->booking_code,
                'status' => $this->booking->status,
            ],
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
