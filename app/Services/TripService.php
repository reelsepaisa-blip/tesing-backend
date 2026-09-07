<?php

namespace App\Services;

use App\Enums\BookingState;
use App\Models\Booking;
use App\Models\DriverLocation;
use App\Models\User;
use App\Services\RealTimeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TripService
{
    protected DriverMatchingService $driverMatching;
    protected NotificationService $notification;
    protected RealTimeService $realTimeService;

    public function __construct(DriverMatchingService $driverMatching, NotificationService $notification, RealTimeService $realTimeService)
    {
        $this->driverMatching = $driverMatching;
        $this->notification = $notification;
        $this->realTimeService = $realTimeService;
    }

    public function generateTripCode(): string
    {
        do {
            $code = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        } while (Booking::where('trip_code', $code)->exists());

        return $code;
    }

    public function startDriverSearch(Booking $booking): bool
    {
        if (!$booking->canTransitionTo(BookingState::SEARCHING)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot start driver search from current status.'],
            ]);
        }

        $booking->update(['status' => BookingState::SEARCHING]);

        $this->driverMatching->findDriversForBooking($booking);

        Cache::put("booking_search_{$booking->id}", true, now()->addMinutes(5));

        return true;
    }

    public function assignDriver(Booking $booking, User $driver): bool
    {
        if (!$booking->canTransitionTo(BookingState::ACCEPTED)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot assign driver from current status.'],
            ]);
        }

        $oldStatus = $booking->status;

        $tripCode = $this->generateTripCode();

        $booking->update([
            'driver_id' => $driver->id,
            'status' => BookingState::ACCEPTED,
            'trip_code' => $tripCode,
        ]);

        $this->realTimeService->broadcastTripStatusChange($booking, $oldStatus, BookingState::ACCEPTED->value, [
            'driver_id' => $driver->id,
            'driver_name' => $driver->name,
            'trip_code' => $tripCode,
        ]);

        $this->notification->driverAssigned($booking);

        return true;
    }

    public function driverArrived(Booking $booking): bool
    {
        if (!$booking->canTransitionTo(BookingState::ARRIVED)) {
            throw ValidationException::withMessages([
                'status' => ['Driver cannot mark as arrived from current status.'],
            ]);
        }

        $oldStatus = $booking->status;

        $booking->update([
            'status' => BookingState::ARRIVED,
            'driver_arrival_time' => now(),
        ]);

        $this->realTimeService->broadcastTripStatusChange($booking, $oldStatus, BookingState::ARRIVED->value, [
            'driver_arrival_time' => now()->toISOString(),
        ]);

        $this->notification->driverArrived($booking);

        return true;
    }

    public function startTrip(Booking $booking, string $tripCode): bool
    {
        if (!$booking->canTransitionTo(BookingState::STARTED)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot start trip from current status.'],
            ]);
        }

        if ($booking->trip_code !== $tripCode) {
            throw ValidationException::withMessages([
                'trip_code' => ['Invalid trip code.'],
            ]);
        }

        $oldStatus = $booking->status;

        $booking->update([
            'status' => BookingState::STARTED,
            'started_at' => now(),
            'pickup_time' => now(),
        ]);

        $this->realTimeService->broadcastTripStatusChange($booking, $oldStatus, BookingState::STARTED->value, [
            'started_at' => now()->toISOString(),
            'pickup_time' => now()->toISOString(),
        ]);

        $this->startTripTracking($booking);

        $this->notification->tripStarted($booking);

        return true;
    }

    public function completeTrip(Booking $booking, array $tripData): bool
    {
        if (!$booking->canTransitionTo(BookingState::COMPLETED)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot complete trip from current status.'],
            ]);
        }

        $finalFare = $this->calculateFinalFare($booking, $tripData);
        $calculatedTotal = $finalFare['total'];
        $finalTotal = max($calculatedTotal, (float) ($booking->estimated_fare ?? 0));

        $baseFare = $finalFare['base_fare'];
        $subtotal = $finalFare['subtotal'];

        // If we are using the estimated fare, adjust the base_fare and subtotal to match
        if ($finalTotal > $calculatedTotal) {
            $fareAdjustment = $finalTotal - $calculatedTotal;
            $baseFare += $fareAdjustment;
            $subtotal += $fareAdjustment;
        }

        $booking->update([
            'status' => BookingState::COMPLETED,
            'completed_at' => now(),
            'dropoff_time' => now(),
            'actual_distance' => $tripData['actual_distance'],
            'actual_duration' => $tripData['actual_duration'],
            'total_waiting_time' => $tripData['waiting_time'] ?? 0,
            'final_fare' => $finalTotal,
            'base_fare' => $baseFare,
            'subtotal' => $subtotal,
            'distance_fare' => $finalFare['distance_fare'],
            'time_fare' => $finalFare['time_fare'],
            'waiting_charge' => $finalFare['waiting_charge'],
            'night_charge' => $finalFare['night_charge'],
            'surge_amount' => $finalFare['surge_amount'],
            'total_amount' => $finalTotal,
        ]);

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

        $this->processPayment($booking);

        $this->notification->tripCompleted($booking);

        return true;
    }

    protected function startTripTracking(Booking $booking): void
    {
        Cache::put("trip_tracking_{$booking->id}", [
            'started_at' => now(),
            'driver_id' => $booking->driver_id,
            'user_id' => $booking->user_id,
        ], now()->addHours(2));
    }

    public function updateDriverLocation(Booking $booking, float $latitude, float $longitude): bool
    {
        if ($booking->status !== BookingState::STARTED) {
            return false;
        }

        $driver = User::find($booking->driver_id);
        if ($driver && $this->isAutogeneratedDriver($driver)) {
            return false;
        }

        DriverLocation::updateOrCreate(
            ['driver_id' => $booking->driver_id],
            [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'updated_at' => now(),
            ]
        );

        $this->notification->driverLocationUpdated($booking, $latitude, $longitude);

        return true;
    }

    /**
     * Check if driver is autogenerated
     * Autogenerated drivers have email pattern: {cityname}driver@etaxi.com
     * 
     * @param User $driver
     * @return bool
     */
    private function isAutogeneratedDriver(User $driver): bool
    {
        if (!$driver->email) {
            return false;
        }

        // Check if email matches pattern: {cityname}driver@etaxi.com
        // Pattern: any characters followed by "driver@etaxi.com"
        $pattern = '/^.+driver@etaxi\.com$/i';
        
        if (preg_match($pattern, $driver->email)) {
            return true;
        }

        return false;
    }

    protected function calculateFinalFare(Booking $booking, array $tripData): array
    {
        $fareService = app(FareService::class);

        return $fareService->calculateFinalFare([
            'ride_type_id' => $booking->ride_type_id,
            'city_id' => $booking->city_id,
            'actual_distance' => $tripData['actual_distance'],
            'actual_duration' => $tripData['actual_duration'],
            'waiting_time' => $tripData['waiting_time'] ?? 0,
            'base_fare' => $booking->base_fare,
            'surge_multiplier' => $booking->surge_multiplier,
        ]);
    }

    protected function processPayment(Booking $booking): void
    {
        $walletService = app(WalletService::class);

        switch ($booking->payment_method) {
            case 'wallet':
                $walletService->deductFromWallet($booking->user, $booking->total_amount, "Trip payment for booking #{$booking->booking_code}");
                $booking->update(['payment_status' => 'paid', 'wallet_amount' => $booking->total_amount]);
                break;

            case 'cash':
                $booking->update(['payment_status' => 'pending', 'cash_amount' => $booking->total_amount]);
                break;

            case 'online':
                $booking->update(['payment_status' => 'pending']);
                break;
        }
    }

    public function getTripStatus(Booking $booking): array
    {
        $data = [
            'booking_id' => $booking->id,
            'booking_code' => $booking->booking_code,
            'status' => $booking->status,
            'status_label' => $this->getStatusLabel($booking->status),
            'pickup_address' => $booking->pickup_address,
            'dropoff_address' => $booking->dropoff_address,
            'estimated_distance' => $booking->estimated_distance,
            'estimated_duration' => $booking->estimated_duration,
            'estimated_fare' => $booking->estimated_fare,
            'time_info' => $this->getTimeInfo($booking),
        ];

        if ($booking->driver) {
            $data['driver'] = [
                'id' => $booking->driver->id,
                'name' => $booking->driver->name,
                'phone' => $booking->driver->phone,
                'profile_photo' => $this->getImageUrl($booking->driver->profile_photo),
                'vehicle' => $booking->driver->vehicles->first()?->only(['model', 'registration_number', 'color']),
            ];
        }

        if ($booking->status === BookingState::ACCEPTED || $booking->status === BookingState::ARRIVED) {
            $data['trip_code'] = $booking->trip_code;
        }

        if ($booking->status === BookingState::STARTED) {
            $data['started_at'] = $booking->started_at;
            $data['actual_distance'] = $booking->actual_distance;
            $data['actual_duration'] = $booking->actual_duration;
        }

        if ($booking->status === BookingState::COMPLETED) {
            $data['completed_at'] = $booking->completed_at;
            $data['final_fare'] = $booking->final_fare;
            $data['payment_status'] = $booking->payment_status;
        }

        return $data;
    }

    private function getTimeInfo(Booking $booking): array
    {
        return [
            'total_trip_duration' => $this->calculateTotalTripDuration($booking),
            'waiting_time' => $booking->waiting_time ?? 0,
            'total_waiting_time' => $booking->total_waiting_time ?? 0,
            'driver_arrival_time' => $booking->driver_arrival_time ? $booking->driver_arrival_time->toISOString() : '',
            'pickup_time' => $booking->pickup_time ? $booking->pickup_time->toISOString() : '',
            'dropoff_time' => $booking->dropoff_time ? $booking->dropoff_time->toISOString() : '',
            'time_breakdown' => $this->getTimeBreakdown($booking),
        ];
    }

    private function calculateTotalTripDuration(Booking $booking): int
    {
        if (!$booking->started_at || !$booking->completed_at) {
            return 0;
        }

        return $booking->started_at->diffInMinutes($booking->completed_at);
    }

    private function getTimeBreakdown(Booking $booking): array
    {
        $breakdown = [
            'estimated_vs_actual' => [
                'estimated_duration' => $booking->estimated_duration ?? 0,
                'actual_duration' => $booking->actual_duration ?? 0,
                'duration_difference' => 0,
            ],
            'waiting_times' => [
                'driver_waiting_time' => $booking->waiting_time ?? 0,
                'total_waiting_time' => $booking->total_waiting_time ?? 0,
            ],
            'trip_phases' => [
                'booking_to_driver_assigned' => $this->calculatePhaseDuration($booking->created_at, $booking->driver_arrival_time),
                'driver_arrival_to_pickup' => $this->calculatePhaseDuration($booking->driver_arrival_time, $booking->pickup_time),
                'pickup_to_dropoff' => $this->calculatePhaseDuration($booking->pickup_time, $booking->dropoff_time),
                'total_trip_time' => $this->calculateTotalTripDuration($booking),
            ]
        ];

        if ($booking->estimated_duration && $booking->actual_duration) {
            $breakdown['estimated_vs_actual']['duration_difference'] = $booking->actual_duration - $booking->estimated_duration;
        }

        return $breakdown;
    }

    private function calculatePhaseDuration($startTime, $endTime): int
    {
        if (!$startTime || !$endTime) {
            return 0;
        }

        if ($startTime instanceof \Carbon\Carbon && $endTime instanceof \Carbon\Carbon) {
            return $startTime->diffInMinutes($endTime);
        }

        return 0;
    }

    private function getImageUrl($imagePath): string
    {
        if (!$imagePath) {
            return '';
        }

        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            return $imagePath;
        }

        return asset('storage/' . $imagePath);
    }

    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Pending',
            'searching' => 'Searching for Driver',
            'accepted' => 'Driver Assigned',
            'arrived' => 'Driver Arrived',
            'started' => 'Trip Started',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'no_driver_found' => 'No Driver Found',
            'driver_cancelled' => 'Driver Cancelled',
            'user_cancelled' => 'User Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
