<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use App\Services\EnhancedFareCalculationService;

class RideType extends Model
{
    use HasFactory, SoftDeletes, \App\Traits\PreventsDemoDeletion;

    protected $fillable = [
        'name',
        'code',
        'description',
        'icon',
        'capacity',
        'status',
        'order',
        'base_distance',
        'base_price',
        'price_per_km',
        'price_per_minute',
        'minimum_fare',
        'cancellation_charge',
        'waiting_charge_per_minute',
        'waiting_time_limit',
        'commission_rate',
        'driver_requirements',
        'vehicle_requirements',
        'meta_data',
        'available_cities',
        'city_pricing',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'status' => 'boolean',
        'order' => 'integer',
        'base_distance' => 'decimal:2',
        'base_price' => 'decimal:2',
        'price_per_km' => 'decimal:2',
        'price_per_minute' => 'decimal:2',
        'minimum_fare' => 'decimal:2',
        'cancellation_charge' => 'decimal:2',
        'waiting_charge_per_minute' => 'decimal:2',
        'waiting_time_limit' => 'integer',
        'commission_rate' => 'decimal:2',
        'driver_requirements' => 'array',
        'vehicle_requirements' => 'array',
        'meta_data' => 'array',
    ];

    public function cities()
    {
        return $this->belongsToMany(City::class, 'city_ride_types')
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

    public function availableCities()
    {
        return $this->belongsToMany(City::class, 'available_ride_cities')
            ->withTimestamps();
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    public function isAvailableInCity(City $city): bool
    {
        // Log the check details
        $availableCitiesQuery = $this->availableCities()
            ->where('city_id', $city->id);

        $exists = $availableCitiesQuery->exists();

        // Get detailed information for logging
        $availableCitiesCount = $this->availableCities()->where('city_id', $city->id)->count();
        $allAvailableCities = $this->availableCities()->pluck('city_id')->toArray();

        // Check city_ride_types table as well
        $hasCityPricing = DB::table('city_ride_types')
            ->where('ride_type_id', $this->id)
            ->where('city_id', $city->id)
            ->where('status', true)
            ->exists();

        $cityRideTypesCount = DB::table('city_ride_types')
            ->where('ride_type_id', $this->id)
            ->where('city_id', $city->id)
            ->where('status', true)
            ->count();

        return $exists;
    }

    public function getPriceForCity(City $city): array
    {
        $cityPrice = DB::table('city_ride_types')
            ->where('ride_type_id', $this->id)
            ->where('city_id', $city->id)
            ->where('status', true)
            ->first();

        if ($cityPrice) {
            return [
                'base_distance' => $cityPrice->base_distance,
                'base_price' => $cityPrice->base_price,
                'price_per_km' => $cityPrice->price_per_km,
                'price_per_minute' => $cityPrice->price_per_minute,
                'minimum_fare' => $cityPrice->minimum_fare,
                'waiting_charge_per_minute' => $cityPrice->waiting_charge_per_minute,
                'waiting_time_limit' => $cityPrice->waiting_time_limit,
                'commission_rate' => $cityPrice->commission_rate,
            ];
        }

        $defaultPrice = DB::table('city_ride_types')
            ->where('ride_type_id', $this->id)
            ->whereNull('city_id')
            ->where('status', true)
            ->first();

        if ($defaultPrice) {
            return [
                'base_distance' => $defaultPrice->base_distance,
                'base_price' => $defaultPrice->base_price,
                'price_per_km' => $defaultPrice->price_per_km,
                'price_per_minute' => $defaultPrice->price_per_minute,
                'minimum_fare' => $defaultPrice->minimum_fare,
                'waiting_charge_per_minute' => $defaultPrice->waiting_charge_per_minute,
                'waiting_time_limit' => $defaultPrice->waiting_time_limit,
                'commission_rate' => $defaultPrice->commission_rate,
            ];
        }

        return [
            'base_distance' => $this->base_distance,
            'base_price' => $this->base_price,
            'price_per_km' => $this->price_per_km,
            'price_per_minute' => $this->price_per_minute,
            'minimum_fare' => $this->minimum_fare,
            'waiting_charge_per_minute' => $this->waiting_charge_per_minute,
            'waiting_time_limit' => $this->waiting_time_limit,
            'commission_rate' => $this->commission_rate,
        ];
    }

    public function calculateFare(
        City $city,
        float $distance,
        int $duration,
        ?float $waitingTime = 0,
        bool $isCancelled = false
    ): array {
        $pricing = $this->getPriceForCity($city);
        $multiplier = $city->getFareMultiplier();

        if ($isCancelled) {
            return [
                'base_fare' => 0,
                'distance_fare' => 0,
                'time_fare' => 0,
                'waiting_charge' => 0,
                'cancellation_charge' => 0,
                'night_charge' => 0,
                'subtotal' => 0,
                'tax' => 0,
                'total' => 0,
            ];
        }

        $baseFare = $pricing['base_price'];

        $extraDistance = max(0, $distance - $pricing['base_distance']);
        $distanceFare = $extraDistance * $pricing['price_per_km'];

        $timeFare = $duration * $pricing['price_per_minute'];

        $waitingCharge = 0;
        if ($waitingTime > $pricing['waiting_time_limit']) {
            $extraWaitingTime = $waitingTime - $pricing['waiting_time_limit'];
            $waitingCharge = $extraWaitingTime * $pricing['waiting_charge_per_minute'];
        }

        $subtotal = ($baseFare + $distanceFare + $timeFare + $waitingCharge) * $multiplier;

        $subtotal = max($subtotal, $pricing['minimum_fare']);


        $taxService = app(EnhancedFareCalculationService::class);
        $taxData = $taxService->calculateTaxes($city, $subtotal);
        $tax = $taxData['total_tax_amount'];

        $total = $subtotal + $tax;

        return [
            'base_fare' => $baseFare,
            'distance_fare' => $distanceFare,
            'time_fare' => $timeFare,
            'waiting_charge' => $waitingCharge,
            'night_charge' => $multiplier > 1 ? ($subtotal / $multiplier) * ($multiplier - 1) : 0,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'tax_rate' => $taxData['total_tax_rate'],
            'tax_breakdown' => $taxData['tax_breakdown'],
            'total' => $total,
            'commission' => $total * ($pricing['commission_rate'] / 100),
            'driver_payout' => $total * (1 - $pricing['commission_rate'] / 100),
        ];
    }

    public function getAvailableDrivers(City $city, Point $location, float $radius = 5000): Collection
    {
        return User::query()
            ->drivers()
            ->active()
            ->online()
            ->whereHas('driverProfile', function ($query) use ($city) {
                $query->where('city_id', $city->id);
            })
            ->whereHas('vehicles', function ($query) {
                $query->where('ride_type_id', $this->id)
                    ->where('status', 'active');
            })
            ->whereHas('currentLocation', function ($query) use ($location, $radius) {
                $query->withinRadius($location, $radius);
            })
            ->get();
    }
}
