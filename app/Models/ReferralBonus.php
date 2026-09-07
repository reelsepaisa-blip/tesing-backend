<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferralBonus extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';
    const STATUS_CREDITED = 'credited';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'referrer_id',
        'referred_id',
        'type', // referrer_bonus, referred_bonus
        'amount',
        'status',
        'credited_at',
        'expires_at',
        'cancelled_reason',
        'meta_data',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'credited_at' => 'datetime',
        'expires_at' => 'datetime',
        'meta_data' => 'array',
    ];

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred()
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    public function transaction()
    {
        return $this->morphOne(WalletTransaction::class, 'reference');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCredited($query)
    {
        return $query->where('status', self::STATUS_CREDITED);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCredited(): bool
    {
        return $this->status === self::STATUS_CREDITED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function markAsCredited(): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_CREDITED,
            'credited_at' => now(),
        ]);
    }

    public function markAsExpired(): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_EXPIRED,
        ]);
    }

    public function cancel(string $reason): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_reason' => $reason,
        ]);
    }
}








