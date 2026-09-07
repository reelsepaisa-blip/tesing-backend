<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromoCode extends Model
{
    use HasFactory, SoftDeletes, \App\Traits\PreventsDemoDeletion;

    const TYPE_FIXED = 'fixed';
    const TYPE_PERCENTAGE = 'percentage';
    const TYPE_CASHBACK = 'cashback';

    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'code',
        'description',
        'type',
        'value',
        'min_order_amount',
        'max_discount_amount',
        'max_uses_per_user',
        'max_uses_total',
        'starts_at',
        'expires_at',
        'status',
        'is_first_ride_only',
        'is_referral_code',
        'referral_user_id',
        'city_ids',
        'ride_type_ids',
        'user_types',
        'meta_data',
        'row_1_text',
        'row_2_text',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'max_uses_per_user' => 'integer',
        'max_uses_total' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_first_ride_only' => 'boolean',
        'is_referral_code' => 'boolean',
        'city_ids' => 'array',
        'ride_type_ids' => 'array',
        'user_types' => 'array',
        'meta_data' => 'array',
    ];

    public function usages()
    {
        return $this->hasMany(PromoUsage::class);
    }

    public function referralUser()
    {
        return $this->belongsTo(User::class, 'referral_user_id');
    }

    public function cities()
    {
        return $this->belongsToMany(City::class, 'promo_code_cities');
    }

    public function rideTypes()
    {
        return $this->belongsToMany(RideType::class, 'promo_code_ride_types');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, '1', 1])
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeExpired($query)
    {
        return $query->where(function ($query) {
            $query->where('status', self::STATUS_EXPIRED)
                ->orWhere('expires_at', '<=', now());
        });
    }

    public function scopeReferralCodes($query)
    {
        return $query->where('is_referral_code', true);
    }

    public function scopePromoCodes($query)
    {
        return $query->where('is_referral_code', false);
    }

    public function isActive(): bool
    {
        $activeStatuses = [self::STATUS_ACTIVE, '1', 1];
        if (!in_array($this->status, $activeStatuses)) {
            return false;
        }

        if ($this->expires_at && $this->expires_at <= now()) {
            return false;
        }

        return true;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED ||
            ($this->expires_at && $this->expires_at <= now());
    }

    public function hasReachedMaxUses(): bool
    {
        if (!$this->max_uses_total) {
            return false;
        }

        return $this->usages()->count() >= $this->max_uses_total;
    }

    public function hasUserReachedMaxUses(User $user): bool
    {
        if (!$this->max_uses_per_user) {
            return false;
        }

        return $this->usages()
            ->where('user_id', $user->id)
            ->count() >= $this->max_uses_per_user;
    }

    public function isValidForBooking(Booking $booking): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->hasReachedMaxUses()) {
            return false;
        }

        if ($this->hasUserReachedMaxUses($booking->user)) {
            return false;
        }

        if ($this->is_first_ride_only && $booking->user->bookings()->completed()->count() > 0) {
            return false;
        }

        if ($this->min_order_amount && $booking->total_fare < $this->min_order_amount) {
            return false;
        }

        $applicableCities = $this->cities()->pluck('cities.id')->toArray();
        if (!empty($applicableCities) && !in_array($booking->city_id, $applicableCities)) {
            return false;
        }

        if (!empty($this->ride_type_ids) && !in_array($booking->ride_type_id, $this->ride_type_ids)) {
            return false;
        }

        return true;
    }

    public function calculateDiscount(float $amount): float
    {
        $value = (float) $this->value;

        if ($amount <= 0) {
            return 0;
        }

        $discount = match ($this->type) {
            self::TYPE_FIXED => $value,
            self::TYPE_PERCENTAGE => ($amount * $value) / 100,
            self::TYPE_CASHBACK => $value,
            default => 0,
        };

        $discount = min($discount, $amount);

        if ($this->max_discount_amount) {
            $discount = min($discount, (float) $this->max_discount_amount);
        }

        return round($discount, 2);
    }
}
