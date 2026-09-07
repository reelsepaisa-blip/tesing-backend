<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FareService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class FareController extends Controller
{
    protected FareService $fareService;

    public function __construct(FareService $fareService)
    {
        $this->fareService = $fareService;
    }

    
    public function estimate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ride_type_id' => ['required', 'integer', 'exists:ride_types,id'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'pickup_latitude' => ['required', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['required', 'numeric', 'between:-180,180'],
            'dropoff_latitude' => ['required', 'numeric', 'between:-90,90'],
            'dropoff_longitude' => ['required', 'numeric', 'between:-180,180'],
            'distance' => ['required', 'numeric', 'min:0'],
            'duration' => ['required', 'numeric', 'min:0'],
            'promo_code' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $fare = $this->fareService->calculateEstimatedFare($validator->validated());

            if ($request->has('promo_code')) {
                $fare = $this->fareService->applyPromotion($fare, $request->promo_code);
            }

            return response()->json([
                'fare' => $fare,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to calculate fare',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function cancellationCharge(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ride_type_id' => ['required', 'integer', 'exists:ride_types,id'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'booking_duration' => ['required', 'integer', 'min:0'],
            'trip_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $validated = $validator->validated();
            if (!isset($validated['trip_amount'])) {
                $validated['trip_amount'] = 0;
            }

            $charge = $this->fareService->getCancellationCharge($validated);

            return response()->json([
                'cancellation_charge' => $charge,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to get cancellation charge',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function waitingCharge(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ride_type_id' => ['required', 'integer', 'exists:ride_types,id'],
            'waiting_time' => ['required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $rideType = \App\Models\RideType::findOrFail($request->ride_type_id);
            $charge = $this->fareService->calculateWaitingCharge(
                $rideType,
                $request->waiting_time
            );

            return response()->json([
                'waiting_charge' => round($charge, 2),
                'currency' => $rideType->city->currency ?? 'INR',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to calculate waiting charge',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function availablePromotions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ride_type_id' => ['required', 'integer', 'exists:ride_types,id'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'estimated_fare' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $promotions = $this->fareService->getAvailablePromotions($validator->validated());

            return response()->json([
                'promotions' => $promotions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to get available promotions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function driverCommission(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'trip_amount' => ['required', 'numeric', 'min:0'],
            'commission_rate' => ['required', 'numeric', 'between:0,100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $commission = $this->fareService->calculateDriverCommission(
                $request->trip_amount,
                $request->commission_rate
            );

            return response()->json($commission);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to calculate driver commission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
