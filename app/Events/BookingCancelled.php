<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingCancelled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $booking;

    
    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->booking->user_id),
            $this->booking->driver_id ? new PrivateChannel('driver.' . $this->booking->driver_id) : null,
            new PresenceChannel('trip.' . $this->booking->id),
        ];
    }

    
    public function broadcastAs(): string
    {
        return 'booking-cancelled';
    }

    
    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->booking->id,
            'status' => $this->booking->status,
            'cancellation_reason' => $this->booking->cancellation_reason,
            'cancelled_at' => $this->booking->cancelled_at,
            'cancelled_by_type' => $this->booking->cancelled_by_type,
        ];
    }
}
