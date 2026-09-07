<?php

namespace App\Services;

use App\Models\User;
use App\Models\City;
use App\Models\RideType;


class ETAService
{
    protected GoogleMapsService $googleMapsService;

    public function __construct(GoogleMapsService $googleMapsService)
    {
        $this->googleMapsService = $googleMapsService;
    }

    
    public function calculateETA(
        float $pickupLatitude,
        float $pickupLongitude,
        int $cityId,
        int $rideTypeId,
        ?float $driverLatitude = null,
        ?float $driverLongitude = null
    ): array {
        $city = City::findOrFail($cityId);
        $rideType = RideType::findOrFail($rideTypeId);

        if ($driverLatitude && $driverLongitude) {
            $eta = $this->calculateETAFromDriverLocation(
                $driverLatitude,
                $driverLongitude,
                $pickupLatitude,
                $pickupLongitude,
                $rideType
            );
        } else {
            $eta = $this->calculateETAFromNearestDriver(
                $pickupLatitude,
                $pickupLongitude,
                $cityId,
                $rideTypeId,
                $rideType
            );
        }

        $eta = $this->applyAdditionalFactors($eta, $city, $rideType);

        $response = [
            'estimated_eta' => $eta['total_minutes'],
            'distance_to_pickup' => $eta['distance_to_pickup'],
            'traffic_factor' => $eta['traffic_factor'],
            'time_of_day_factor' => $eta['time_of_day_factor'],
            'vehicle_type_factor' => $eta['vehicle_type_factor'],
            'breakdown' => $eta
        ];

        if (isset($eta['matched_driver'])) {
            $response['matched_driver'] = $eta['matched_driver'];
        }

        return $response;
    }

    
    protected function calculateETAFromDriverLocation(
        float $driverLat,
        float $driverLng,
        float $pickupLat,
        float $pickupLng,
        RideType $rideType
    ): array {
        $travelMode = $this->getTravelModeForRideType($rideType);

        $routeData = $this->googleMapsService->getDistanceAndDuration(
            $driverLat,
            $driverLng,
            $pickupLat,
            $pickupLng,
            $travelMode
        );

        $baseMinutes = $routeData['duration'];
        $distanceToPickup = $routeData['distance'];

        return [
            'base_minutes' => $baseMinutes,
            'distance_to_pickup' => $distanceToPickup,
            'traffic_factor' => $this->calculateTrafficFactor($routeData),
            'time_of_day_factor' => $this->calculateTimeOfDayFactor(),
            'vehicle_type_factor' => $this->calculateVehicleTypeFactor($rideType),
            'total_minutes' => 0 // Will be calculated later
        ];
    }

    
    protected function calculateETAFromNearestDriver(
        float $pickupLat,
        float $pickupLng,
        int $cityId,
        int $rideTypeId,
        RideType $rideType
    ): array {
        $nearestDriver = $this->findNearestAvailableDriver(
            $pickupLat,
            $pickupLng,
            $cityId,
            $rideTypeId
        );

        if (!$nearestDriver) {
            return $this->getDefaultETA($rideType);
        }

        if (!$nearestDriver->relationLoaded('currentLocation')) {
            $nearestDriver->load('currentLocation');
        }
        if (!$nearestDriver->relationLoaded('vehicles')) {
            $nearestDriver->load('vehicles');
        }

        $driverLat = null;
        $driverLng = null;

        if ($nearestDriver->currentLocation) {
            $coordinates = $nearestDriver->currentLocation->parseLocation();
            $driverLat = $coordinates['latitude'] ?? null;
            $driverLng = $coordinates['longitude'] ?? null;
        } elseif ($nearestDriver->last_latitude && $nearestDriver->last_longitude) {
            $driverLat = $nearestDriver->last_latitude;
            $driverLng = $nearestDriver->last_longitude;
        }

        $etaData = $this->calculateETAFromDriverLocation(
            $driverLat,
            $driverLng,
            $pickupLat,
            $pickupLng,
            $rideType
        );

        $etaData['matched_driver'] = [
            'id' => $nearestDriver->id,
            'name' => $nearestDriver->name,
            'phone' => $nearestDriver->phone,
            'vehicle' => $nearestDriver->vehicles->first(),
            'distance_to_pickup' => $etaData['distance_to_pickup'],
            'driver_latitude' => $driverLat,
            'driver_longitude' => $driverLng,
        ];

        return $etaData;
    }

    
    protected function findNearestAvailableDriver(
        float $pickupLat,
        float $pickupLng,
        int $cityId,
        int $rideTypeId
    ): ?User {
        $onlineDrivers = User::drivers()
            ->where('status', 'active')
            ->where('is_online', 1)
            ->with(['currentLocation', 'vehicles'])
            ->get();

        if ($onlineDrivers->isEmpty()) {
            return null;
        }

        $availableDrivers = collect();

        foreach ($onlineDrivers as $driver) {
            $driverLat = null;
            $driverLng = null;

            if ($driver->currentLocation) {
                $coordinates = $driver->currentLocation->parseLocation();
                $driverLat = $coordinates['latitude'] ?? null;
                $driverLng = $coordinates['longitude'] ?? null;
            } elseif ($driver->last_latitude && $driver->last_longitude) {
                $driverLat = $driver->last_latitude;
                $driverLng = $driver->last_longitude;
            }

            if (!$driverLat || !$driverLng) {

                continue;
            }

            $matchingVehicle = $driver->vehicles()
                ->where('ride_type_id', $rideTypeId)
                ->where('status', 'active')
                ->first();

            if (!$matchingVehicle) {

                continue;
            }

            $distance = $this->calculateHaversineDistance(
                $pickupLat,
                $pickupLng,
                $driverLat,
                $driverLng
            );

            $availableDrivers->push([
                'driver' => $driver,
                'distance' => $distance,
                'vehicle' => $matchingVehicle,
                'latitude' => $driverLat,
                'longitude' => $driverLng
            ]);


        }

        if ($availableDrivers->isEmpty()) {

            return null;
        }

        $nearestDriverData = $availableDrivers->sortBy('distance')->first();
        $nearestDriver = $nearestDriverData['driver'];



        return $nearestDriver;
    }

    
    public function getAvailableRideTypesWithDrivers(
        float $pickupLat,
        float $pickupLng,
        int $cityId,
        array $rideTypeIds
    ): array {
        $availableRideTypes = [];

        foreach ($rideTypeIds as $rideTypeId) {
            $rideType = RideType::find($rideTypeId);
            if (!$rideType) {
                continue;
            }

            $nearestDriver = $this->findNearestAvailableDriver($pickupLat, $pickupLng, $cityId, $rideTypeId);

            if ($nearestDriver) {
                if (!$nearestDriver->relationLoaded('currentLocation')) {
                    $nearestDriver->load('currentLocation');
                }

                $driverLat = null;
                $driverLng = null;

                if ($nearestDriver->currentLocation) {
                    $coordinates = $nearestDriver->currentLocation->parseLocation();
                    $driverLat = $coordinates['latitude'] ?? null;
                    $driverLng = $coordinates['longitude'] ?? null;
                } elseif ($nearestDriver->last_latitude && $nearestDriver->last_longitude) {
                    $driverLat = $nearestDriver->last_latitude;
                    $driverLng = $nearestDriver->last_longitude;
                }

                $etaData = $this->calculateETA(
                    $pickupLat,
                    $pickupLng,
                    $cityId,
                    $rideTypeId,
                    $driverLat,
                    $driverLng
                );

                $vehicle = $nearestDriver->vehicles()
                    ->where('ride_type_id', $rideTypeId)
                    ->where('status', 'active')
                    ->first();

                $availableRideTypes[strtolower($rideType->name)] = [
                    'estimated_arrived_in' => $etaData['estimated_eta'] . ' min',
                    'estimate_arrived_time' => now()->addMinutes($etaData['estimated_eta'])->format('Y-m-d H:i:s'),
                    'driver_info' => [
                        'driver_id' => $nearestDriver->id,
                        'driver_name' => $nearestDriver->name,
                        'driver_phone' => $nearestDriver->phone,
                        'vehicle_model' => $vehicle->model ?? 'N/A',
                        'vehicle_number' => $vehicle->registration_number ?? 'N/A',
                        'distance_to_pickup' => $etaData['distance_to_pickup'] ?? 0,
                    ]
                ];


            } else {

            }
        }

        return $availableRideTypes;
    }

    
    protected function calculateTrafficFactor(array $routeData): float
    {
        $baseDuration = $routeData['duration'];
        $trafficDuration = $routeData['duration_in_traffic'] ?? $baseDuration;

        if ($baseDuration <= 0) {
            return 1.0; // No traffic delay for same location
        }

        if ($trafficDuration > $baseDuration) {
            return $trafficDuration / $baseDuration;
        }

        return 1.0; // No traffic delay
    }

    
    protected function calculateTimeOfDayFactor(): float
    {
        $hour = (int) now()->format('H');

        if (($hour >= 7 && $hour <= 9) || ($hour >= 17 && $hour <= 19)) {
            return 1.3; // 30% slower during peak hours
        }

        if ($hour >= 22 || $hour <= 5) {
            return 0.8; // 20% faster during late night
        }

        return 1.0; // Normal hours
    }

    
    protected function calculateVehicleTypeFactor(RideType $rideType): float
    {
        switch (strtolower($rideType->name)) {
            case 'bike':
            case 'motorcycle':
                return 0.9; // Bikes are faster in traffic
            case 'car':
            case 'sedan':
                return 1.0; // Standard speed
            case 'suv':
            case 'premium':
                return 1.1; // Slightly slower due to size
            case 'auto':
            case 'rickshaw':
                return 0.95; // Slightly faster than cars
            default:
                return 1.0;
        }
    }

    
    protected function applyAdditionalFactors(array $eta, City $city, RideType $rideType): array
    {
        $baseMinutes = $eta['base_minutes'];

        $trafficFactor = $eta['traffic_factor'];
        $timeOfDayFactor = $eta['time_of_day_factor'];
        $vehicleTypeFactor = $eta['vehicle_type_factor'];

        $totalMinutes = $baseMinutes * $trafficFactor * $timeOfDayFactor * $vehicleTypeFactor;

        $bufferMinutes = $this->calculateBufferTime($city, $rideType);
        $totalMinutes += $bufferMinutes;

        $minETA = $this->getMinimumETA($rideType);
        $totalMinutes = max($totalMinutes, $minETA);

        $totalMinutes = round($totalMinutes);

        return array_merge($eta, [
            'buffer_minutes' => $bufferMinutes,
            'total_minutes' => $totalMinutes
        ]);
    }

    
    protected function calculateBufferTime(City $city, RideType $rideType): float
    {
        $baseBuffer = 2.0; // Base 2 minutes buffer

        if ($city->name === 'Mumbai' || $city->name === 'Delhi') {
            $baseBuffer += 1.0; // Extra buffer for large cities
        }

        switch (strtolower($rideType->name)) {
            case 'bike':
                $baseBuffer += 0.5; // Less buffer for bikes
                break;
            case 'suv':
            case 'premium':
                $baseBuffer += 1.5; // More buffer for larger vehicles
                break;
        }

        return $baseBuffer;
    }

    
    protected function getMinimumETA(RideType $rideType): float
    {
        switch (strtolower($rideType->name)) {
            case 'bike':
                return 3.0; // Minimum 3 minutes for bikes
            case 'car':
            case 'sedan':
                return 4.0; // Minimum 4 minutes for cars
            case 'suv':
            case 'premium':
                return 5.0; // Minimum 5 minutes for SUVs
            default:
                return 4.0;
        }
    }

    
    protected function getDefaultETA(RideType $rideType): array
    {
        $defaultMinutes = $this->getMinimumETA($rideType) * 2; // Double the minimum

        return [
            'base_minutes' => $defaultMinutes,
            'distance_to_pickup' => 0,
            'traffic_factor' => 1.0,
            'time_of_day_factor' => $this->calculateTimeOfDayFactor(),
            'vehicle_type_factor' => $this->calculateVehicleTypeFactor($rideType),
            'buffer_minutes' => 2.0,
            'total_minutes' => $defaultMinutes
        ];
    }

    
    protected function calculateHaversineDistance(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        if (abs($lat1 - $lat2) < 0.000001 && abs($lon1 - $lon2) < 0.000001) {
            return 0.0;
        }

        $earthRadius = 6371; // Earth's radius in kilometers

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        if ($a >= 1.0) {
            $a = 0.999999;
        }

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    
    protected function getTravelModeForRideType(RideType $rideType): string
    {
        switch (strtolower($rideType->name)) {
            case 'bike':
            case 'motorcycle':
                return 'bicycling'; // Google Maps doesn't have motorcycle mode, use bicycling as closest
            case 'auto':
            case 'rickshaw':
                return 'driving'; // Auto rickshaws follow car routes
            case 'car':
            case 'sedan':
            case 'suv':
            case 'premium':
            case 'mini':
            case 'prime':
            default:
                return 'driving';
        }
    }

    
    public function getETABreakdown(array $eta): array
    {
        return [
            'estimated_eta_minutes' => $eta['estimated_eta'],
            'breakdown' => [
                'base_travel_time' => $eta['breakdown']['base_minutes'] ?? 0,
                'traffic_delay' => $eta['breakdown']['traffic_factor'] ?? 1,
                'time_of_day_factor' => $eta['breakdown']['time_of_day_factor'] ?? 1,
                'vehicle_type_factor' => $eta['breakdown']['vehicle_type_factor'] ?? 1,
                'buffer_time' => $eta['breakdown']['buffer_minutes'] ?? 0,
                'distance_to_pickup_km' => $eta['breakdown']['distance_to_pickup'] ?? 0
            ]
        ];
    }
}
