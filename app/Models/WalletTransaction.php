<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class WalletTransaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'wallet_id',
        'driver_id',
        'type',
        'payment_type',
        'amount',
        'balance',
        'description',
        'reference_type',
        'reference_id',
        'status',
        'meta_data',
        'transection_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'meta_data' => 'array',
    ];

    const TYPE_BOOKING_PAYMENT = 'booking_payment';
    const TYPE_BOOKING_REFUND = 'booking_refund';
    const TYPE_DRIVER_PAYOUT = 'driver_payout';
    const TYPE_DRIVER_COMMISSION = 'driver_commission';
    const TYPE_WALLET_TOPUP = 'wallet_topup';
    const TYPE_WALLET_WITHDRAWAL = 'wallet_withdrawal';
    const TYPE_WITHDRAWAL_REFUND = 'withdrawal_refund';
    const TYPE_REFERRAL_BONUS = 'referral_bonus';
    const TYPE_PROMO_CREDIT = 'promo_credit';
    const TYPE_INCENTIVE_REWARD = 'incentive_reward';
    const TYPE_ADJUSTMENT = 'adjustment';
    const TYPE_REFUND_DEDUCTION = 'refund_deduction';
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_REVERSED = 'reversed';

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user()
    {
        return $this->hasOneThrough(User::class, Wallet::class, 'id', 'id', 'wallet_id', 'user_id');
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeOfTypes($query, array $types)
    {
        return $query->whereIn('type', $types);
    }

    public function scopeCredits($query)
    {
        return $query->where('amount', '>', 0);
    }

    public function scopeDebits($query)
    {
        return $query->where('amount', '<', 0);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeReversed($query)
    {
        return $query->where('status', self::STATUS_REVERSED);
    }

    public function scopeForReference($query, Model $reference)
    {
        return $query->where([
            'reference_type' => get_class($reference),
            'reference_id' => $reference->id,
        ]);
    }

    public function isCredit(): bool
    {
        return $this->amount > 0;
    }

    public function isDebit(): bool
    {
        return $this->amount < 0;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    public function getAbsoluteAmount(): float
    {
        return abs($this->amount);
    }

    public function complete(): void
    {
        if (!$this->isPending()) {
            throw new \Exception('Transaction is not in pending state');
        }

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'meta_data' => array_merge($this->meta_data ?? [], [
                'completed_at' => now()->toDateTimeString(),
            ]),
        ]);
    }

    public function fail(string $reason): void
    {
        if (!$this->isPending()) {
            throw new \Exception('Transaction is not in pending state');
        }

        DB::transaction(function () use ($reason) {
            $this->update([
                'status' => self::STATUS_FAILED,
                'meta_data' => array_merge($this->meta_data ?? [], [
                    'failed_at' => now()->toDateTimeString(),
                    'failure_reason' => $reason,
                ]),
            ]);

            // Update the related Transaction record if it exists
            if ($this->transection_id) {
                Transaction::where('transaction_id', $this->transection_id)
                    ->update([
                        'status' => self::STATUS_FAILED,
                        'failed_at' => now(),
                    ]);
            }

            // If it was a debit transaction (negative amount), we need to refund it
            if ($this->isDebit()) {
                $refundType = $this->type === self::TYPE_WALLET_WITHDRAWAL
                    ? self::TYPE_WITHDRAWAL_REFUND
                    : self::TYPE_ADJUSTMENT;

                $this->wallet->credit(
                    $this->getAbsoluteAmount(),
                    $refundType,
                    "Refund for failed transaction #{$this->id}. Reason: {$reason}",
                    [
                        'original_transaction_id' => $this->id,
                        'failure_reason' => $reason,
                    ]
                );
            }
        });
    }

    public function reverse(string $reason): void
    {
        if (!$this->isCompleted()) {
            throw new \Exception('Only completed transactions can be reversed');
        }

        $this->update([
            'status' => self::STATUS_REVERSED,
            'meta_data' => array_merge($this->meta_data ?? [], [
                'reversed_at' => now()->toDateTimeString(),
                'reversal_reason' => $reason,
            ]),
        ]);

        $this->wallet->transactions()->create([
            'type' => $this->type . '_reversal',
            'amount' => -$this->amount,
            'balance' => $this->wallet->balance,
            'description' => "Reversal of transaction #{$this->id}: {$reason}",
            'reference_type' => get_class($this),
            'reference_id' => $this->id,
            'status' => self::STATUS_COMPLETED,
            'meta_data' => [
                'original_transaction_id' => $this->id,
                'reversal_reason' => $reason,
            ],
        ]);

        $this->wallet->recalculateBalance();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (!$transaction->status) {
                $transaction->status = self::STATUS_COMPLETED;
            }
        });
    }
}
