<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\CityService;
use App\Models\CityTaxRule;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class CityController extends Controller
{
    protected CityService $cityService;

    public function __construct(CityService $cityService)
    {
        $this->cityService = $cityService;
    }

    
    public function index(): JsonResponse
    {
        try {
            $cities = City::where('status', 1) // 1 = active, 0 = inactive
                ->select([
                    'id',
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
                    'night_charge_multiplier',
                    'night_start_time',
                    'night_end_time',
                ])
                ->orderBy('name')
                ->get();

            $taxRulesByCity = CityTaxRule::query()
                ->whereIn('city_id', $cities->pluck('id'))
                ->active()
                ->ordered()
                ->get()
                ->groupBy('city_id');

            $cities = $cities->map(function ($city) use ($taxRulesByCity) {
                $cityTaxRules = $taxRulesByCity->get($city->id, collect());
                $totalTaxRate = $cityTaxRules->sum('tax_rate');

                return [
                    'id' => (string) $city->id,
                    'name' => $city->name ?? '',
                    'state' => $city->state ?? '',
                    'country' => $city->country ?? '',
                    'latitude' => (string) ($city->latitude ?? ''),
                    'longitude' => (string) ($city->longitude ?? ''),
                    'status' => (string) ($city->status ? '1' : '0'),
                    'timezone' => $city->timezone ?? '',
                    'currency' => $city->currency ?? '',
                    'service_start_time' => $city->service_start_time ? $city->service_start_time->format('H:i:s') : '',
                    'service_end_time' => $city->service_end_time ? $city->service_end_time->format('H:i:s') : '',
                    'base_distance' => (string) ($city->base_distance ?? ''),
                    'base_price' => (string) ($city->base_price ?? ''),
                    'price_per_km' => (string) ($city->price_per_km ?? ''),
                    'price_per_minute' => (string) ($city->price_per_minute ?? ''),
                    'minimum_fare' => (string) ($city->minimum_fare ?? ''),
                    'cancellation_charge' => (string) ($city->cancellation_charge ?? ''),
                    'waiting_charge_per_minute' => (string) ($city->waiting_charge_per_minute ?? ''),
                    'waiting_time_limit' => (string) ($city->waiting_time_limit ?? ''),
                    'commission_rate' => '',
                    'tax_rate' => (string) $totalTaxRate,
                    'tax_breakdown' => $cityTaxRules->map(function ($rule) {
                        return [
                            'name' => $rule->tax_name,
                            'rate' => (string) $rule->tax_rate,
                        ];
                    })->values()->toArray(),
                    'night_charge_multiplier' => (string) ($city->night_charge_multiplier ?? ''),
                    'night_start_time' => $city->night_start_time ? $city->night_start_time->format('H:i:s') : '',
                    'night_end_time' => $city->night_end_time ? $city->night_end_time->format('H:i:s') : '',
                    'is_service_available' => (string) ($city->isServiceAvailable() ? '1' : '0'),
                    'is_night_charge_applicable' => (string) ($city->isNightChargeApplicable() ? '1' : '0'),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Cities list retrieved successfully',
                'data' => [
                    'total_cities' => (string) $cities->count(),
                    'cities' => $cities,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch cities',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function nearest(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
                'max_distance' => ['nullable', 'numeric', 'min:0'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        // Use demo city in demo mode
        if (\App\Services\DemoModeService::isEnabled()) {
            $demoCity = \App\Services\DemoModeService::getDemoCity();
            if ($demoCity) {
                return response()->json([
                    'success' => true,
                    'message' => 'Nearest city found successfully',
                    'data' => [
                        'city' => $demoCity,
                    ],
                ]);
            }
        }

        try {
            $city = $this->cityService->getNearestCity(
                $request->latitude,
                $request->longitude,
                $request->max_distance ?? 50
            );

            if (!$city) {
                return response()->json([
                    'success' => false,
                    'message' => 'No city found within the specified distance',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Nearest city found successfully',
                'data' => [
                    'city' => $city,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to find nearest city',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function checkServiceability(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'city_id' => ['required', 'integer', 'exists:cities,id'],
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        // Always return serviceable in demo mode
        if (\App\Services\DemoModeService::isEnabled()) {
            return response()->json([
                'success' => true,
                'message' => 'Serviceability check completed',
                'data' => [
                    'is_serviceable' => true,
                ],
            ]);
        }

        try {
            $isServiceable = $this->cityService->isLocationServiceable(
                $request->city_id,
                $request->latitude,
                $request->longitude
            );

            return response()->json([
                'success' => true,
                'message' => 'Serviceability check completed',
                'data' => [
                    'is_serviceable' => $isServiceable,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check serviceability',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function availableRideTypes(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'city_id' => ['required', 'integer', 'exists:cities,id'],
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        // In demo mode, return only taxi ride type
        if (\App\Services\DemoModeService::isEnabled()) {
            $demoRideTypeId = \App\Services\DemoModeService::getDemoRideTypeId();
            if ($demoRideTypeId) {
                $taxiRideType = \App\Models\RideType::find($demoRideTypeId);
                if ($taxiRideType) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Available ride types retrieved successfully',
                        'data' => [
                            'ride_types' => [$taxiRideType],
                        ],
                    ]);
                }
            }
        }

        try {
            $rideTypes = $this->cityService->getAvailableRideTypes(
                $request->city_id,
                $request->latitude,
                $request->longitude
            );

            return response()->json([
                'success' => true,
                'message' => 'Available ride types retrieved successfully',
                'data' => [
                    'ride_types' => $rideTypes,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch available ride types',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function findNearbyDrivers(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'city_id' => ['required', 'integer', 'exists:cities,id'],
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
                'ride_type_id' => ['required', 'integer', 'exists:ride_types,id'],
                'radius' => ['nullable', 'integer', 'min:1000', 'max:10000'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        // Return demo drivers in demo mode
        if (\App\Services\DemoModeService::isEnabled()) {
            return response()->json([
                'success' => true,
                'message' => 'Nearby drivers found successfully',
                'data' => [
                    'drivers' => \App\Services\DemoModeService::getDemoNearbyDrivers(),
                ],
            ]);
        }

        try {
            $drivers = $this->cityService->findNearbyDrivers(
                $request->city_id,
                $request->latitude,
                $request->longitude,
                $request->ride_type_id,
                $request->radius ?? 5000
            );

            return response()->json([
                'success' => true,
                'message' => 'Nearby drivers found successfully',
                'data' => [
                    'drivers' => $drivers,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to find nearby drivers',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
