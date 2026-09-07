<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends Model
{
    use HasFactory, SoftDeletes;

    const TYPE_CASH = 'cash';
    const TYPE_CARD = 'card';
    const TYPE_WALLET = 'wallet';
    const TYPE_ONLINE = 'online';

    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_MAINTENANCE = 'maintenance';

    protected $fillable = [
        'name',
        'code',
        'type',
        'description',
        'icon',
        'color',
        'is_active',
        'is_online',
        'requires_verification',
        'min_amount',
        'max_amount',
        'processing_fee_percentage',
        'processing_fee_fixed',
        'sort_order',
        'configuration',
        'supported_currencies',
        'supported_countries',
        'status',
        'status_message',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_online' => 'boolean',
        'requires_verification' => 'boolean',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'processing_fee_percentage' => 'decimal:2',
        'processing_fee_fixed' => 'decimal:2',
        'sort_order' => 'integer',
        'configuration' => 'array',
        'supported_currencies' => 'array',
        'supported_countries' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOnline($query)
    {
        return $query->where('is_online', true);
    }

    public function scopeCash($query)
    {
        return $query->where('type', self::TYPE_CASH);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function isAvailable(): bool
    {
        return $this->is_active && $this->status === self::STATUS_ACTIVE;
    }

    public function isMaintenance(): bool
    {
        return $this->status === self::STATUS_MAINTENANCE;
    }

    public function calculateProcessingFee(float $amount): float
    {
        $percentageFee = ($amount * $this->processing_fee_percentage) / 100;
        return round($percentageFee + $this->processing_fee_fixed, 2);
    }

    public function isAmountValid(float $amount): bool
    {
        if ($this->min_amount && $amount < $this->min_amount) {
            return false;
        }

        if ($this->max_amount && $amount > $this->max_amount) {
            return false;
        }

        return true;
    }

    public function getStatusBadgeColor(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'success',
            self::STATUS_INACTIVE => 'danger',
            self::STATUS_MAINTENANCE => 'warning',
            default => 'secondary',
        };
    }

    public function getTypeBadgeColor(): string
    {
        return match ($this->type) {
            self::TYPE_CASH => 'success',
            self::TYPE_CARD => 'primary',
            self::TYPE_WALLET => 'info',
            self::TYPE_ONLINE => 'warning',
            default => 'secondary',
        };
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'payment_method');
    }
}
