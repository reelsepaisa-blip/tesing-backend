<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use App\Services\EnhancedFareCalculationService;

class City extends Model
{
    use HasFactory, SoftDeletes, \App\Traits\PreventsDemoDeletion;

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($city) {
            $city->zones()->delete();
        });

        static::creating(function ($city) {
            $exists = static::where('name', $city->name)
                ->where('state', $city->state)
                ->where('country', $city->country)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'city' => ["The city '{$city->name}, {$city->state}, {$city->country}' already exists in the system."]
                ]);
            }
        });

        static::updating(function ($city) {
            if ($city->isDirty('status')) {
                $newStatus = $city->status;

                if (!$newStatus) {
                    $city->zones()->update(['status' => false]);
                }
            }
        });
    }

    protected $fillable = [
        'name',
        'state',
        'country',
        'latitude',
        'longitude',
        'status',
        'timezone',
        'currency',
        'service_start_time',
        'service_end_time',
        'base_distance',
        'base_price',
        'price_per_km',
        'price_per_minute',
        'minimum_fare',
        'cancellation_charge',
        'waiting_charge_per_minute',
        'waiting_time_limit',
        'commission_rate',
        'tax_rate',
        'night_charge_multiplier',
        'night_start_time',
        'night_end_time',
        'meta_data',
    ];

    protected $casts = [
        'latitude' => 'decimal:4',
        'longitude' => 'decimal:4',
        'status' => 'boolean',
        'base_distance' => 'decimal:2',
        'base_price' => 'decimal:2',
        'price_per_km' => 'decimal:2',
        'price_per_minute' => 'decimal:2',
        'minimum_fare' => 'decimal:2',
        'cancellation_charge' => 'decimal:2',
        'waiting_charge_per_minute' => 'decimal:2',
        'waiting_time_limit' => 'integer',
        'commission_rate' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'night_charge_multiplier' => 'decimal:2',
        'service_start_time' => 'datetime',
        'service_end_time' => 'datetime',
        'night_start_time' => 'datetime',
        'night_end_time' => 'datetime',
        'meta_data' => 'array',
    ];

    public function zones()
    {
        return $this->hasMany(Zone::class);
    }

    public function drivers()
    {
        return $this->hasMany(DriverProfile::class);
    }

    public function taxRules()
    {
        return $this->hasMany(CityTaxRule::class);
    }

    public function rideTypes()
    {
        return $this->belongsToMany(RideType::class, 'city_ride_types')
            ->withPivot([
                'base_distance',
                'base_price',
                'price_per_km',
                'price_per_minute',
                'minimum_fare',
                'cancellation_charge',
                'waiting_charge_per_minute',
                'waiting_time_limit',
                'commission_rate',
                'status',
            ])
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function isServiceAvailable(): bool
    {
        if (!$this->status) {
            return false;
        }

        $now = now()->setTimezone($this->timezone);
        $startTime = $now->copy()->setTimeFromTimeString($this->service_start_time);
        $endTime = $now->copy()->setTimeFromTimeString($this->service_end_time);

        return $now->between($startTime, $endTime);
    }

    public function isNightChargeApplicable(): bool
    {
        $now = now()->setTimezone($this->timezone);
        $startTime = $now->copy()->setTimeFromTimeString($this->night_start_time);
        $endTime = $now->copy()->setTimeFromTimeString($this->night_end_time);

        if ($startTime->greaterThan($endTime)) {
            return $now->greaterThanOrEqualTo($startTime) || $now->lessThan($endTime);
        }

        return $now->between($startTime, $endTime);
    }

    public function getFareMultiplier(): float
    {
        return $this->isNightChargeApplicable() ? $this->night_charge_multiplier : 1.0;
    }

    public function calculateBaseFare(RideType $rideType, float $distance, int $duration): array
    {
        $pricing = $rideType->getPriceForCity($this);
        $multiplier = $this->getFareMultiplier();

        $baseFare = $pricing['base_price'];

        $extraDistance = max(0, $distance - $pricing['base_distance']);
        $distanceFare = $extraDistance * $pricing['price_per_km'];

        $timeFare = $duration * $pricing['price_per_minute'];

        $subtotal = ($baseFare + $distanceFare + $timeFare) * $multiplier;

        $subtotal = max($subtotal, $pricing['minimum_fare']);


        $taxService = app(EnhancedFareCalculationService::class);
        $taxData = $taxService->calculateTaxes($this, $subtotal);
        $tax = $taxData['total_tax_amount'];

        $total = $subtotal + $tax;

        return [
            'base_fare' => $baseFare,
            'distance_fare' => $distanceFare,
            'time_fare' => $timeFare,
            'night_charge' => $multiplier > 1 ? (($subtotal / $multiplier) * ($multiplier - 1)) : 0,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'tax_rate' => $taxData['total_tax_rate'],
            'tax_breakdown' => $taxData['tax_breakdown'],
            'total' => $total,
            'commission' => $total * ($pricing['commission_rate'] / 100),
            'driver_payout' => $total * (1 - $pricing['commission_rate'] / 100),
        ];
    }
}
