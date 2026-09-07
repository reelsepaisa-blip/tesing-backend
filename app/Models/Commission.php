<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    use HasFactory;

    const TYPE_FIXED = 'fixed';
    const TYPE_PERCENTAGE = 'percentage';

    protected $fillable = [
        'booking_id',
        'driver_id',
        'ride_type_id',
        'base_fare',
        'total_fare',
        'commission_type',
        'commission_value',
        'commission_amount',
        'driver_amount',
        'tax_percentage',
        'tax_amount',
        'meta_data',
    ];

    protected $casts = [
        'base_fare' => 'decimal:2',
        'total_fare' => 'decimal:2',
        'commission_value' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'driver_amount' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'meta_data' => 'array',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function rideType()
    {
        return $this->belongsTo(RideType::class);
    }

    public function transactions()
    {
        return $this->morphMany(WalletTransaction::class, 'reference');
    }


    public function isFixedCommission(): bool
    {
        return $this->commission_type === self::TYPE_FIXED;
    }

    public function isPercentageCommission(): bool
    {
        return $this->commission_type === self::TYPE_PERCENTAGE;
    }

    public static function calculateCommission(
        float $baseFare,
        float $totalFare,
        string $commissionType,
        float $commissionValue,
        float $taxPercentage = 0
    ): array {
        $commissionAmount = match ($commissionType) {
            self::TYPE_FIXED => $commissionValue,
            self::TYPE_PERCENTAGE => ($totalFare * $commissionValue) / 100,
            default => 0,
        };

        $taxAmount = ($commissionAmount * $taxPercentage) / 100;
        $driverAmount = $totalFare - $commissionAmount - $taxAmount;

        return [
            'commission_amount' => round($commissionAmount, 2),
            'tax_amount' => round($taxAmount, 2),
            'driver_amount' => round($driverAmount, 2),
        ];
    }
}








