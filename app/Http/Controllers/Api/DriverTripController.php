<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\User;

use Carbon\Carbon;

class DriverTripController extends Controller
{
    
    public function getCurrentTrip(Request $request)
    {
        $driver = auth()->user();

        $currentTrip = Booking::where('driver_id', $driver->id)
            ->whereIn('status', ['accepted', 'driver_arrived', 'started'])
            ->with(['user', 'rideType'])
            ->first();

        if (!$currentTrip) {
            return response()->json([
                'success' => false,
                'message' => 'No active trip found'
            ], 404);
        }

        $tripData = [
            'id' => $currentTrip->id,
            'booking_code' => $currentTrip->booking_code,
            'status' => $currentTrip->status,
            'pickup_address' => $currentTrip->pickup_address,
            'dropoff_address' => $currentTrip->dropoff_address,
            'total_fare' => $currentTrip->total_fare,
            'final_fare' => $currentTrip->final_fare,
            'payment_method' => $currentTrip->payment_method,
            'trip_code' => $currentTrip->trip_code,
            'rider' => [
                'id' => $currentTrip->user->id,
                'name' => $currentTrip->user->name,
                'phone' => $currentTrip->user->phone,
                'rating' => $currentTrip->user->driverProfile->rating ?? 0,
                'profile_photo' => $this->getProfilePhotoUrl($currentTrip->user->profile_photo),
            ],
        ];

        if ($currentTrip->status === 'driver_arrived') {
            $tripData['waiting_time'] = $this->calculateWaitingTime($currentTrip);
            $tripData['free_waiting_time'] = config('app.free_waiting_time', 3);
            $tripData['waiting_charge_per_minute'] = config('app.waiting_charge_per_minute', 2);
        }

        return response()->json([
            'success' => true,
            'data' => $tripData
        ]);
    }

    
    public function arriveAtPickup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|exists:bookings,id',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();
        $booking = Booking::where('id', $request->booking_id)
            ->where('driver_id', $driver->id)
            ->where('status', 'accepted')
            ->firstOrFail();

