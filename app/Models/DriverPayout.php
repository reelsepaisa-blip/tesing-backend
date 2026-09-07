<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DriverPayout extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'driver_id',
        'amount',
        'bank_name',
        'account_number',
        'ifsc_code',
        'reference_number',
        'status',
        'processed_at',
        'failed_reason',
        'cancelled_reason',
        'meta_data',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'meta_data' => 'array',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function transactions()
    {
        return $this->morphMany(WalletTransaction::class, 'reference');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function markAsProcessing(): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_PROCESSING,
        ]);
    }

    public function markAsCompleted(string $referenceNumber): bool
    {
        if (!$this->isProcessing()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_COMPLETED,
            'reference_number' => $referenceNumber,
            'processed_at' => now(),
        ]);
    }

    public function markAsFailed(string $reason): bool
    {
        if (!$this->isProcessing()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_FAILED,
            'failed_reason' => $reason,
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








