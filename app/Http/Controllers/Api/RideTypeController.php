<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RideType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RideTypeController extends Controller
{
    
    public function index(Request $request): JsonResponse
    {
        try {
            $rideTypes = RideType::active()
                ->ordered()
                ->get()
                ->map(function ($rideType) {
                    return [
                        'id' => (string) $rideType->id,
                        'name' => (string) $rideType->name,
                        'code' => (string) $rideType->code,
                        'description' => $rideType->description ?? "",
                        'icon' => $this->getRideTypeIconUrl($rideType->icon),
                        'capacity' => (string) $rideType->capacity,
                        'base_distance' => (string) $rideType->base_distance,
                        'base_price' => (string) $rideType->base_price,
                        'price_per_km' => (string) $rideType->price_per_km,
                        'price_per_minute' => (string) $rideType->price_per_minute,
                        'minimum_fare' => (string) $rideType->minimum_fare,
                        'cancellation_charge' => (string) $rideType->cancellation_charge,
                        'waiting_charge_per_minute' => (string) $rideType->waiting_charge_per_minute,
                        'waiting_time_limit' => (string) $rideType->waiting_time_limit,
                        'status' => $rideType->status ? "1" : "0",
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Ride types retrieved successfully',
                'data' => [
                    'ride_types' => $rideTypes,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch ride types',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $rideType = RideType::active()->find($id);

            if (!$rideType) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ride type not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Ride type retrieved successfully',
                'data' => [
                    'ride_type' => [
                        'id' => (string) $rideType->id,
                        'name' => (string) $rideType->name,
                        'code' => (string) $rideType->code,
                        'description' => $rideType->description ?? "",
                        'icon' => $this->getRideTypeIconUrl($rideType->icon),
                        'capacity' => (string) $rideType->capacity,
                        'base_distance' => (string) $rideType->base_distance,
                        'base_price' => (string) $rideType->base_price,
                        'price_per_km' => (string) $rideType->price_per_km,
                        'price_per_minute' => (string) $rideType->price_per_minute,
                        'minimum_fare' => (string) $rideType->minimum_fare,
                        'cancellation_charge' => (string) $rideType->cancellation_charge,
                        'waiting_charge_per_minute' => (string) $rideType->waiting_charge_per_minute,
                        'waiting_time_limit' => (string) $rideType->waiting_time_limit,
                        'status' => $rideType->status ? "1" : "0",
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch ride type',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    protected function getRideTypeIconUrl(?string $icon): string
    {
        if (empty($icon)) {
            return '';
        }

        if (filter_var($icon, FILTER_VALIDATE_URL)) {
            return $icon;
        }

        return url('storage/' . $icon);
    }
}