        try {
            DB::beginTransaction();

            $booking->update([
                'status' => 'driver_arrived',
                'driver_arrival_time' => now(),
            ]);

            $driver->update([
                'last_latitude' => $request->latitude,
                'last_longitude' => $request->longitude,
                'last_location_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Arrived at pickup location',
                'data' => [
                    'status' => 'driver_arrived',
                    'arrival_time' => now()->toISOString(),
                    'trip_code' => $booking->trip_code,
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update arrival status'
            ], 500);
        }
    }

    
    public function startTrip(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|exists:bookings,id',
            'trip_code' => 'required|string|size:4',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();
        $booking = Booking::where('id', $request->booking_id)
            ->where('driver_id', $driver->id)
            ->where('status', 'driver_arrived')
            ->firstOrFail();

        if ($booking->trip_code !== $request->trip_code) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid trip code'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $waitingCharges = $this->calculateWaitingCharges($booking);

            $booking->update([
                'status' => 'started',
                'started_at' => now(),
                'waiting_charge' => $waitingCharges,
                'total_waiting_time' => $this->calculateTotalWaitingTime($booking),
                'final_fare' => $booking->final_fare + $waitingCharges,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Trip started successfully',
                'data' => [
                    'status' => 'started',
                    'started_at' => now()->toISOString(),
                    'waiting_charges' => $waitingCharges,
                    'final_fare' => $booking->final_fare,
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to start trip'
            ], 500);
        }
    }

    
    public function completeTrip(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|exists:bookings,id',
            'actual_distance' => 'required|numeric|min:0',
            'actual_duration' => 'required|integer|min:0',
            'dropoff_latitude' => 'required|numeric|between:-90,90',
            'dropoff_longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();
        $booking = Booking::where('id', $request->booking_id)
            ->where('driver_id', $driver->id)
            ->where('status', 'started')
            ->firstOrFail();

        try {
            DB::beginTransaction();

            $actualDistance = $request->actual_distance;
            $actualDuration = $request->actual_duration;
            
            $tripDurationMinutes = $booking->started_at ? $booking->started_at->diffInMinutes(now()) : 0;
            
            if ($actualDistance <= 0) {
                if ($booking->distance > 0) {
                    $actualDistance = $booking->distance;
                    
                } else {
                    $actualDistance = 1.0;
                    
                }
            }
            
            if ($actualDistance < 1.0) {
                
                $actualDistance = 1.0;
            }
            
            if ($actualDuration <= 0) {
                if ($tripDurationMinutes > 0) {
                    $actualDuration = $tripDurationMinutes;
                    
                } elseif ($booking->duration > 0) {
                    $actualDuration = $booking->duration;
                    
                } else {
                    $actualDuration = 1;
                    
                }
            }
            
            if ($tripDurationMinutes < 1 && $actualDistance < 0.1) {
            }

            $waitingTime = $this->calculateTotalWaitingTime($booking);
            
            
            
            $booking->update([
                'status' => 'completed',
                'completed_at' => now(),
                'actual_distance' => $actualDistance,
                'actual_duration' => $actualDuration,
                'waiting_time' => $waitingTime,
                'dropoff_latitude' => $request->dropoff_latitude,
                'dropoff_longitude' => $request->dropoff_longitude,
            ]);

            $fare = $booking->calculateFare();
            
            $booking->update([
                'final_fare' => $booking->total_amount,
            ]);
            
            $finalFare = $booking->total_amount;

            if ($booking->promo_code) {
                $promoCode = \App\Models\PromoCode::where('code', $booking->promo_code)->first();
                if ($promoCode) {
                    $originalAmount = $booking->total_amount + ($booking->discount_amount ?? 0);
                    $discountAmount = $booking->discount_amount ?? 0;
                    $finalAmount = $booking->total_amount;

                    \App\Models\PromoUsage::updateOrCreate(
                        ['booking_id' => $booking->id],
                        [
                            'promo_code_id' => $promoCode->id,
                            'user_id' => $booking->user_id,
                            'original_amount' => $originalAmount,
                            'discount_amount' => $discountAmount,
                            'final_amount' => $finalAmount,
                            'meta_data' => [
                                'promo_type' => $promoCode->type,
                                'promo_value' => $promoCode->value,
                                'is_referral' => $promoCode->is_referral_code ?? false,
                            ],
                        ]
                    );
                }
            }

            $driver->update([
                'last_latitude' => $request->dropoff_latitude,
                'last_longitude' => $request->dropoff_longitude,
                'last_location_at' => now(),
            ]);

            DB::commit();

            if ($booking->status === 'completed' && $booking->driver_id) {
                try {
                    $incentiveService = app(\App\Services\DriverIncentiveService::class);
                    $incentiveService->processRideCompletion($booking->driver_id, $booking->id);
                } catch (\Exception $e) {
                    // Error processing incentive
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Trip completed successfully',
                'data' => [
                    'status' => 'completed',
                    'completed_at' => now()->toISOString(),
                    'final_fare' => $finalFare,
                    'actual_distance' => $request->actual_distance,
                    'actual_duration' => $request->actual_duration,
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to complete trip'
            ], 500);
        }
    }

    
    private function calculateWaitingTime($booking)
    {
        if (!$booking->driver_arrival_time) {
            return 0;
        }

        $arrivalTime = Carbon::parse($booking->driver_arrival_time);
        $now = Carbon::now();

        return $now->diffInMinutes($arrivalTime);
    }

    
    private function calculateWaitingCharges($booking)
    {
        $freeWaitingTime = config('app.free_waiting_time', 3);
        $waitingChargePerMinute = config('app.waiting_charge_per_minute', 2);

        $totalWaitingTime = $this->calculateWaitingTime($booking);
        $chargeableTime = max(0, $totalWaitingTime - $freeWaitingTime);

        return $chargeableTime * $waitingChargePerMinute;
    }

    
    private function calculateTotalWaitingTime($booking)
    {
        if (!$booking->driver_arrival_time || !$booking->started_at) {
            return 0;
        }

        $arrivalTime = Carbon::parse($booking->driver_arrival_time);
        $startTime = Carbon::parse($booking->started_at);

        return $startTime->diffInMinutes($arrivalTime);
    }

    
    private function calculateFinalFare($booking, $actualDistance, $actualDuration)
    {
        $rideType = $booking->rideType;

        $baseFare = $rideType->base_fare;
        $distanceFare = $actualDistance * $rideType->price_per_km;
        $timeFare = ($actualDuration / 60) * $rideType->price_per_minute;
        $waitingCharges = $booking->waiting_charge ?? 0;

        $subtotal = $baseFare + $distanceFare + $timeFare + $waitingCharges;
        $finalFare = $subtotal - ($booking->discount_amount ?? 0);

        return max($finalFare, $rideType->minimum_fare);
    }

    
    protected function getProfilePhotoUrl(?string $profilePhoto): string
    {
        if (empty($profilePhoto)) {
            return '';
        }

        if (filter_var($profilePhoto, FILTER_VALIDATE_URL)) {
            return $profilePhoto;
        }

        return url('storage/' . $profilePhoto);
    }
}
