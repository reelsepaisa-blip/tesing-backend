<?php

namespace App\Services;

use App\Models\CancellationPolicy;
use App\Models\City;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CityService
{
    
    public function getActiveCities(): Collection
    {
        return City::where('status', 1) // 1 = active, 0 = inactive
            ->orderBy('name')
            ->get();
    }

    
    public function getCityById(int $id): ?City
    {
        return City::findOrFail($id);
    }

    
    public function getNearestCity(float $latitude, float $longitude, float $maxDistance = 50): ?City
    {
        return City::selectRaw(
            'cities.*, ST_Distance_Sphere(point(longitude, latitude), point(?, ?)) / 1000 as distance',
            [$longitude, $latitude]
        )
            ->where('status', 1)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->having('distance', '<=', $maxDistance)
            ->orderBy('distance')
            ->first();
    }

    
    public function isLocationServiceable(int $cityId, float $latitude, float $longitude): bool
    {

        $city = $this->getCityById($cityId);
        if ($city->status != 1) { // 1 = active, 0 = inactive
            return false;
        }

        $currentTime = now()->format('H:i:s');
        $startTime = date('H:i:s', strtotime($city->service_start_time));
        $endTime = date('H:i:s', strtotime($city->service_end_time));
        if ($endTime === '00:00:00') {
            $endTime = '23:59:59';
        }
        $isWithinServiceHours = $startTime <= $endTime
            ? ($currentTime >= $startTime && $currentTime <= $endTime)
            : ($currentTime >= $startTime || $currentTime <= $endTime);
        if (!$isWithinServiceHours) {
            return false;
        }
        $hasActiveZones = $city->zones()->where('status', 1)->exists();
        return $hasActiveZones;
    }

    
    public function getSurgeMultiplier(int $cityId, float $latitude, float $longitude): float
    {
        $zone = $this->getZoneForLocation($cityId, $latitude, $longitude);
        
        if (!$zone) {
            return 1.0;
        }

        $zoneModel = \App\Models\Zone::find($zone->id);
        
        return $zoneModel ? $zoneModel->getCurrentMultiplier() : 1.0;
    }

    
    public function getZoneForLocation(int $cityId, float $latitude, float $longitude): ?object
    {
        return DB::table('zones')
            ->where('city_id', $cityId)
            ->where('status', 1) // 1 = active, 0 = inactive
            ->first(); // For now, just return the first active zone
    }

    
    public function getAvailableRideTypes(int $cityId, float $latitude, float $longitude): array
    {
        $city = $this->getCityById($cityId);
        $zone = $this->getZoneForLocation($cityId, $latitude, $longitude);

        if (!$zone || $city->status != 1) { // 1 = active, 0 = inactive
            return [];
        }

        return [1, 2, 3]; // Mini, Sedan, SUV
    }

    
    public function getNightChargesMultiplier(City $city): float
    {
        return $city->getFareMultiplier();
    }

    
    public function getCancellationPolicy(?City $city = null, ?int $rideTypeId = null): ?CancellationPolicy
    {
        $cityId = $city ? $city->id : null;

        if ($cityId && $rideTypeId) {
            $policy = CancellationPolicy::active()
                ->where('city_id', $cityId)
                ->where('ride_type_id', $rideTypeId)
                ->first();

            if ($policy) {
                return $policy;
            }
        }

        if ($cityId) {
            $policy = CancellationPolicy::active()
                ->where('city_id', $cityId)
                ->whereNull('ride_type_id')
                ->first();

            if ($policy) {
                return $policy;
            }
        }

        if ($rideTypeId) {
            // First try exact ride_type_id match
            $policy = CancellationPolicy::active()
                ->whereNull('city_id')
                ->where('ride_type_id', $rideTypeId)
                ->first();

            if ($policy) {
                return $policy;
            }

            // If no exact match, try matching by ride type name in policy name
            try {
                $rideType = \App\Models\RideType::find($rideTypeId);
                if ($rideType) {
                    $rideTypeName = strtolower(trim($rideType->name));
                    
                    // Try to find policy by matching the full ride type name in policy name
                    // Match patterns: "Prime Sedan" -> "Prime Sedan Cancellation Policy"
                    // "Mini" -> "Mini Cancellation Policy", etc.
                    $policy = CancellationPolicy::active()
                        ->whereNull('city_id')
                        ->whereNull('ride_type_id')
                        ->whereRaw('LOWER(name) LIKE ?', ['%' . $rideTypeName . '%'])
                        ->orderBy('id') // Get the first matching policy
                        ->first();

                    if ($policy) {
                        return $policy;
                    }
                }
            } catch (\Exception $e) {
            }
        }

        return CancellationPolicy::active()
            ->whereNull('city_id')
            ->whereNull('ride_type_id')
            ->first();
    }

    
    public function isDropAllowed(int $cityId, float $latitude, float $longitude): bool
    {
        return DB::table('zones')
            ->where('city_id', $cityId)
            ->where('status', 1) // 1 = active, 0 = inactive
            ->exists(); // For now, just check if there are any active zones
    }

    
    public function findNearbyDrivers(int $cityId, float $latitude, float $longitude, int $rideTypeId, int $radius = 5000): Collection
    {
        $zone = $this->getZoneForLocation($cityId, $latitude, $longitude);

        if (!$zone) {
            return collect();
        }

        return DB::table('users')
            ->join('driver_profiles', 'users.id', '=', 'driver_profiles.user_id')
            ->join('vehicles', function ($join) use ($rideTypeId) {
                $join->on('users.id', '=', 'vehicles.driver_id')
                    ->where('vehicles.ride_type_id', $rideTypeId)
                    ->where('vehicles.status', 'active');
            })
            ->where('users.is_active', true)
            ->where('driver_profiles.is_online', true)
            ->where('driver_profiles.is_available', true)
            ->whereRaw("
                ST_Distance_Sphere(
                    point(driver_profiles.current_longitude, driver_profiles.current_latitude),
                    point(?, ?)
                ) <= ?
            ", [$longitude, $latitude, $radius])
            ->orderByRaw("
                ST_Distance_Sphere(
                    point(driver_profiles.current_longitude, driver_profiles.current_latitude),
                    point(?, ?)
                )
            ", [$longitude, $latitude])
            ->select('users.*', 'driver_profiles.*', 'vehicles.*')
            ->get();
    }
}
