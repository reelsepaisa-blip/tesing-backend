<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Wallet extends Model
{
    use HasFactory, SoftDeletes, \App\Traits\PreventsDemoDeletion;

    protected $fillable = [
        'user_id',
        'driver_id',
        'balance',
        'hold_amount',
        'total_credit',
        'total_debit',
        'last_transaction_at',
        'status',
        'meta_data',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'hold_amount' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'total_debit' => 'decimal:2',
        'last_transaction_at' => 'datetime',
        'meta_data' => 'array',
    ];

    const STATUS_ACTIVE = 'active';
    const STATUS_BLOCKED = 'blocked';
    const STATUS_SUSPENDED = 'suspended';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeBlocked($query)
    {
        return $query->where('status', self::STATUS_BLOCKED);
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', self::STATUS_SUSPENDED);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function hasBalance(float $amount): bool
    {
        return $this->balance >= $amount;
    }

    public function credit(float $amount, string $type, string $description, ?array $meta = null, ?string $paymentType = null): WalletTransaction
    {
        return DB::transaction(function () use ($amount, $type, $description, $meta, $paymentType) {
            $this->increment('balance', $amount);
            $this->increment('total_credit', $amount);
            $this->update(['last_transaction_at' => now()]);

            $this->refresh();

            return $this->transactions()->create([
                'type' => $type,
                'payment_type' => $paymentType,
                'amount' => $amount,
                'balance' => $this->balance,
                'description' => $description,
                'meta_data' => $meta,
            ]);
        });
    }

    public function debit(float $amount, string $type, string $description, ?array $meta = null, ?string $paymentType = null, bool $allowNegative = false): WalletTransaction
    {
        return DB::transaction(function () use ($amount, $type, $description, $meta, $paymentType, $allowNegative) {
            $lockedWallet = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if (!$allowNegative && (float) $lockedWallet->balance < $amount) {
                throw new \Exception('Insufficient balance');
            }

            $lockedWallet->decrement('balance', $amount);
            $lockedWallet->increment('total_debit', $amount);
            $lockedWallet->update(['last_transaction_at' => now()]);

            $lockedWallet->refresh();
            $this->setRawAttributes($lockedWallet->getAttributes(), true);

            return $lockedWallet->transactions()->create([
                'type' => $type,
                'payment_type' => $paymentType,
                'amount' => -$amount,
                'balance' => $lockedWallet->balance,
                'description' => $description,
                'meta_data' => $meta,
            ]);
        });
    }

    public function block(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_BLOCKED,
            'meta_data' => array_merge($this->meta_data ?? [], [
                'blocked_at' => now()->toDateTimeString(),
                'blocked_reason' => $reason,
            ]),
        ]);
    }

    public function unblock(): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'meta_data' => array_merge($this->meta_data ?? [], [
                'unblocked_at' => now()->toDateTimeString(),
            ]),
        ]);
    }

    public function suspend(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_SUSPENDED,
            'meta_data' => array_merge($this->meta_data ?? [], [
                'suspended_at' => now()->toDateTimeString(),
                'suspension_reason' => $reason,
            ]),
        ]);
    }

    public function unsuspend(): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'meta_data' => array_merge($this->meta_data ?? [], [
                'unsuspended_at' => now()->toDateTimeString(),
            ]),
        ]);
    }

    public function recalculateBalance(): void
    {
        $totals = $this
            ->transactions()
            ->selectRaw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_credit')
            ->selectRaw('SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as total_debit')
            ->first();

        $this->update([
            'balance' => $totals->total_credit - $totals->total_debit,
            'total_credit' => $totals->total_credit,
            'total_debit' => $totals->total_debit,
        ]);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($wallet) {
            if (!$wallet->status) {
                $wallet->status = self::STATUS_ACTIVE;
            }
        });
    }
}
