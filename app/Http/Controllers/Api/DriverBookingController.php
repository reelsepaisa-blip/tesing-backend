<?php

namespace App\Http\Controllers\Api;

use App\Events\BookingStatusChanged;
use App\Events\DriverAssigned;
use App\Events\DriverCancelledBooking;
use App\Events\LocationUpdated;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\DriverLocation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DriverMatchingService;
use App\Services\NotificationService;
use App\Services\DriverNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use Illuminate\Validation\ValidationException;
use MatanYadaev\EloquentSpatial\Objects\Point;

class DriverBookingController extends Controller
{
    protected $driverMatching;
    protected $notificationService;
    protected $driverNotificationService;

    public function __construct(DriverMatchingService $driverMatching, NotificationService $notificationService, DriverNotificationService $driverNotificationService)
    {
        $this->driverMatching = $driverMatching;
        $this->notificationService = $notificationService;
        $this->driverNotificationService = $driverNotificationService;
    }

    private function formatResponseData($data)
    {
        if (is_null($data)) {
            return '';
        }

        if (is_array($data)) {
            $formatted = [];
            foreach ($data as $key => $value) {
                if (in_array($key, ['driver_requirements', 'vehicle_requirements']) && ($value === null || $value === '')) {
                    $formatted[$key] = null;
                } else {
                    $formatted[$key] = $this->formatResponseData($value);
                }
            }
            return $formatted;
        }

        if (is_object($data)) {
            if (method_exists($data, 'toArray')) {
                $array = $data->toArray();
                return $this->formatResponseData($array);
            }

            if (method_exists($data, 'getAttributes')) {
                $attributes = $data->getAttributes();
                $formatted = [];

                foreach ($attributes as $key => $value) {
                    if (in_array($key, ['driver_requirements', 'vehicle_requirements']) && ($value === null || $value === '')) {
                        $formatted[$key] = null;
                    } else {
                        $formatted[$key] = $this->formatResponseData($value);
                    }
                }

                if (method_exists($data, 'getRelations')) {
                    foreach ($data->getRelations() as $relationName => $relationData) {
                        if ($data instanceof \App\Models\User && ($relationName === 'driverProfile' || $relationName === 'driver_profile')) {
                            continue;
                        }
                        $formatted[$relationName] = $this->formatResponseData($relationData);
                    }
                }

                if ($data instanceof \App\Models\User) {
                    if (isset($formatted['driverProfile'])) {
                        unset($formatted['driverProfile']);
                    }
                    if (isset($formatted['driver_profile'])) {
                        unset($formatted['driver_profile']);
                    }
                }

                return $formatted;
            }
        }

        if ($data === '' || $data === 0 || $data === false) {
            return '';
        }

        return (string) $data;
    }

