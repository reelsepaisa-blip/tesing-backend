<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CancellationPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'city_id',
        'ride_type_id',
        'allow_customer_cancellation',
        'free_cancellation_window',
        'max_cancellation_time',
        'cancellation_fee',
        'cancellation_fee_percentage',
        'driver_gets_share',
        'driver_share_percentage',
        'is_active',
    ];

    protected $casts = [
        'city_id' => 'integer',
        'ride_type_id' => 'integer',
        'allow_customer_cancellation' => 'boolean',
        'free_cancellation_window' => 'integer',
        'max_cancellation_time' => 'integer',
        'cancellation_fee' => 'decimal:2',
        'cancellation_fee_percentage' => 'decimal:2',
        'driver_gets_share' => 'boolean',
        'driver_share_percentage' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'allow_customer_cancellation' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $policy) {
            $policy->attributes['allow_customer_cancellation'] = true;
        });
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function rideType()
    {
        return $this->belongsTo(RideType::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function calculateCancellationFee(float $tripAmount): float
    {
        if (!$this->allow_customer_cancellation) {
            return 0;
        }

        $fee = $this->cancellation_fee;
        if ($this->cancellation_fee_percentage > 0) {
            $fee += $tripAmount * ($this->cancellation_fee_percentage / 100);
        }

        return $fee;
    }

    public function calculateDriverShare(float $cancellationFee): float
    {
        if (!$this->driver_gets_share) {
            return 0;
        }

        return $cancellationFee * ($this->driver_share_percentage / 100);
    }

    public function getAllowCustomerCancellationAttribute($value): bool
    {
        return true;
    }

    public function setAllowCustomerCancellationAttribute($value): void
    {
        $this->attributes['allow_customer_cancellation'] = true;
    }
}
