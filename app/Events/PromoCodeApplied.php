<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\PromoCode;
use App\Models\PromoUsage;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PromoCodeApplied implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $booking;
    public $promoCode;
    public $promoUsage;
    public $user;
    public $replacedPromo;

    
    public function __construct(Booking $booking, PromoCode $promoCode, PromoUsage $promoUsage, User $user, array $replacedPromo = null)
    {
        $this->booking = $booking;
        $this->promoCode = $promoCode;
        $this->promoUsage = $promoUsage;
        $this->user = $user;
        $this->replacedPromo = $replacedPromo;
    }

    
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->user->id),
            new Channel('user.all'),
            new Channel('global.users'),
            new Channel('public-booking.' . $this->booking->id),
        ];
    }

    
    public function broadcastAs(): string
    {
        return 'promo-code-applied';
    }

    
    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->booking->id ?? '',
            'booking_code' => $this->booking->booking_code ?? '',
            'user_id' => $this->user->id ?? '',
            'user_name' => $this->user->name ?? '',
            'promo_code' => [
                'id' => $this->promoCode->id ?? '',
                'code' => $this->promoCode->code ?? '',
                'description' => $this->promoCode->description ?? '',
                'type' => $this->promoCode->type ?? '',
                'value' => $this->promoCode->value ?? '',
                'discount_amount' => $this->promoUsage->discount_amount ?? '',
                'original_amount' => $this->promoUsage->original_amount ?? '',
                'final_amount' => $this->promoUsage->final_amount ?? '',
            ],
            'replaced_promo' => $this->replacedPromo ? [
                'code' => $this->replacedPromo['code'] ?? '',
                'discount_amount' => $this->replacedPromo['discount_amount'] ?? '',
                'original_amount' => $this->replacedPromo['original_amount'] ?? '',
            ] : null,
            'applied_at' => $this->promoUsage->created_at ? $this->promoUsage->created_at->toISOString() : '',
            'timestamp' => now()->toISOString(),
        ];
    }

    
    public function onQueue(): string
    {
        return 'default';
    }
}
