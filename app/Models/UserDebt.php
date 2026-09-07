<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserDebt extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_WRITTEN_OFF = 'written_off';

    protected $fillable = [
        'user_id',
        'original_booking_id',
        'applied_booking_id',
        'type',
        'amount',
        'amount_settled',
        'currency',
        'status',
        'description',
        'meta_data',
        'due_at',
        'applied_at',
        'settled_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_settled' => 'decimal:2',
        'meta_data' => 'array',
        'due_at' => 'datetime',
        'applied_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function originalBooking()
    {
        return $this->belongsTo(Booking::class, 'original_booking_id');
    }

    public function appliedBooking()
    {
        return $this->belongsTo(Booking::class, 'applied_booking_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApplied($query)
    {
        return $query->where('status', self::STATUS_APPLIED);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPLIED]);
    }

    public function getRemainingAmountAttribute(): float
    {
        $remaining = (float) $this->amount - (float) $this->amount_settled;
        return max(0, round($remaining, 2));
    }

    public function markApplied(Booking $booking): void
    {
        if ($this->status === self::STATUS_PENDING) {
            $this->update([
                'status' => self::STATUS_APPLIED,
                'applied_booking_id' => $booking->id,
                'applied_at' => now(),
            ]);
        }
    }

    public function markSettled(?float $amount = null): void
    {
        $settledAmount = $amount !== null ? min($amount, $this->amount) : $this->amount;

        $this->update([
            'status' => self::STATUS_SETTLED,
            'amount_settled' => $settledAmount,
            'settled_at' => now(),
        ]);
    }

    public static function settleForBooking(Booking $booking): void
    {
        if (!$booking->user) {
            return;
        }

        $booking->user->debts()
            ->where('status', self::STATUS_APPLIED)
            ->where('applied_booking_id', $booking->id)
            ->get()
            ->each(function (UserDebt $debt) {
                $debt->markSettled();
            });
    }
}
