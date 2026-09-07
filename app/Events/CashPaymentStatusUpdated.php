<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CashPaymentStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $transaction;
    public $booking;

    
    public function __construct(Transaction $transaction, Booking $booking)
    {
        $this->transaction = $transaction;
        $this->booking = $booking;
    }

    
    public function broadcastOn(): array
    {
        $channels = [
            new Channel('user.all'),
            new Channel('global.users'),
        ];

        if ($this->booking->user_id) {
            $channels[] = new PrivateChannel('user.' . $this->booking->user_id);
        }

        if ($this->booking->driver_id) {
            $channels[] = new PrivateChannel('driver.' . $this->booking->driver_id);
            $channels[] = new Channel('drivers.all');
        }

        $channels[] = new Channel('public-booking.' . $this->booking->id);

        return $channels;
    }

    
    public function broadcastAs(): string
    {
        return 'cash-payment-status-updated';
    }

    
    public function broadcastWith(): array
    {
        return [
            'transaction_id' => (string) ($this->transaction->transaction_id ?? ''),
            'booking_id' => (string) ($this->booking->id ?? ''),
            'booking_code' => (string) ($this->booking->booking_code ?? ''),
            'status' => (string) ($this->transaction->status ?? ''),
            'amount' => (string) ($this->transaction->amount ?? ''),
            'payment_method' => 'cash',
            'user_id' => (string) ($this->booking->user_id ?? ''),
            'driver_id' => (string) ($this->booking->driver_id ?? ''),
            'updated_at' => $this->transaction->updated_at ? $this->transaction->updated_at->toISOString() : '',
        ];
    }
}
