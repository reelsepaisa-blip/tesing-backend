<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromoUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'promo_code_id',
        'user_id',
        'booking_id',
        'original_amount',
        'discount_amount',
        'final_amount',
        'meta_data',
    ];

    protected $casts = [
        'original_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'meta_data' => 'array',
    ];

    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function transaction()
    {
        return $this->morphOne(WalletTransaction::class, 'reference');
    }

    
    public function getStatusAttribute(): string
    {
        if (!$this->booking) {
            return 'applied';
        }

        $bookingStatus = $this->booking->status;

        return match ($bookingStatus) {
            'completed' => 'applied',
            'cancelled' => 'cancelled',
            'expired' => 'expired',
            default => 'applied',
        };
    }
}