    public function collectCash(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => ['required', 'integer', 'exists:bookings,id'],
            'cash_amount' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $driver = $request->user();
            if (!$driver) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            $booking = Booking::whereKey($request->booking_id)
                ->where('driver_id', $driver->id)
                ->first();

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this booking',
                ], 403);
            }

            $booking->update([
                'cash_amount' => $request->cash_amount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cash amount collected successfully',
                'data' => [
                    'booking_id' => (string) $booking->id,
                    'cash_amount' => (string) $booking->cash_amount,
                    'total_amount' => (string) ($booking->total_amount ?? ''),
                    'payment_method' => $booking->payment_method ?? '',
                    'updated_at' => $booking->updated_at ? $booking->updated_at->toISOString() : '',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update cash amount: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function updateStatus(Request $request, Booking $booking): JsonResponse
    {
        $booking->load(['user', 'driver', 'rideType', 'pickupZone', 'dropoffZone']);

        $user = Auth::user();

        if ($user && $user->bearer_token) {
            \Illuminate\Support\Facades\Cache::forget('auth:bearer_user:' . str_replace('Bearer_', '', $user->bearer_token));
            $user = $user->fresh();
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }
        $validationRules = [
            'status' => ['required', 'integer', 'min:1', 'max:6'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];

        // In demo mode, accept 6-digit OTP (123456), otherwise 4-digit
        if (\App\Services\DemoModeService::isEnabled()) {
            if ($request->status == 3) {
                $validationRules['otp'] = ['required', 'string', 'size:6'];
            } else {
                $validationRules['otp'] = ['nullable', 'string', 'size:6'];
            }
        } else {
            if ($request->status == 3) {
                $validationRules['otp'] = ['required', 'string', 'size:4'];
            } else {
                $validationRules['otp'] = ['nullable', 'string', 'size:4'];
            }
        }

        if ($request->status == 4) {
            $validationRules['dropoff_latitude'] = ['required', 'numeric', 'between:-90,90'];
            $validationRules['dropoff_longitude'] = ['required', 'numeric', 'between:-180,180'];
            $validationRules['dropoff_address'] = ['required', 'string', 'max:255'];
            $validationRules['total_distance'] = ['required', 'numeric', 'min:0'];
        } else {
            $validationRules['dropoff_latitude'] = ['nullable', 'numeric', 'between:-90,90'];
            $validationRules['dropoff_longitude'] = ['nullable', 'numeric', 'between:-180,180'];
            $validationRules['dropoff_address'] = ['nullable', 'string', 'max:255'];
            $validationRules['total_distance'] = ['nullable', 'numeric', 'min:0'];
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $validationRules);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->validate($validationRules);
        $statusMap = [
            1 => 'accepted',  // Driver accepts booking -> status becomes 'accepted'
            2 => 'arrived',  // Driver arrives at pickup
            3 => 'started',  // Trip starts after OTP verification
            4 => 'completed',  // Trip completed
            5 => 'cancelled',  // Booking cancelled
            6 => 'expired'  // Booking expired
        ];

        $newStatus = $statusMap[$data['status']] ?? null;

        if (!$newStatus) {
            throw ValidationException::withMessages([
                'status' => ['Invalid status code provided.'],
            ]);
        }

        if ($newStatus !== 'accepted' && (int) $booking->driver_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this booking',
            ], 403);
        }

        switch ($newStatus) {
            case 'accepted':  // Driver accepts booking

                if ($user->is_online == 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not eligible to accept rides. Go Online to accept rides.',
                    ], 422);
                }

                $runningRide = Booking::where('driver_id', $user->id)
                    ->whereIn('status', ['accepted', 'arrived', 'started'])
                    ->where('id', '!=', $booking->id)  // Exclude current booking
                    ->first();

                if ($runningRide) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ride is Running So Cant Accept this Ride',
                    ], 422);
                }
                if ($booking->status !== 'searching') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Booking is not available for acceptance. Current status: ' . $booking->status,
                    ], 422);
                }

                if ($booking->driver_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Booking has already been assigned to another driver.',
                    ], 422);
                }

                DB::table('bookings')
                    ->where('id', $booking->id)
                    ->update([
                        'driver_id' => $user->id,
                        'status' => 'accepted',
                        'accepted_at' => now(),
                        'updated_at' => now()
                    ]);

                $booking = $booking->fresh();

                $user->update(['current_booking_id' => $booking->id]);


                break;

            case 'arrived':  // Driver arrives at pickup
                if ($booking->status !== 'accepted') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid booking status for arrived. Must be accepted first.',
                    ], 422);
                }

                DB::table('bookings')
                    ->where('id', $booking->id)
                    ->update([
                        'status' => 'arrived',
                        'driver_arrival_time' => now(),  // Set arrival time to start free waiting time count
                        'updated_at' => now()
                    ]);

                $booking = $booking->fresh();


                break;
            case 'started':  // Trip starts after OTP verification

                if ($request->otp == null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'OTP is required.',
                    ], 422);
                }
                if ($booking->status !== 'arrived') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid booking status for started. Must be arrived first.',
                    ], 422);
                }

                // Accept demo OTP in demo mode
                $isValidOtp = false;
                if (\App\Services\DemoModeService::isEnabled()) {
                    $isValidOtp = \App\Services\DemoModeService::isDemoOtp($request->otp) || $booking->otp === $request->otp;
                } else {
                    $isValidOtp = $booking->otp === $request->otp;
                }

                if (!$isValidOtp) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid OTP provided.',
                    ], 422);
                }

                DB::table('bookings')
                    ->where('id', $booking->id)
                    ->update([
                        'status' => 'started',
                        'started_at' => now(),
                        'updated_at' => now()
                    ]);

                $booking = $booking->fresh();


                break;

            case 'completed':  // Trip completed
                if ($booking->status !== 'started') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid booking status for completed. Must be started first.',
                    ], 422);
                }

                $completedAt = now();

                $distanceProvided = array_key_exists('total_distance', $data);

                if ($distanceProvided) {
                    $actualDistance = (float) $data['total_distance'];
                } else {
                    $actualDistance = 0;

                    if (
                        isset($data['dropoff_latitude']) &&
                        isset($data['dropoff_longitude']) &&
                        $booking->pickup_latitude &&
                        $booking->pickup_longitude
                    ) {
                        $lat1 = (float) $booking->pickup_latitude;
                        $lon1 = (float) $booking->pickup_longitude;
                        $lat2 = (float) $data['dropoff_latitude'];
                        $lon2 = (float) $data['dropoff_longitude'];

                        $earthRadius = 6371;  // Earth's radius in kilometers
                        $dLat = deg2rad($lat2 - $lat1);
                        $dLon = deg2rad($lon2 - $lon1);
                        $a = sin($dLat / 2) * sin($dLat / 2)
                            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
                            * sin($dLon / 2) * sin($dLon / 2);
                        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                        $calculatedDistance = $earthRadius * $c;

                        if ($calculatedDistance > 0) {
                            $actualDistance = round($calculatedDistance, 2);
                        }
                    }

                    if ($actualDistance <= 0) {
                        if ($booking->distance > 0) {
                            $actualDistance = $booking->distance;
                        } else {
                            $actualDistance = 0.1;
                        }
                    }
                }

                $actualDurationSeconds = $booking->started_at->diffInSeconds($completedAt);
                $actualDuration = (int) floor($actualDurationSeconds / 60);  // Only count full minutes

                if ($actualDuration < 0) {
                    $actualDuration = 0;
                }

                if ($actualDuration === 0 && $booking->duration > 0) {
                    // Actual duration is 0 but booking has estimated duration
                }

                $waitingTime = $this->calculateTotalWaitingTime($booking);



                DB::table('bookings')
                    ->where('id', $booking->id)
                    ->update([
                        'status' => 'completed',
                        'dropoff_latitude' => $data['dropoff_latitude'],
                        'dropoff_longitude' => $data['dropoff_longitude'],
                        'dropoff_address' => $data['dropoff_address'],
                        'completed_at' => $completedAt,
                        'actual_distance' => $actualDistance,
                        'actual_duration' => $actualDuration,
                        'waiting_time' => $waitingTime,
                        'updated_at' => now()
                    ]);

                $user = $booking->user;
                if ($user && $user->referred_by && $user->bookings()->completed()->count() === 1) {
                    $referralBonus = \App\Models\ReferralBonus::where('referred_id', $user->id)
                        ->where('status', \App\Models\ReferralBonus::STATUS_PENDING)
                        ->first();
                    if ($referralBonus) {
                        \DB::transaction(function () use ($referralBonus) {
                            if ($referralBonus->markAsCredited()) {
                                $walletService = app(\App\Services\WalletService::class);
                                $referrer = $referralBonus->referrer;
                                $referred = $referralBonus->referred;
                                if ($referrer) {
                                    $walletService->addReferralBonus(
                                        $referrer,
                                        $referralBonus->amount,
                                        $referred->referral_code
                                    );
                                }
                            }
                        });
                    }
                }

                $booking = $booking->fresh();

                $this->calculateAndUpdateFare($booking);
                $this->processTripCompletion($booking);

                $notificationService = app(\App\Services\NotificationService::class);
                $notificationService->tripCompleted($booking);


                break;

            case 'cancelled':  // Booking cancelled
                if (!$booking->canBeCancelledByDriver()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Booking can only be cancelled in accepted or arrived status, before the trip starts.',
                    ], 422);
                }

                if ($request['reason'] == null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cancellation reason is required.',
                    ], 422);
                }

                if (!$data['reason']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cancellation reason is required.',
                    ], 422);
                }
                break;
        }

        try {
            DB::beginTransaction();

            $oldStatus = $booking->status;
            $updateData = ['status' => $newStatus];

            switch ($newStatus) {
                case 'started':
                    $updateData['started_at'] = now();
                    break;

                case 'completed':
                    $updateData['dropoff_latitude'] = $data['dropoff_latitude'];
                    $updateData['dropoff_longitude'] = $data['dropoff_longitude'];
                    $updateData['dropoff_address'] = $data['dropoff_address'];

                    $dropoffLocation = new Point($data['dropoff_latitude'], $data['dropoff_longitude']);
                    $updateData['dropoff_location'] = $dropoffLocation;

                    $actualDistance = $data['total_distance'];
                    $actualDurationSeconds = $booking->started_at->diffInSeconds(now());
                    $actualDuration = (int) floor($actualDurationSeconds / 60);  // Only count full minutes

                    $updateData['completed_at'] = now();
                    $updateData['actual_distance'] = $actualDistance;
                    $updateData['actual_duration'] = $actualDuration;
                    break;

                case 'cancelled':
                    $updateData['cancelled_at'] = now();
                    $updateData['cancellation_reason'] = $data['reason'];
                    $updateData['cancelled_by_type'] = 'App\Models\User';
                    $updateData['cancelled_by_id'] = $user->id;

                    // If driver cancels and status is not 'searching' or 'pending', deduct cancellation charge from driver wallet
                    if ($oldStatus !== 'searching' && $oldStatus !== 'pending') {
                        try {
                            $fareService = app(\App\Services\FareService::class);
                            $walletService = app(\App\Services\WalletService::class);

                            // Calculate cancellation charge
                            $shouldApplyCharge = in_array($oldStatus, ['accepted', 'arrived', 'started'], true);

                            if ($shouldApplyCharge) {
                                $referenceTime = $booking->accepted_at ?? $booking->created_at;
                                $bookingDuration = 0;

                                if ($referenceTime) {
                                    $bookingDuration = (int) ceil(
                                        $referenceTime->diffInMilliseconds(now()) / 1000
                                    );
                                }

                                $tripAmount = $booking->subtotal ?? 0;

                                $chargeData = $fareService->getCancellationCharge([
                                    'ride_type_id' => $booking->ride_type_id,
                                    'city_id' => $booking->city_id,
                                    'booking_duration' => $bookingDuration,
                                    'trip_amount' => $tripAmount,
                                    'booking_status' => $oldStatus,
                                ]);

                                $cancellationCharge = $chargeData['charge'] ?? 0;

                                // Store cancellation charge in booking
                                $updateData['cancellation_charge'] = $cancellationCharge;

                                if ($cancellationCharge > 0) {
                                    // Deduct cancellation charge from driver wallet
                                    $driverWallet = $walletService->ensureWallet($user);

                                    $walletTransaction = $driverWallet->debit(
                                        $cancellationCharge,
                                        \App\Models\WalletTransaction::TYPE_ADJUSTMENT,
                                        "Cancellation fee for booking #{$booking->booking_code}",
                                        [
                                            'booking_id' => $booking->id,
                                            'booking_code' => $booking->booking_code,
                                            'cancellation_charge' => $cancellationCharge,
                                            'old_status' => $oldStatus,
                                            'debited_at' => now()->toDateTimeString(),
                                        ],
                                        null,
                                        true // Allow negative balance
                                    );

                                    $transactionId = 'TXN_' . time() . '_' . rand(1000, 9999);
                                    $walletTransaction->update([
                                        'transection_id' => $transactionId,
                                        'reference_type' => 'App\Models\Booking',
                                        'reference_id' => $booking->id,
                                    ]);
                                }
                            }
                        } catch (\Exception $e) {
                            // Continue with cancellation even if wallet deduction fails
                        }
                    }
                    break;
            }

            if (!in_array($newStatus, ['accepted', 'arrived', 'started', 'completed'])) {
                $booking->update($updateData);
            }

            if (in_array($newStatus, ['completed', 'cancelled'])) {
                $user->current_booking_id = null;
                $user->is_online = true;  // Keep driver online but available
                $user->save();
            }

            // Handle customer debt for cancellation (keep existing customer debt logic)
            if ($newStatus === 'cancelled') {
                try {
                    $booking->refresh();
                    $userDebtService = app(\App\Services\UserDebtService::class);
                    $userDebtService->recordCancellationDebt($booking, $data['reason'] ?? null);
                } catch (\Exception $e) {
                }
                // Clear waypoint cache when booking is cancelled
                \Illuminate\Support\Facades\Cache::forget("booking_{$booking->id}_route_waypoints");
                \Illuminate\Support\Facades\Cache::forget("booking_{$booking->id}_waypoint_index");
            }

            if ($newStatus === 'completed') {

                $user_update = User::where('id', $booking->driver_id)->update([
                    'current_booking_id' => null,
                ]);

                // Clear waypoint cache when booking is completed
                \Illuminate\Support\Facades\Cache::forget("booking_{$booking->id}_route_waypoints");
                \Illuminate\Support\Facades\Cache::forget("booking_{$booking->id}_waypoint_index");
            }

            DB::commit();

            $this->sendStatusChangeNotifications($booking->fresh(), $newStatus, $oldStatus);


            event(new BookingStatusChanged($booking->fresh(), $newStatus, $this->getStatusMessage($newStatus, $booking)));

            // Removed DriverCancelledBooking event to prevent duplicate socket events
            // BookingStatusChanged already handles cancellation status and broadcasts to user channels

            $bookingData = $booking->fresh()->load(['user', 'rideType', 'driver', 'pickupZone', 'dropoffZone']);
            $formattedBooking = $this->formatResponseData($bookingData);

            if (isset($formattedBooking['user'])) {
                $keysToRemove = [];
                foreach ($formattedBooking['user'] as $key => $value) {
                    if (strpos($key, 'driver') !== false && strpos($key, 'profile') !== false) {
                        $keysToRemove[] = $key;
                    }
                }

                foreach ($keysToRemove as $key) {
                    unset($formattedBooking['user'][$key]);
                }
            }

            $responseData = [
                'success' => true,
                'message' => $this->getStatusMessage($newStatus, $booking),
                'booking' => $formattedBooking,
            ];

            if ($newStatus === 'completed') {
                $booking = $booking->fresh()->load(['user', 'rideType', 'driver', 'pickupZone', 'dropoffZone']);

                $fare = [
                    'base_fare' => $booking->base_fare,
                    'distance_fare' => $booking->distance_fare,
                    'time_fare' => $booking->time_fare,
                    'waiting_charge' => $booking->waiting_charge,
                    'night_charge' => $booking->night_charge,
                    'surge_amount' => $booking->surge_amount,
                    'subtotal' => $booking->subtotal,
                    'discount_amount' => $booking->discount_amount,
                    'debt_amount' => $booking->debt_amount,
                    'tax_amount' => $booking->tax_amount,
                    'total_amount' => $booking->total_amount,
                    'admin_commission' => $booking->admin_commission,
                    'driver_amount' => $booking->driver_amount,
                ];
                $responseData['fare'] = $this->formatResponseData($fare);

                $formattedCompletedBooking = $this->formatResponseData($booking);

                if (isset($formattedCompletedBooking['user'])) {
                    $keysToRemove = [];
                    foreach ($formattedCompletedBooking['user'] as $key => $value) {
                        if (strpos($key, 'driver') !== false && strpos($key, 'profile') !== false) {
                            $keysToRemove[] = $key;
                        }
                    }

                    foreach ($keysToRemove as $key) {
                        unset($formattedCompletedBooking['user'][$key]);
                    }
                }

                // Remove meta_data from rideType
                if (isset($formattedCompletedBooking['rideType']) && isset($formattedCompletedBooking['rideType']['meta_data'])) {
                    unset($formattedCompletedBooking['rideType']['meta_data']);
                }
                // Also check for ride_type (snake_case)
                if (isset($formattedCompletedBooking['ride_type']) && isset($formattedCompletedBooking['ride_type']['meta_data'])) {
                    unset($formattedCompletedBooking['ride_type']['meta_data']);
                }

                $responseData['booking'] = $formattedCompletedBooking;

                $invoice = $this->generateInvoice($booking);
                $responseData['invoice'] = $this->formatResponseData($invoice);
            }

            return response()->json($responseData);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function sendStatusChangeNotifications(Booking $booking, string $newStatus, string $oldStatus): void
    {
        try {
            $booking->load(['user', 'driver']);

            switch ($newStatus) {
                case 'accepted':
                    $driverName = $booking->driver->name ?? 'Driver';
                    $this->notificationService->sendBookingNotificationToUser(
                        $booking,
                        'driver_assigned',
                        "Driver {$driverName} has accepted your ride. They are on the way to pick you up."
                    );

                    break;

                case 'arrived':
                    $otp = $booking->otp ?: 'N/A';
                    $message = 'Your driver has arrived. Please provide otp ' . $otp;
                    $this->notificationService->sendBookingNotificationToUser(
                        $booking,
                        'driver_arrived',
                        $message
                    );

                    break;

                case 'started':
                    $this->notificationService->sendBookingNotificationToUser(
                        $booking,
                        'trip_started',
                        'Your trip has started. Enjoy your ride!'
                    );

                    break;

                case 'completed':
                    // Trip completed notification is handled elsewhere
                    break;

                case 'cancelled':

                    break;
            }
        } catch (\Exception $e) {
        }
    }

    private function getStatusMessage(string $status, $booking = null): string
    {
        return match ($status) {
            'accepted' => 'Booking accepted successfully - Driver is on the way',
            'arriving' => 'Driver is arriving at pickup location',
            'arrived' => $booking ? "Your driver has arrived. Please provide otp {$booking->otp}" : 'Arrival marked successfully',
            'started' => 'Trip started successfully',
            'completed' => 'Trip completed successfully',
            'cancelled' => 'Booking cancelled successfully',
            'expired' => 'Booking expired',
            default => 'Status updated successfully'
        };
    }

    private function calculateTripDistance(Booking $booking): float
    {
        $driverLocations = \App\Models\DriverLocation::where('driver_id', $booking->driver_id)
            ->where('recorded_at', '>=', $booking->started_at)
            ->where('recorded_at', '<=', now())
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('recorded_at')
            ->get();

        if ($driverLocations->count() < 2) {
            return $booking->estimated_distance ?? 0.0;
        }

        $totalDistance = 0.0;
        $previousLocation = null;

        foreach ($driverLocations as $location) {
            if ($previousLocation) {
                $distance = $this->calculateDistance(
                    $previousLocation->latitude,
                    $previousLocation->longitude,
                    $location->latitude,
                    $location->longitude
                );
                $totalDistance += $distance;
            }
            $previousLocation = $location;
        }

        return round($totalDistance, 2);
    }

    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;  // Earth's radius in kilometers

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function calculateAndUpdateFare(Booking $booking): void
    {
        $booking = $booking->fresh();

        $fare = $booking->calculateFare();

        $booking->update([
            'base_fare' => $fare['base_fare'],
            'distance_fare' => $fare['distance_fare'],
            'time_fare' => $fare['time_fare'],
            'waiting_charge' => $fare['waiting_charge'],
            'night_charge' => $fare['night_charge'],
            'surge_amount' => $fare['surge_amount'],
            'subtotal' => $fare['subtotal'],
            'discount_amount' => $fare['discount_amount'],
            'tax_amount' => $fare['tax_amount'],
            'total_amount' => $fare['total_amount'],
            'final_fare' => $fare['total_amount'],  // final_fare should match total_amount
            'admin_commission' => $fare['admin_commission'],
            'driver_amount' => $fare['driver_amount'],
        ]);
    }

    private function calculateTotalWaitingTime(Booking $booking): int
    {
        if (!$booking->driver_arrival_time || !$booking->started_at) {
            return 0;
        }

        $arrivalTime = \Carbon\Carbon::parse($booking->driver_arrival_time);
        $startTime = \Carbon\Carbon::parse($booking->started_at);

        $waitingMinutes = max(0, $arrivalTime->diffInMinutes($startTime));

        return (int) $waitingMinutes;
    }

    private function processTripCompletion(Booking $booking): void
    {
        $booking = $booking->fresh();

        $booking->loadMissing(['driver.driverProfile', 'rideType']);

        try {
            DB::beginTransaction();

            $commissionData = [
                'platform_commission_rate' => $booking->admin_commission_rate,
                'platform_commission' => $booking->admin_commission,
                'driver_amount' => $booking->driver_amount,
                'ride_type_commission_rate' => $booking->rideType->commission_rate ?? 20.0,
                'driver_commission_rate' => $booking->driver->driverProfile->commission_rate ?? null,
                'commission_type' => 'percentage',
                'total_amount' => $booking->total_amount,
            ];

            // Keep payment_status as 'pending' - will be updated later when payment is actually processed
            $booking->update([
                'payment_status' => 'pending',
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

            $commission = $this->createCommissionRecord($booking, $commissionData);

            $this->processWalletTransactions($booking, $commissionData['driver_amount'], $commissionData);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            // Log error silently
        }

        if ($booking->status === 'completed' && $booking->driver_id) {
            try {
                $incentiveService = app(\App\Services\DriverIncentiveService::class);
                $incentiveService->processRideCompletion($booking->driver_id, $booking->id);
            } catch (\Exception $e) {
                // Log error silently
            }
        }
    }

    private function calculateDynamicCommission(Booking $booking): array
    {
        $rideTypeCommissionRate = $booking->rideType->commission_rate ?? 20.0;  // Default 20% platform commission

        $driverCommissionRate = $booking->driver->driverProfile->commission_rate ?? null;

        $platformCommissionRate = $rideTypeCommissionRate ?? $driverCommissionRate;

        $platformCommissionRate = max(0, min(100, $platformCommissionRate));

        $platformCommission = ($booking->total_amount * $platformCommissionRate) / 100;
        $driverAmount = $booking->total_amount - $platformCommission;

        return [
            'platform_commission_rate' => $platformCommissionRate,
            'platform_commission' => round($platformCommission, 2),
            'driver_amount' => round($driverAmount, 2),
            'ride_type_commission_rate' => $rideTypeCommissionRate,
            'driver_commission_rate' => $driverCommissionRate,
            'commission_type' => 'percentage',
            'total_amount' => $booking->total_amount,
        ];
    }

    private function createCommissionRecord(Booking $booking, array $commissionData): \App\Models\Commission
    {
        // Ensure booking has latest tax data
        $booking = $booking->fresh();

        // Get tax amount directly from booking (not calculated on commission)
        $taxAmount = (float) ($booking->tax_amount ?? 0);

        // Get tax rate from booking
        $taxRate = (float) ($booking->tax_rate ?? 0);

        // If tax_rate is 0 but tax_amount exists, calculate tax_rate from booking
        // tax_rate = (tax_amount / subtotal) * 100
        if ($taxRate == 0 && $taxAmount > 0 && $booking->subtotal > 0) {
            $taxRate = ($taxAmount / $booking->subtotal) * 100;
        }

        return \App\Models\Commission::create([
            'booking_id' => $booking->id,
            'driver_id' => $booking->driver_id,
            'ride_type_id' => $booking->ride_type_id,
            'base_fare' => $booking->base_fare,
            'total_fare' => $booking->total_amount,
            'commission_type' => $commissionData['commission_type'],
            'commission_value' => $commissionData['platform_commission_rate'],
            'commission_amount' => $commissionData['platform_commission'],
            'driver_amount' => $commissionData['driver_amount'],
            'tax_percentage' => round($taxRate, 2),
            'tax_amount' => round($taxAmount, 2),
            'meta_data' => [
                'ride_type_commission_rate' => $commissionData['ride_type_commission_rate'],
                'driver_commission_rate' => $commissionData['driver_commission_rate'],
                'calculated_at' => now()->toISOString(),
            ],
        ]);
    }

    private function processWalletTransactions(Booking $booking, float $driverAmount, array $commissionData): void
    {
        $walletService = app(\App\Services\WalletService::class);
        $paymentGatewayService = app(\App\Services\PaymentGatewayService::class);

        $latestPaymentTransaction = $booking
            ->transactions()
            ->where('type', 'payment')
            ->latest()
            ->first();

        $paymentMethod = $latestPaymentTransaction->payment_method
            ?? $booking->payment_method
            ?? 'cash';

        // Normalize payment method to ensure consistent comparison
        $paymentMethod = strtolower(trim($paymentMethod));

        // Define non-cash payment methods (where commission should NOT be deducted from driver wallet)
        $nonCashPaymentMethods = ['razorpay', 'stripe', 'wallet', 'card', 'upi', 'netbanking', 'paypal'];

        // IMPORTANT: Only deduct commission when payment is actually received AND it's cash
        // Don't deduct commission at booking completion if payment hasn't been received yet
        $paymentStatus = $booking->payment_status ?? 'pending';
        $isPaymentReceived = $paymentStatus === 'paid';

        // Handle commission and wallet transactions based on payment method
        if ($paymentMethod === 'cash' && $isPaymentReceived) {
            // For cash payments ONLY (and only when payment is actually received):
            // 1. Deduct admin commission from driver wallet (can go negative)
            // 2. Add admin commission to admin wallet
            $this->debitCommissionFromDriverWallet($booking, $commissionData, $walletService);
            $this->addCommissionToAdminWallet($booking, $commissionData, $walletService);
        } elseif ($paymentMethod === 'cash' && !$isPaymentReceived) {
            // Cash payment but not yet received - don't deduct commission yet
            // Commission will be deducted when payment is actually received (in PaymentController)
            // DO NOT credit commission to admin wallet here - it will be done when payment is received
            // This prevents duplicate commission credits
        } elseif (in_array($paymentMethod, $nonCashPaymentMethods)) {
            // For non-cash payments (razorpay, stripe, wallet, etc.):
            // 1. Credit driver amount (after commission) to driver wallet via processDriverPayout
            // 2. Add admin commission to admin wallet
            // IMPORTANT: Admin commission is NOT deducted from driver wallet for online/wallet payments
            // The driver receives (total_amount - commission) directly, and commission goes to admin

            // Process driver payout (credits driver with amount after commission, does NOT deduct commission)
            $paymentGatewayService->processDriverPayout($booking, $booking->total_amount, $paymentMethod);

            // Add commission to admin wallet (does NOT deduct from driver wallet)
            $this->addCommissionToAdminWallet($booking, $commissionData, $walletService);
        } else {
            // Fallback: treat unknown payment methods as cash for safety
            $this->debitCommissionFromDriverWallet($booking, $commissionData, $walletService);
            $this->addCommissionToAdminWallet($booking, $commissionData, $walletService);
        }

        // Handle customer wallet debit for wallet payments
        if ($booking->payment_method === 'wallet') {
            $customerWallet = $walletService->ensureWallet($booking->user);
            $customerWallet->debit(
                $booking->total_amount,
                \App\Models\WalletTransaction::TYPE_BOOKING_PAYMENT,
                "Payment for completed trip #{$booking->booking_code}",
                [
                    'booking_id' => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'driver_id' => $booking->driver_id,
                ]
            );
        }
        // Note: Payment status will remain 'pending' until payment is actually processed
        // For stripe/online payments, payment_status should be updated separately when payment is confirmed

        try {
            $acceptedAt = $booking->accepted_at ?? $booking->created_at;
            $arrivalAt = $booking->driver_arrival_time;

            if ($acceptedAt && $arrivalAt) {
                $graceMinutes = (int) config('app.late_arrival_grace_minutes', 1);
                $penaltyAmount = (float) config('app.late_arrival_penalty_amount', 10);

                $minutesDiff = \Carbon\Carbon::parse($acceptedAt)->diffInMinutes(\Carbon\Carbon::parse($arrivalAt));

                if ($minutesDiff > $graceMinutes && $penaltyAmount > 0) {
                    $driver = $booking->driver;
                    if ($driver) {
                        $driverWallet = $walletService->ensureWallet($driver);

                        $walletTransaction = $driverWallet->debit(
                            $penaltyAmount,
                            \App\Models\WalletTransaction::TYPE_ADJUSTMENT,
                            'Penalty for Late Arrival',
                            [
                                'booking_id' => $booking->id,
                                'booking_code' => $booking->booking_code,
                                'minutes_late' => $minutesDiff,
                                'grace_minutes' => $graceMinutes,
                                'applied_at' => now()->toDateTimeString(),
                            ]
                        );

                        $transactionId = 'TXN_' . time() . '_' . rand(1000, 9999);
                        $walletTransaction->update([
                            'transection_id' => $transactionId,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
        }
    }

    private function addCommissionToAdminWallet(Booking $booking, array $commissionData, $walletService): void
    {
        try {
            $adminUser = \App\Models\User::find(1);

            if (!$adminUser) {
                // Admin user not found for commission credit
                return;
            }

            $commissionAmount = $commissionData['platform_commission'] ?? 0;

            if ($commissionAmount <= 0) {
                return;
            }

            // Get payment method to log it
            $latestPaymentTransaction = $booking
                ->transactions()
                ->where('type', 'payment')
                ->latest()
                ->first();

            $paymentMethod = $latestPaymentTransaction->payment_method
                ?? $booking->payment_method
                ?? 'cash';

            $paymentMethod = strtolower(trim($paymentMethod));

            $adminWallet = $walletService->ensureWallet($adminUser);

            $commission = \App\Models\Commission::where('booking_id', $booking->id)->first();

            // Check if commission has already been credited for this booking to prevent duplicates
            $existingTransaction = \App\Models\WalletTransaction::where('wallet_id', $adminWallet->id)
                ->where('type', \App\Models\WalletTransaction::TYPE_DRIVER_COMMISSION)
                ->where('booking_id', $booking->id)
                ->where('amount', $commissionAmount)
                ->first();

            if ($existingTransaction) {
                // Commission already credited for this booking
                return;
            }

            // IMPORTANT: This method ONLY credits the admin wallet. It does NOT deduct from driver wallet.
            // For cash payments, commission is deducted from driver wallet separately in debitCommissionFromDriverWallet.
            // For non-cash payments, commission is NOT deducted from driver wallet - driver receives (total - commission).
            $description = $paymentMethod === 'cash'
                ? "Commission from cash booking #{$booking->booking_code}"
                : "Commission from booking #{$booking->booking_code}";

            $walletTransaction = $adminWallet->credit(
                $commissionAmount,
                \App\Models\WalletTransaction::TYPE_DRIVER_COMMISSION,
                $description,
                [
                    'booking_id' => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'driver_id' => $booking->driver_id,
                    'total_amount' => $commissionData['total_amount'],
                    'commission_rate' => $commissionData['platform_commission_rate'],
                    'driver_amount' => $commissionData['driver_amount'],
                    'payment_method' => $paymentMethod,
                    'credited_at' => now()->toDateTimeString(),
                ]
            );

            if ($commission) {
                $walletTransaction->update([
                    'reference_type' => \App\Models\Commission::class,
                    'reference_id' => $commission->id,
                ]);
            }

            $transactionId = 'COMM_' . time() . '_' . rand(1000, 9999);
            $walletTransaction->update([
                'transection_id' => $transactionId,
            ]);
        } catch (\Throwable $e) {
            // Log error silently
        }
    }

    private function debitCommissionFromDriverWallet(Booking $booking, array $commissionData, $walletService): void
    {
        try {
            // SAFEGUARD: This method should ONLY be called for cash payments
            // For non-cash payments (Razorpay, Stripe, Wallet), commission is NOT deducted from driver wallet
            $latestPaymentTransaction = $booking
                ->transactions()
                ->where('type', 'payment')
                ->latest()
                ->first();

            $paymentMethod = $latestPaymentTransaction->payment_method
                ?? $booking->payment_method
                ?? 'cash';

            $paymentMethod = strtolower(trim($paymentMethod));

            $nonCashPaymentMethods = ['razorpay', 'stripe', 'wallet', 'card', 'upi', 'netbanking', 'paypal'];

            if (in_array($paymentMethod, $nonCashPaymentMethods)) {
                // Prevent commission deduction for non-cash payments
                return;
            }

            $driver = $booking->driver;

            if (!$driver) {
                // Driver not found
                return;
            }

            $commissionAmount = $commissionData['platform_commission'] ?? 0;

            if ($commissionAmount <= 0) {
                return;
            }

            $driverWallet = $walletService->ensureWallet($driver);

            // Allow negative balance for commission deduction on cash payments ONLY
            $walletTransaction = $driverWallet->debit(
                $commissionAmount,
                \App\Models\WalletTransaction::TYPE_DRIVER_COMMISSION,
                "Commission deducted for cash booking #{$booking->booking_code}",
                [
                    'booking_id' => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'user_id' => $booking->user_id,
                    'total_amount' => $commissionData['total_amount'],
                    'commission_rate' => $commissionData['platform_commission_rate'],
                    'driver_amount' => $commissionData['driver_amount'],
                    'payment_method' => 'cash',
                    'debited_at' => now()->toDateTimeString(),
                ],
                null,
                true // Allow negative balance
            );

            $transactionId = 'COMM_DRV_' . time() . '_' . rand(1000, 9999);
            $walletTransaction->update([
                'transection_id' => $transactionId,
                'reference_type' => 'App\Models\Booking',
                'reference_id' => $booking->id,
            ]);

            // Deduct tax amount from driver wallet (tax is paid by admin for COD)
            $taxAmount = (float) ($booking->tax_amount ?? 0);
            if ($taxAmount > 0) {
                $taxTransaction = $driverWallet->debit(
                    $taxAmount,
                    \App\Models\WalletTransaction::TYPE_ADJUSTMENT,
                    "Tax deducted for cash booking #{$booking->booking_code}",
                    [
                        'booking_id' => $booking->id,
                        'booking_code' => $booking->booking_code,
                        'user_id' => $booking->user_id,
                        'tax_amount' => $taxAmount,
                        'payment_method' => 'cash',
                        'debited_at' => now()->toDateTimeString(),
                    ],
                    null,
                    true // Allow negative balance
                );

                $taxTransactionId = 'TAX_DRV_' . time() . '_' . rand(1000, 9999);
                $taxTransaction->update([
                    'transection_id' => $taxTransactionId,
                    'reference_type' => 'App\Models\Booking',
                    'reference_id' => $booking->id,
                ]);
            }
        } catch (\Throwable $e) {
        }
    }

    private function debitCommissionFromDriverWalletForNonCash(Booking $booking, array $commissionData, $walletService, string $paymentMethod, bool $allowNegative = false): void
    {
        try {
            $driver = $booking->driver;

            if (!$driver) {
                // Driver not found
                return;
            }

            $commissionAmount = $commissionData['platform_commission'] ?? 0;

            if ($commissionAmount <= 0) {
                return;
            }

            $driverWallet = $walletService->ensureWallet($driver);

            // Deduct commission from driver wallet
            // Allow negative balance if payout is scheduled (driver will receive payout later)
            $walletTransaction = $driverWallet->debit(
                $commissionAmount,
                \App\Models\WalletTransaction::TYPE_DRIVER_COMMISSION,
                "Commission deducted for {$paymentMethod} booking #{$booking->booking_code}",
                [
                    'booking_id' => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'user_id' => $booking->user_id,
                    'total_amount' => $commissionData['total_amount'],
                    'commission_rate' => $commissionData['platform_commission_rate'],
                    'driver_amount' => $commissionData['driver_amount'],
                    'payment_method' => $paymentMethod,
                    'debited_at' => now()->toDateTimeString(),
                ],
                null,
                $allowNegative // Allow negative if payout is scheduled
            );

            $transactionId = 'COMM_DRV_' . time() . '_' . rand(1000, 9999);
            $walletTransaction->update([
                'transection_id' => $transactionId,
            ]);
        } catch (\Throwable $e) {
            // Log error silently
        }
    }
    private function generateInvoice(Booking $booking): array
    {
        $booking->load(['user', 'driver.vehicles', 'promoUsage.promoCode']);

        $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad($booking->id, 6, '0', STR_PAD_LEFT);

        // Calculate billing distance and duration (max of actual and estimated) to match fare calculation
        $billingDistance = max(
            (float) ($booking->actual_distance ?? 0),
            (float) ($booking->estimated_distance ?? 0)
        );

        $billingDuration = max(
            (int) ($booking->actual_duration ?? 0),
            (int) ($booking->estimated_duration ?? 0)
        );

        // Use billing values for invoice to match fare calculation
        $invoiceDistance = $billingDistance > 0 ? number_format($billingDistance, 2, '.', '') : '0.00';
        $invoiceDuration = $billingDuration > 0 ? (string) $billingDuration : '0';

        return [
            'invoice_number' => $invoiceNumber,
            'booking_id' => $booking->id,
            'booking_code' => $booking->booking_code,
            'invoice_date' => now()->format('Y-m-d H:i:s'),
            'customer' => [
                'name' => $booking->user->name,
                'phone' => $booking->user->phone,
                'email' => $booking->user->email,
            ],
            'driver' => [
                'name' => $booking->driver->name,
                'phone' => $booking->driver->phone,
                'vehicle' => $booking->driver->vehicles->first()->model ?? 'N/A',
                'license_plate' => $booking->driver->vehicles->first()->registration_number ?? 'N/A',
            ],
            'trip_details' => [
                'pickup_address' => $booking->pickup_address,
                'dropoff_address' => $booking->dropoff_address,
                'distance' => $invoiceDistance . ' km',
                'duration' => $invoiceDuration . ' minutes',
                'started_at' => $booking->started_at?->format('Y-m-d H:i:s'),
                'completed_at' => $booking->completed_at?->format('Y-m-d H:i:s'),
            ],
            'fare_breakdown' => [
                'base_fare' => $booking->base_fare,
                'distance_fare' => $booking->distance_fare,
                'time_fare' => $booking->time_fare,
                'waiting_charge' => $booking->waiting_charge,
                'night_charge' => $booking->night_charge,
                'surge_amount' => $booking->surge_amount,
                'subtotal' => $booking->subtotal,
                'promo_code' => $booking->promo_code,
                'promo_description' => $booking->promoUsage?->promoCode?->description ?? null,
                'discount_amount' => $booking->discount_amount ?? 0,
                'tax_amount' => $booking->tax_amount,
                'total_amount' => $booking->total_amount,
            ],
            'payment_details' => [
                'payment_method' => $booking->payment_method,
                'payment_status' => $booking->payment_status,
                'driver_amount' => $booking->driver_amount,
                'platform_commission' => $booking->admin_commission,
                'driver_commission_rate' => $booking->driver_amount > 0 ? round((($booking->driver_amount / $booking->total_amount) * 100), 1) . '%' : '0%',
                'platform_commission_rate' => $booking->admin_commission > 0 ? round((($booking->admin_commission / $booking->total_amount) * 100), 1) . '%' : '0%',
            ],
        ];
    }

    public function accept(Request $request, Booking $booking): JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        if ($user->is_online == 0) {
            throw ValidationException::withMessages([
                'success' => false,
                'status' => ['You are not eligible to accept rides.Go Online to accept rides.'],
            ]);
        }

        if ($booking->status !== 'searching') {
            return response()->json([
                'success' => false,
                'message' => 'Booking is not available for acceptance. Current status: ' . $booking->status,
            ], 422);
        }

        if ($booking->driver_id) {
            return response()->json([
                'success' => false,
                'message' => 'Booking has already been assigned to another driver.',
            ], 422);
        }

        DB::table('bookings')
            ->where('id', $booking->id)
            ->update([
                'driver_id' => $user->id,
                'status' => 'accepted',
                'accepted_at' => now(),
                'updated_at' => now()
            ]);

        $booking = $booking->fresh();

        $user->update(['current_booking_id' => $booking->id]);

        event(new BookingStatusChanged($booking, 'accepted', 'Booking accepted successfully - Driver is on the way'));

        $bookingData = $booking->fresh()->load('user', 'rideType');

        return response()->json([
            'success' => true,
            'message' => 'Booking accepted successfully',
            'booking' => $this->formatResponseData($bookingData),
        ]);
    }

    /**
     * Auto-accept booking by booking_id
     * This API accepts booking_id in request and uses AutoAssignToAutoGeneratedDriver job to auto-accept the booking
     */
    public function autoAccept(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|integer|exists:bookings,id',
            'is_demo' => 'nullable|integer|in:0,1',
        ]);

        $bookingId = $request->input('booking_id');
        $isDemo = $request->input('is_demo', 1); // Default to 1 for backward compatibility
        $booking = Booking::findOrFail($bookingId);

        // Allow auto-accept for 'searching' or 'expired' status bookings
        // This allows calling the API even after booking expires
        if (!in_array($booking->status, ['searching', 'expired'])) {
            return response()->json([
                'success' => false,
                'message' => 'Booking is not available for acceptance. Current status: ' . $booking->status,
            ], 422);
        }

        // If booking is expired, reset it to searching status
        if ($booking->status === 'expired') {

            $booking->update([
                'status' => 'searching',
                'expired_at' => null,
            ]);
            $booking->refresh();
        }

        // Check if booking already has a driver
        if ($booking->driver_id) {
            return response()->json([
                'success' => false,
                'message' => 'Booking has already been assigned to a driver.',
            ], 422);
        }

        // Always trigger normal flow (socket events) - removed is_demo condition


        // Ensure booking is in searching status for normal flow
        if ($booking->status !== 'searching') {
            $booking->update([
                'status' => 'searching',
                'expired_at' => null,
            ]);
            $booking->refresh();
        }

        // Trigger normal driver notification flow which will broadcast socket events
        try {
            $this->driverNotificationService->startDriverNotification($booking);
        } catch (\Exception $e) {
            // Log error silently
        }

        // Broadcast to drivers.all channel using NewBooking event structure
        try {
            $booking = $booking->fresh();
            $booking->load(['user', 'rideType', 'bookingContact', 'promoUsage.promoCode']);

            // Create NewBooking event instance to get the broadcast data
            $newBookingEvent = new \App\Events\NewBooking($booking, $booking->user, null);
            $broadcastData = $newBookingEvent->broadcastWith();

            // Broadcast using CustomDriverBroadcast to drivers.all channel
            // Note: CustomDriverBroadcast uses 'driver.all' channel, but we need 'drivers.all'
            // So we'll use NewBooking event directly which broadcasts to drivers.all
            event(new \App\Events\NewBooking($booking, $booking->user, null));
        } catch (\Exception $e) {
            // Log error silently
        }

        $booking = $booking->fresh();
        $booking->load(['user', 'rideType']);

        return response()->json([
            'success' => true,
            'message' => 'Normal flow triggered with socket events.',
            'booking' => $this->formatResponseData($booking),
        ]);
    }

    public function arrived(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->driver_id !== Auth::id() || !in_array($booking->status, ['accepted'])) {
            throw ValidationException::withMessages([
                'booking' => ['Invalid booking status.'],
            ]);
        }

        $oldStatus = $booking->status;
        $booking->update(['status' => 'arrived']);

        // Clear waypoint cache when booking status changes to arrived
        \Illuminate\Support\Facades\Cache::forget("booking_{$booking->id}_route_waypoints");
        \Illuminate\Support\Facades\Cache::forget("booking_{$booking->id}_waypoint_index");

        event(new BookingStatusChanged($booking->fresh(), 'arrived', 'Arrival marked successfully'));

        return response()->json([
            'message' => 'Arrival marked successfully',
            'booking' => $this->formatResponseData($booking->fresh()),
        ]);
    }

    public function start(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->driver_id !== Auth::id() || $booking->status !== 'arrived') {
            throw ValidationException::withMessages([
                'booking' => ['Invalid booking status.'],
            ]);
        }

        // In demo mode, accept 6-digit OTP (123456), otherwise 4-digit
        $otpSize = \App\Services\DemoModeService::isEnabled() ? 6 : 4;
        $request->validate([
            'otp' => ['required', 'string', 'size:' . $otpSize],
        ]);

        // Accept demo OTP in demo mode
        $isValidOtp = false;
        if (\App\Services\DemoModeService::isEnabled()) {
            $isValidOtp = \App\Services\DemoModeService::isDemoOtp($request->otp) || $booking->otp === $request->otp;
        } else {
            $isValidOtp = $booking->otp === $request->otp;
        }

        if (!$isValidOtp) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid OTP provided.'],
            ]);
        }

        $oldStatus = $booking->status;
        $booking->update([
            'status' => 'started',
            'started_at' => now(),
        ]);

        event(new BookingStatusChanged($booking->fresh(), 'started', 'Trip started successfully'));

        return response()->json([
            'message' => 'Trip started successfully',
            'booking' => $this->formatResponseData($booking->fresh()),
        ]);
    }

    public function complete(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->driver_id !== Auth::id() || $booking->status !== 'started') {
            throw ValidationException::withMessages([
                'booking' => ['Invalid booking status.'],
            ]);
        }

        try {
            DB::beginTransaction();

            $actualDistance = $booking
                ->driverLocations()
                ->whereNotNull('next_location')
                ->sum(DB::raw('ST_Distance_Sphere(location, next_location) / 1000'));  // in km

            $actualDuration = $booking->started_at->diffInMinutes(now());

            $oldStatus = $booking->status;
            $booking->update([
                'status' => 'completed',
                'completed_at' => now(),
                'actual_distance' => $actualDistance,
                'actual_duration' => $actualDuration,
            ]);

            $fare = $booking->calculateFare();

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

            $driver = Auth::user();
            $driver->current_booking_id = null;
            $driver->is_online = true;  // Keep driver online but available
            $driver->save();

            DB::commit();

            $booking = $booking->fresh();

            if ($booking->status === 'completed' && $booking->driver_id) {
                try {
                    $incentiveService = app(\App\Services\DriverIncentiveService::class);
                    $incentiveService->processRideCompletion($booking->driver_id, $booking->id);
                } catch (\Exception $e) {
                    // Continue on error
                }
            }

            event(new BookingStatusChanged($booking, 'completed', 'Trip completed successfully'));

            event(new \App\Events\TripCompleted($booking));

            return response()->json([
                'message' => 'Trip completed successfully',
                'booking' => $this->formatResponseData($booking),
                'fare' => $this->formatResponseData($fare),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->driver_id !== Auth::id() || !$booking->canBeCancelledByDriver()) {
            throw ValidationException::withMessages([
                'booking' => ['This booking cannot be cancelled.'],
            ]);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($booking, $data) {
            $oldStatus = $booking->status;
            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $data['reason'],
                'cancelled_by_type' => 'App\Models\User',
                'cancelled_by_id' => Auth::id(),
            ]);

            if ($booking->promo_code) {
                \App\Models\PromoUsage::where('booking_id', $booking->id)->delete();
            }

            $driver = Auth::user();
            $driver->current_booking_id = null;
            $driver->is_online = true;  // Keep driver online but available
            $driver->save();

            event(new BookingStatusChanged($booking->fresh(), 'cancelled', 'Booking cancelled successfully'));

            event(new DriverCancelledBooking($booking->fresh()));
        });

        return response()->json([
            'message' => 'Booking cancelled successfully',
        ]);
    }

    public function getCurrentBooking(): JsonResponse
    {
        $booking = Booking::where('driver_id', Auth::id())
            ->whereIn('status', ['accepted', 'arrived', 'started'])
            ->with(['user', 'rideType', 'pickupZone', 'dropoffZone'])
            ->first();

        return response()->json(['booking' => $this->formatResponseData($booking)]);
    }

    public function getBookingHistory(Request $request): JsonResponse
    {
        $bookings = Booking::where('driver_id', Auth::id())
            ->whereIn('status', ['completed', 'cancelled'])
            ->with(['user', 'rideType'])
            ->latest()
            ->paginate($request->input('per_page', 10));

        return response()->json($this->formatResponseData($bookings));
    }

    public function rateUser(Request $request, Booking $booking): JsonResponse
    {
        if (!$booking->isCompleted() || $booking->driver_id !== Auth::id()) {
            throw ValidationException::withMessages([
                'booking' => ['Invalid booking for rating.'],
            ]);
        }

        $data = $request->validate([
            'rating' => ['required', 'numeric', 'min:1', 'max:5'],
            'review' => ['nullable', 'string', 'max:65535'],
        ]);

        $booking->update([
            'driver_rating' => $data['rating'],
            'driver_review' => $data['review'],
        ]);

        return response()->json([
            'message' => 'Rating submitted successfully',
            'booking' => $this->formatResponseData($booking->only('id', 'driver_rating', 'driver_review')),
        ]);
    }

    public function reviewCustomer(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'booking_id' => ['required', 'exists:bookings,id'],
                'rating' => ['required', 'numeric', 'between:1,5'],
                'comment' => ['nullable', 'string', 'max:500'],
            ]);

            $driver = $request->user();
            $booking = Booking::findOrFail($data['booking_id']);
            if (!$driver || (int) $booking->driver_id !== (int) $driver->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this booking',
                ], 403);
            }

            if ($booking->status !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only review completed trips',
                ], 422);
            }

            $booking->update([
                'driver_rating' => $data['rating'],
                'driver_comment' => $data['comment'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Customer reviewed successfully',
                'data' => [
                    'booking_id' => $booking->id,
                    'rating' => $data['rating'],
                    'comment' => $data['comment'],
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to review customer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateLocation(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->driver_id !== Auth::id() || !in_array($booking->status, ['accepted', 'arrived', 'started'])) {
            throw ValidationException::withMessages([
                'booking' => ['Invalid booking for location update.'],
            ]);
        }

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['required', 'numeric', 'between:0,360'],
            'eta' => ['nullable', 'integer', 'min:0'],
        ]);

        $location = new Point($data['latitude'], $data['longitude']);

        try {
            DB::beginTransaction();

            $previousLocation = $booking->driverLocations()->latest()->first();

            $locationRecord = $booking->driverLocations()->create([
                'driver_id' => Auth::id(),
                'location' => $location,
                'heading' => $data['heading'],
                'recorded_at' => now(),
            ]);

            if ($previousLocation) {
                $previousLocation->update([
                    'next_location' => $location,
                ]);
            }

            $driver = Auth::user();
            $driver->last_latitude = $data['latitude'];
            $driver->last_longitude = $data['longitude'];
            $driver->last_location_at = now();
            $driver->save();

            DB::commit();

            broadcast(new LocationUpdated(
                $booking,
                $location,
                $data['heading'],
                $data['eta'] ?? null
            ))->toOthers();

            return response()->json([
                'message' => 'Location updated successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function matchOtp(Request $request): JsonResponse
    {
        try {
            // In demo mode, accept 6-digit OTP (123456), otherwise 4-digit
            $otpSize = \App\Services\DemoModeService::isEnabled() ? 6 : 4;
            $data = $request->validate([
                'booking_id' => ['required', 'exists:bookings,id'],
                'otp' => ['required', 'string', 'size:' . $otpSize],
            ]);

            $user = Auth::user();
            $booking = Booking::findOrFail($data['booking_id']);

            if (!$user || (int) $booking->driver_id !== (int) $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this booking',
                ], 403);
            }

            if ($booking->status !== 'arrived') {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking must be in arrived status to verify OTP',
                ], 400);
            }

            // Accept demo OTP in demo mode
            $isValidOtp = false;
            if (\App\Services\DemoModeService::isEnabled()) {
                $isValidOtp = \App\Services\DemoModeService::isDemoOtp($data['otp']) || $booking->otp === $data['otp'];
            } else {
                $isValidOtp = $booking->otp === $data['otp'];
            }

            if (!$isValidOtp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid OTP provided',
                ], 422);
            }

            $oldStatus = $booking->status;
            $booking->update([
                'status' => 'started',
                'started_at' => now(),
            ]);

            $booking->load(['user', 'driver', 'rideType']);

            event(new BookingStatusChanged($booking->fresh(), 'started', 'OTP verified successfully, trip started'));

            return response()->json([
                'success' => true,
                'message' => 'OTP verified successfully, trip started',
                'data' => [
                    'booking_id' => (string) $booking->id,
                    'status' => (string) $booking->status,
                    'started_at' => $booking->started_at ? $booking->started_at->toISOString() : '',
                    'customer' => $this->formatCustomerData($booking->user),
                    'driver' => $booking->driver ? $this->formatDriverData($booking->driver) : '',
                    'ride_type' => $booking->rideType ? $this->formatRideTypeData($booking->rideType) : '',
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify OTP',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function formatCustomerData($user): array
    {
        if (!$user) {
            return [];
        }

        return [
            'id' => (string) ($user->id ?? ''),
            'name' => (string) ($user->name ?? ''),
            'phone' => (string) ($user->phone ?? ''),
            'email' => (string) ($user->email ?? ''),
            'country_code' => (string) ($user->country_code ?? ''),
            'profile_photo' => (string) ($user->profile_photo ?? ''),
        ];
    }

    private function formatDriverData($driver): array
    {
        if (!$driver) {
            return [];
        }

        return [
            'id' => (string) ($driver->id ?? ''),
            'name' => (string) ($driver->name ?? ''),
            'phone' => (string) ($driver->phone ?? ''),
            'email' => (string) ($driver->email ?? ''),
            'country_code' => (string) ($driver->country_code ?? ''),
            'profile_photo' => (string) ($driver->profile_photo ?? ''),
            'is_online' => (string) ($driver->is_online ?? ''),
            'last_latitude' => (string) ($driver->last_latitude ?? ''),
            'last_longitude' => (string) ($driver->last_longitude ?? ''),
        ];
    }

    private function formatRideTypeData($rideType): array
    {
        if (!$rideType) {
            return [];
        }

        return [
            'id' => (string) ($rideType->id ?? ''),
            'name' => (string) ($rideType->name ?? ''),
            'description' => (string) ($rideType->description ?? ''),
            'icon' => (string) ($rideType->icon ?? ''),
            'capacity' => (string) ($rideType->capacity ?? ''),
            'status' => (string) ($rideType->status ?? ''),
        ];
    }

    public function broadcastToDriverAll(Request $request): JsonResponse
    {
        try {
            $actor = $request->user();
            $isAdmin = $actor && ((int) ($actor->role_id ?? 0) === 1 || (method_exists($actor, 'hasRole') && $actor->hasRole('admin')));
            if (!$isAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admins can broadcast to all drivers',
                ], 403);
            }

            $data = $request->validate([
                'message' => ['required', 'string', 'max:255'],
                'event_type' => ['nullable', 'string', 'max:50'],
                'data' => ['nullable', 'array'],
            ]);

            $event = new \App\Events\CustomDriverBroadcast(
                $data['message'],
                $data['event_type'] ?? 'test.message',
                $data['data'] ?? []
            );

            event($event);

            return response()->json([
                'success' => true,
                'message' => 'Data broadcasted to driver.all channel successfully',
                'broadcasted_data' => [
                    'message' => $data['message'],
                    'event_type' => $data['event_type'] ?? 'test.message',
                    'data' => $data['data'] ?? [],
                    'channel' => 'driver.all',
                    'timestamp' => now()->toISOString(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to broadcast data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
