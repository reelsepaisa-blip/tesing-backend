<?php

namespace App\Services;

use App\Enums\BookingState;
use App\Models\Booking;
use App\Models\City;
use App\Models\RideType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    protected FareService $fareService;
    protected CityService $cityService;
    protected UserDebtService $userDebtService;

    public function __construct(FareService $fareService, CityService $cityService, UserDebtService $userDebtService)
    {
        $this->fareService = $fareService;
        $this->cityService = $cityService;
        $this->userDebtService = $userDebtService;
    }

    public function createBooking(array $data, User $user): Booking
    {
        if (!$this->cityService->isLocationServiceable(
            $data['city_id'],
            $data['pickup_latitude'],
            $data['pickup_longitude']
        )) {
            throw ValidationException::withMessages([
                'pickup_location' => ['Pickup location is not serviceable.'],
            ]);
        }

        if (!$this->cityService->isDropAllowed(
            $data['city_id'],
            $data['dropoff_latitude'],
            $data['dropoff_longitude']
        )) {
            throw ValidationException::withMessages([
                'dropoff_location' => ['Drop location is not serviceable.'],
            ]);
        }

        $fareEstimate = $this->fareService->calculateEstimatedFare([
            'ride_type_id' => $data['ride_type_id'],
            'city_id' => $data['city_id'],
            'pickup_latitude' => $data['pickup_latitude'],
            'pickup_longitude' => $data['pickup_longitude'],
            'distance' => $data['estimated_distance'],
            'duration' => $data['estimated_duration'],
        ]);

        if (isset($data['promo_code'])) {
            $fareEstimate = $this->fareService->applyPromotion($fareEstimate, $data['promo_code']);
        }

        return DB::transaction(function () use ($data, $user, $fareEstimate) {
            $booking = Booking::create([
                'booking_code' => $this->generateBookingCode(),
                'user_id' => $user->id,
                'ride_type_id' => $data['ride_type_id'],
                'city_id' => $data['city_id'],
                'pickup_latitude' => $data['pickup_latitude'],
                'pickup_longitude' => $data['pickup_longitude'],
                'pickup_address' => $data['pickup_address'],
                'dropoff_latitude' => $data['dropoff_latitude'],
                'dropoff_longitude' => $data['dropoff_longitude'],
                'dropoff_address' => $data['dropoff_address'],
                'estimated_distance' => $data['estimated_distance'],
                'estimated_duration' => $data['estimated_duration'],
                'estimated_fare' => $fareEstimate['total'],
                'base_fare' => $fareEstimate['base_fare'],
                'distance_fare' => $fareEstimate['distance_fare'],
                'time_fare' => $fareEstimate['time_fare'],
                'surge_multiplier' => $fareEstimate['surge_multiplier'],
                'surge_amount' => $fareEstimate['surge_amount'],
                'night_charges_multiplier' => $fareEstimate['night_charges_multiplier'],
                'night_charges_amount' => $fareEstimate['night_charges_amount'],
                'booking_fee' => $fareEstimate['booking_fee'],
                'tax_rate' => $fareEstimate['tax_rate'],
                'tax_amount' => $fareEstimate['tax_amount'],
                'promo_code' => $data['promo_code'] ?? null,
                'promo_discount' => 0,  // Will be updated when promo is applied
                'payment_method' => $data['payment_method'],
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'status' => BookingState::PENDING,
                'meta_data' => $data['meta_data'] ?? null,
            ]);

            if (!isset($data['scheduled_at'])) {
                $this->generateAndStoreOtp($booking);
            }

            Event::dispatch('booking.created', $booking);

            return $booking;
        });
    }

    public function startDriverSearch(Booking $booking): void
    {
        if (!$booking->status->canTransitionTo(BookingState::SEARCHING)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot start driver search from current status.'],
            ]);
        }

        DB::transaction(function () use ($booking) {
            $booking->update(['status' => BookingState::SEARCHING]);
            Event::dispatch('booking.searching', $booking);
        });
    }

    public function acceptBooking(Booking $booking, User $driver): void
    {
        if (!$booking->status->canTransitionTo(BookingState::ACCEPTED)) {
            throw ValidationException::withMessages([
                'status' => ['Booking cannot be accepted in current status.'],
            ]);
        }

        if (!$driver->hasRole('driver') || !$driver->is_active || !$driver->driverProfile?->is_online) {
            throw ValidationException::withMessages([
                'driver' => ['Invalid or inactive driver.'],
            ]);
        }

        DB::transaction(function () use ($booking, $driver) {
            $booking->update([
                'driver_id' => $driver->id,
                'status' => BookingState::ACCEPTED,
                'accepted_at' => now(),
            ]);

            $driver->driverProfile->update(['is_available' => false]);

            Event::dispatch('booking.accepted', $booking);
        });
    }

    public function markDriverArrived(Booking $booking): void
    {
        if (!$booking->status->canTransitionTo(BookingState::ARRIVED)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot mark arrival in current status.'],
            ]);
        }

        DB::transaction(function () use ($booking) {
            $booking->update([
                'status' => BookingState::ARRIVED,
                'arrived_at' => now(),
            ]);

            $this->clearBookingWaypointCache($booking);

            Event::dispatch('booking.driver_arrived', $booking);
        });
    }

    public function startTrip(Booking $booking, string $otp): void
    {
        if (!$booking->status->canTransitionTo(BookingState::STARTED)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot start trip in current status.'],
            ]);
        }

        if (!$this->verifyStartOtp($booking, $otp)) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid OTP.'],
            ]);
        }

        DB::transaction(function () use ($booking) {
            $booking->update([
                'status' => BookingState::STARTED,
                'started_at' => now(),
                'otp_verified_at' => now(),
            ]);

            Event::dispatch('booking.started', $booking);
        });
    }

    public function completeTrip(Booking $booking, array $data): void
    {
        if (!$booking->status->canTransitionTo(BookingState::COMPLETED)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot complete trip in current status.'],
            ]);
        }

        DB::transaction(function () use ($booking, $data) {
            $finalFare = $this->calculateFinalFare($booking, $data);
            $calculatedTotal = $finalFare['total'];
            $finalTotal = max($calculatedTotal, (float) ($booking->estimated_fare ?? 0));

            $baseFare = $booking->base_fare;
            $subtotal = $booking->subtotal;

            if ($finalTotal > $calculatedTotal) {
                $fareAdjustment = $finalTotal - $calculatedTotal;
                $baseFare += $fareAdjustment;
                $subtotal += $fareAdjustment;
            }

            $booking->update([
                'status' => BookingState::COMPLETED,
                'completed_at' => now(),
                'actual_distance' => $data['actual_distance'],
                'actual_duration' => $data['actual_duration'],
                'waiting_time' => $data['waiting_time'] ?? 0,
                'waiting_charge' => $finalFare['waiting_charge'] ?? 0,
                'total_amount' => $finalTotal,
                'base_fare' => $baseFare,
                'subtotal' => $subtotal,
                'driver_commission' => $finalFare['driver_commission'],
                'route_snapshot' => $data['route_snapshot'] ?? null,
            ]);

            $this->clearBookingWaypointCache($booking);

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

            $booking->driver->driverProfile->update(['is_available' => true]);

            app(\App\Services\ReferralService::class)->handleBookingCompleted($booking);

            Event::dispatch('booking.completed', $booking);
        });
    }

    public function cancelBooking(Booking $booking, array $data): void
    {
        $currentStatus = $booking->status instanceof BookingState
            ? $booking->status
            : BookingState::from($booking->status);

        if (!$currentStatus->canTransitionTo(BookingState::CANCELLED)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot cancel booking in current status.'],
            ]);
        }

        DB::transaction(function () use ($booking, $data, $currentStatus) {
            $charge = 0;
            $status = $currentStatus;

            $shouldApplyCharge = in_array($status, [
                BookingState::ACCEPTED,
                BookingState::ARRIVED,
                BookingState::STARTED,
            ], true);

            if ($shouldApplyCharge) {
                // Refresh booking to ensure we have latest data with proper casts
                $booking = $booking->fresh();
                
                // Free cancellation window should start from when driver accepts the ride
                // Only use accepted_at, not created_at as fallback
                $referenceTime = $booking->accepted_at;
                $bookingDuration = 0;

                if ($referenceTime) {
                    // Ensure accepted_at is a Carbon instance (handle case where it might be a string)
                    // This can happen if the model wasn't properly cast or if accessed as raw attribute
                    if (!($referenceTime instanceof Carbon)) {
                        try {
                            $referenceTime = Carbon::parse($referenceTime);
                        } catch (\Exception $e) {
                            $referenceTime = null;
                        }
                    }
                    
                    if ($referenceTime instanceof Carbon) {
                        $bookingDuration = (int) ceil(
                            $referenceTime->diffInMilliseconds(now()) / 1000
                        );
                    }
                } else {
                    // If accepted_at is null but status is ACCEPTED/ARRIVED/STARTED,
                    // this shouldn't happen, but if it does, set duration to 0
                    // which will be treated as within free window
                    
                }
                $tripAmount = $booking->subtotal ?? 0;  // Use subtotal as trip amount base


                $chargeData = $this->fareService->getCancellationCharge([
                    'ride_type_id' => $booking->ride_type_id,
                    'city_id' => $booking->city_id,
                    'booking_duration' => $bookingDuration,
                    'trip_amount' => $tripAmount,
                    'booking_status' => $status->value,
                ]);

                $charge = $chargeData['charge'] ?? 0;

            } else {
                
            }

            // Ensure cancellation_charge is always set to a numeric value (not NULL)
            // This is important for debt calculation even if charge is 0
            $cancellationCharge = (float) $charge;
            
            $booking->update([
                'status' => BookingState::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_type' => $data['cancelled_by_type'] ?? null,
                'cancelled_by_id' => $data['cancelled_by_id'] ?? null,
                'cancellation_reason' => $data['reason'],
                'cancellation_charge' => $cancellationCharge, // Always numeric, never NULL
            ]);

            $this->clearBookingWaypointCache($booking);

            if ($booking->promo_code) {
                \App\Models\PromoUsage::where('booking_id', $booking->id)->delete();
            }

            $this->userDebtService->recordCancellationDebt($booking->fresh(), $data['reason'] ?? null);

            if ($booking->driver) {
                $booking->driver->driverProfile->update(['is_available' => true]);
            }

            Event::dispatch('booking.cancelled', $booking);
        });
    }

    public function expireBooking(Booking $booking): void
    {
        if (!$booking->status->canTransitionTo(BookingState::EXPIRED)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot expire booking in current status.'],
            ]);
        }

        DB::transaction(function () use ($booking) {
            $booking->update([
                'status' => BookingState::EXPIRED,
                'expired_at' => now(),
            ]);

            Event::dispatch('booking.expired', $booking);
        });
    }

    protected function generateBookingCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Booking::where('booking_code', $code)->exists());

        return $code;
    }

    protected function generateAndStoreOtp(Booking $booking): void
    {
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $booking->update(['start_otp' => $otp]);
    }

    protected function verifyStartOtp(Booking $booking, string $otp): bool
    {
        return $booking->start_otp === $otp;
    }

    protected function calculateFinalFare(Booking $booking, array $data): array
    {
        $fare = $this->fareService->calculateEstimatedFare([
            'ride_type_id' => $booking->ride_type_id,
            'city_id' => $booking->city_id,
            'pickup_latitude' => $booking->pickup_latitude,
            'pickup_longitude' => $booking->pickup_longitude,
            'distance' => $data['actual_distance'],
            'duration' => $data['actual_duration'],
        ]);

        if (isset($data['waiting_time']) && $data['waiting_time'] > 0) {
            $waitingCharge = $this->fareService->calculateWaitingCharge(
                $booking->rideType,
                $data['waiting_time']
            );
            $fare['waiting_charge'] = $waitingCharge;
            $fare['total'] += $waitingCharge;
        }

        $commission = $this->fareService->calculateDriverCommission(
            $fare['total'],
            $booking->driver->driverProfile->commission_rate
        );
        $fare['driver_commission'] = $commission['commission'];
        $fare['driver_earning'] = $commission['driver_earning'];

        return $fare;
    }

    /**
     * Clear waypoint cache for a booking when status changes
     * This ensures stale route data doesn't persist after booking completion/cancellation/arrival
     */
    protected function clearBookingWaypointCache(Booking $booking): void
    {
        Cache::forget("booking_{$booking->id}_route_waypoints");
        Cache::forget("booking_{$booking->id}_waypoint_index");
    }
}
