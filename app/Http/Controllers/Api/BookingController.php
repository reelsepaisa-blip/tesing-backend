<?php

namespace App\Http\Controllers\Api;

use App\Events\NewBooking;
use App\Events\UserCancelledBooking;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingContact;
use App\Models\City;
use App\Models\Driver;
use App\Models\RideType;
use App\Models\User;
use App\Models\UserDebt;
use App\Models\Zone;
use App\Services\BookingService;
use App\Services\CityService;
use App\Services\DriverMatchingService;
use App\Services\DriverNotificationService;
use App\Services\ETAService;
use App\Services\FareService;
use App\Services\GoogleMapsService;
use App\Services\NotificationService;
use App\Services\RealTimeService;
use App\Services\UserDebtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Illuminate\Validation\ValidationException;
use MatanYadaev\EloquentSpatial\Objects\Point;

class BookingController extends Controller
{
    protected $driverMatching;
    protected $driverNotificationService;
    protected $realTimeService;
    protected $notificationService;
    protected $userDebtService;
    protected BookingService $bookingService;

    public function __construct(
        DriverMatchingService $driverMatching,
        DriverNotificationService $driverNotificationService,
        RealTimeService $realTimeService,
        NotificationService $notificationService,
        UserDebtService $userDebtService,
        BookingService $bookingService
    ) {
        $this->driverMatching = $driverMatching;
        $this->driverNotificationService = $driverNotificationService;
        $this->realTimeService = $realTimeService;
        $this->notificationService = $notificationService;
        $this->userDebtService = $userDebtService;
        $this->bookingService = $bookingService;
    }

    public function estimate(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'city_id' => ['required', 'exists:cities,id'],
                'ride_type_id' => ['required', 'exists:ride_types,id'],
                'pickup_latitude' => ['required', 'numeric', 'between:-90,90'],
                'pickup_longitude' => ['required', 'numeric', 'between:-180,180'],
                'dropoff_latitude' => ['required', 'numeric', 'between:-90,90'],
                'dropoff_longitude' => ['required', 'numeric', 'between:-180,180'],
            ]);

            $user = $request->user();
            if ($user && !$user->isActive()) {
                $statusMessage = match ($user->status) {
                    'inactive' => 'Your account is inactive. Please contact support to activate your account.',
                    'blocked' => 'Your account has been blocked. Please contact support for assistance.',
                    'under_review' => 'Your account is under review. Please wait for the review to complete before getting fare estimates.',
                    default => 'Your account is not active. Please contact support for assistance.',
                };

                return response()->json([
                    'success' => false,
                    'message' => $statusMessage,
                ], 403);
            }

            // Skip serviceability check in demo mode
            if (!\App\Services\DemoModeService::isEnabled()) {
                $cityService = app(CityService::class);
                $isServiceable = $cityService->isLocationServiceable(
                    $data['city_id'],
                    $data['pickup_latitude'],
                    $data['pickup_longitude']
                );

                if (!$isServiceable) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Service not available in this area.',
                    ], 400);
                }
            }

            $googleMapsService = app(GoogleMapsService::class);
            $routeData = $googleMapsService->getDistanceAndDuration(
                $data['pickup_latitude'],
                $data['pickup_longitude'],
                $data['dropoff_latitude'],
                $data['dropoff_longitude']
            );

            $data['distance'] = $routeData['distance'];
            $data['duration'] = $routeData['duration'];

            $fareService = app(FareService::class);
            $fareEstimate = $fareService->calculateEstimatedFare($data);

            $etaService = app(ETAService::class);
            $etaData = $etaService->calculateETA(
                $data['pickup_latitude'],
                $data['pickup_longitude'],
                $data['city_id'],
                $data['ride_type_id']
            );

            $estimatedArrivalTime = now()->addMinutes($etaData['estimated_eta']);

            $responseData = [
                'fare_breakdown' => $this->formatFareBreakdownToStrings($fareEstimate),
                'distance' => (string) ($routeData['distance'] ?? ''),
                'duration' => (string) ($routeData['duration'] ?? ''),
                'duration_in_traffic' => (string) ($routeData['duration_in_traffic'] ?? ''),
                'estimated_eta' => (string) ($etaData['estimated_eta'] ?? ''),
                'estimate_arrived_time' => $estimatedArrivalTime ? $estimatedArrivalTime->format('Y-m-d H:i:s') : '',
                'eta_breakdown' => $this->formatEtaBreakdownToStrings($etaData['breakdown'] ?? []),
            ];

            if (isset($etaData['matched_driver'])) {
                $responseData['matched_driver'] = $this->formatDriverInfoToStrings($etaData['matched_driver']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Fare estimation successful',
                'data' => $responseData,
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
                'message' => 'Failed to estimate fare',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function create(Request $request): JsonResponse
    {
        try {
            $requestData = $request->all();
            $cleanedData = [];
            foreach ($requestData as $key => $value) {
                $cleanedKey = trim($key);
                if ($cleanedKey !== 'user' && $cleanedKey !== '') {
                    $cleanedData[$cleanedKey] = $value;
                }
            }
            $request->merge($cleanedData);

            $data = $request->validate([
                'pickup_latitude' => ['required', 'numeric', 'between:-90,90'],
                'pickup_longitude' => ['required', 'numeric', 'between:-180,180'],
                'pickup_address' => ['required', 'string'],
                'dropoff_latitude' => ['required', 'numeric', 'between:-90,90'],
                'dropoff_longitude' => ['required', 'numeric', 'between:-180,180'],
                'dropoff_address' => ['required', 'string'],
                'booking_contact_id' => [
                    'nullable',
                    'integer',
                ],
                'ride_type_id' => [
                    'nullable',
                    'integer',
                    'exists:ride_types,id',
                ],
            ]);

            if (empty($data['booking_contact_id']) || $data['booking_contact_id'] == 0) {
                $data['booking_contact_id'] = null;
            }

            if (!empty($data['booking_contact_id'])) {
                $user = $request->user();
                $bookingContactExists = BookingContact::where('id', $data['booking_contact_id'])
                    ->where('user_id', $user->id ?? null)
                    ->exists();

                if (!$bookingContactExists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The selected booking contact is invalid or does not belong to you.',
                    ], 422);
                }
            }

            
            // Use demo locations if demo mode is enabled
            if (\App\Services\DemoModeService::isEnabled()) {
                $demoPickup = \App\Services\DemoModeService::getDemoPickupLocation();
                $demoDropoff = \App\Services\DemoModeService::getDemoDropoffLocation();

                $data['pickup_latitude'] = $demoPickup['latitude'];
                $data['pickup_longitude'] = $demoPickup['longitude'];
                $data['pickup_address'] = $demoPickup['address'];
                $data['dropoff_latitude'] = $demoDropoff['latitude'];
                $data['dropoff_longitude'] = $demoDropoff['longitude'];
                $data['dropoff_address'] = $demoDropoff['address'];
            }

            $data['pickup_latitude'] = (float) $data['pickup_latitude'];
            $data['pickup_longitude'] = (float) $data['pickup_longitude'];
            $data['dropoff_latitude'] = (float) $data['dropoff_latitude'];
            $data['dropoff_longitude'] = (float) $data['dropoff_longitude'];


            if (
                abs($data['pickup_latitude'] - $data['dropoff_latitude']) < 0.000001 &&
                abs($data['pickup_longitude'] - $data['dropoff_longitude']) < 0.000001
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pickup and dropoff locations cannot be the same.',
                ], 422);
            }

            $cityService = app(CityService::class);

            

            // In demo mode, use first available city if demo location doesn't match any city
            if (\App\Services\DemoModeService::isEnabled()) {
                $city = $cityService->getNearestCity(
                    $data['pickup_latitude'],
                    $data['pickup_longitude'],
                    50  // Max distance in km
                );

                // Fallback to first active city if no city found
                if (!$city) {
                    $city = City::where('status', 1)->whereNotNull('latitude')->whereNotNull('longitude')->first();
                    
                }

                if (!$city) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No active cities found in the system. Please configure at least one city.',
                    ], 400);
                }
            } else {
                $city = $cityService->getNearestCity(
                    $data['pickup_latitude'],
                    $data['pickup_longitude'],
                    50000  // Max distance in km
                );

                if (!$city) {

                    return response()->json([
                        'success' => false,
                        'message' => 'No service available in this area. Please try a different location.',
                    ], 400);
                }
            }

            $data['city_id'] = $city->id;

            // Use demo ride type (taxi) if demo mode is enabled
            if (\App\Services\DemoModeService::isEnabled()) {
                // If ride_type_id is already provided, use it
                // Otherwise, use demo ride type
                if (empty($data['ride_type_id'])) {
                    $demoRideTypeId = \App\Services\DemoModeService::getDemoRideTypeId();
                    if ($demoRideTypeId) {
                        $data['ride_type_id'] = $demoRideTypeId;
                    } else {
                        // Fallback to default if taxi not found
                        $defaultRideType = RideType::where('status', 1)
                            ->where(function ($query) use ($city) {
                                $query->whereHas('cities', function ($subQuery) use ($city) {
                                    $subQuery->where('city_id', $city->id);
                                })->orWhereDoesntHave('cities');
                            })
                            ->orderBy('order')
                            ->first();
                        $data['ride_type_id'] = $defaultRideType?->id;
                    }
                }
            } else {
                // If ride_type_id is already provided, use it
                // Otherwise, get default ride type
                if (empty($data['ride_type_id'])) {
                    $defaultRideType = RideType::where('status', 1)
                        ->where(function ($query) use ($city) {
                            $query->whereHas('cities', function ($subQuery) use ($city) {
                                $subQuery->where('city_id', $city->id);
                            })->orWhereDoesntHave('cities');  // Include ride types not linked to any city
                        })
                        ->orderBy('order')
                        ->first();

                    if (!$defaultRideType) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No ride types available for this city.',
                        ], 400);
                    }

                    $data['ride_type_id'] = $defaultRideType->id;
                }
            }

            // Validate that the selected ride type is available in the city
            if (!empty($data['ride_type_id'])) {
                $rideType = RideType::find($data['ride_type_id']);

                if (!$rideType) {
                    

                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid ride type selected.',
                    ], 422);
                }

                

                // Check if ride type is available in the detected city
                $isAvailable = $rideType->isAvailableInCity($city);

                

                if (!$isAvailable) {
                    // Get additional debug information
                    $availableCitiesForRideType = DB::table('available_ride_cities')
                        ->where('ride_type_id', $rideType->id)
                        ->pluck('city_id')
                        ->toArray();

                    $cityRideTypesForRideType = DB::table('city_ride_types')
                        ->where('ride_type_id', $rideType->id)
                        ->where('status', 1)
                        ->pluck('city_id')
                        ->toArray();

                    // Auto-fix: Automatically link ride type to city if both are active
                    // This prevents errors when ride types are not properly linked to cities
                    $rideTypeIsActive = $rideType->status == 1 || $rideType->status === true;
                    $cityIsActive = $city->status == 1 || $city->status === true;

                    // Check if ride type has pricing for this city
                    $hasCityPricing = DB::table('city_ride_types')
                        ->where('ride_type_id', $rideType->id)
                        ->where('city_id', $city->id)
                        ->where('status', 1)
                        ->exists();

                    // Check if ride type has no restrictions at all (available in all cities)
                    $hasAnyRestrictions = DB::table('available_ride_cities')
                        ->where('ride_type_id', $rideType->id)
                        ->exists() ||
                        DB::table('city_ride_types')
                        ->where('ride_type_id', $rideType->id)
                        ->whereNotNull('city_id')
                        ->exists();

                    // Auto-fix: Add to available_ride_cities if:
                    // 1. Both ride type and city are active, OR
                    // 2. Has pricing for this city, OR
                    // 3. Has no restrictions at all (available everywhere)
                    if (($rideTypeIsActive && $cityIsActive) || $hasCityPricing || !$hasAnyRestrictions) {
                        // Check if entry already exists (race condition protection)
                        $exists = DB::table('available_ride_cities')
                            ->where('ride_type_id', $rideType->id)
                            ->where('city_id', $city->id)
                            ->exists();

                        if (!$exists) {
                            DB::table('available_ride_cities')->insert([
                                'ride_type_id' => $rideType->id,
                                'city_id' => $city->id,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);


                            // Re-check availability after auto-fix
                            $isAvailable = $rideType->isAvailableInCity($city);
                            if ($isAvailable) {
                                
                                // Continue with booking creation
                            }
                        }
                    }

                    // If still not available after auto-fix, return error
                    if (!$isAvailable) {

                        return response()->json([
                            'success' => false,
                            'message' => 'no ride available in tour area',
                            'debug' => [
                                'ride_type_id' => $rideType->id,
                                'ride_type_name' => $rideType->name,
                                'city_id' => $city->id,
                                'city_name' => $city->name,
                                'available_cities_for_ride_type' => $availableCitiesForRideType,
                                'fix_query' => "INSERT INTO available_ride_cities (ride_type_id, city_id, created_at, updated_at) VALUES ({$rideType->id}, {$city->id}, NOW(), NOW())",
                            ],
                        ], 400);
                    }
                }
            } else {
                

                // If no ride type could be determined, return error
                return response()->json([
                    'success' => false,
                    'message' => 'no ride available in tour area',
                ], 400);
            }
            $data['payment_method'] = null;  // Will be set by separate API

            

            // Skip serviceability check in demo mode
            if (\App\Services\DemoModeService::isEnabled()) {
                
            } else {
                $isServiceable = $cityService->isLocationServiceable(
                    $data['city_id'],
                    $data['pickup_latitude'],
                    $data['pickup_longitude']
                );


                if (!$isServiceable) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Service not available in this area.',
                    ], 400);
                }
            }

            // Skip zone validation in demo mode
            if (\App\Services\DemoModeService::isEnabled()) {
                $demoZones = \App\Services\DemoModeService::getDemoZoneIds($data['city_id']);
                $data['pickup_zone_id'] = $demoZones['pickup_zone_id'];
                $data['dropoff_zone_id'] = $demoZones['dropoff_zone_id'];

                
            } else {
                try {
                    $pickupZone = Zone::where('city_id', $data['city_id'])
                        ->where('status', 1)
                        ->containsLocation($data['pickup_latitude'], $data['pickup_longitude'])
                        ->first();

                    $dropoffZone = Zone::where('city_id', $data['city_id'])
                        ->where('status', 1)
                        ->containsLocation($data['dropoff_latitude'], $data['dropoff_longitude'])
                        ->first();
                } catch (\Exception $e) {

                    $pickupZone = Zone::where('city_id', $data['city_id'])
                        ->where('status', 1)
                        ->whereRaw('ST_Contains(boundaries, POINT(?, ?))', [$data['pickup_longitude'], $data['pickup_latitude']])
                        ->first();

                    $dropoffZone = Zone::where('city_id', $data['city_id'])
                        ->where('status', 1)
                        ->whereRaw('ST_Contains(boundaries, POINT(?, ?))', [$data['dropoff_longitude'], $data['dropoff_latitude']])
                        ->first();
                }


                if (!$pickupZone || !$dropoffZone) {
                    $message = 'No service available for this zone, please select another location.';
                    if (!$pickupZone) {
                        $message .= ' Pickup location is not within any active zone.';
                    }
                    if (!$dropoffZone) {
                        $message .= ' Dropoff location is not within any active zone.';
                    }

                    return response()->json([
                        'success' => false,
                        'message' => $message,
                    ], 400);
                }

                if ($pickupZone->city_id !== $dropoffZone->city_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No service available for this zone, please select another location.',
                    ], 400);
                }

                $data['pickup_zone_id'] = $pickupZone->id;
                $data['dropoff_zone_id'] = $dropoffZone->id;
            }

            $zone = Zone::where('city_id', $data['city_id'])->where('status', 1)->first();

            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }
            if (!$user->isActive()) {
                $statusMessage = match ($user->status) {
                    'inactive' => 'Your account is inactive. Please contact support to activate your account.',
                    'blocked' => 'Your account has been blocked. Please contact support for assistance.',
                    'under_review' => 'Your account is under review. Please wait for the review to complete before booking rides.',
                    default => 'Your account is not active. Please contact support for assistance.',
                };

                return response()->json([
                    'success' => false,
                    'message' => $statusMessage,
                ], 403);
            }

            $googleMapsService = app(GoogleMapsService::class);
            $routeData = $googleMapsService->getDistanceAndDuration(
                $data['pickup_latitude'],
                $data['pickup_longitude'],
                $data['dropoff_latitude'],
                $data['dropoff_longitude']
            );

            $distance = $routeData['distance'];
            $duration = $routeData['duration'];

            $passengerName = null;
            $isOtherBooking = 0;
            if (!empty($data['booking_contact_id'])) {
                $bookingContact = BookingContact::find($data['booking_contact_id']);
                if ($bookingContact) {
                    $passengerName = $bookingContact->name;
                    $isOtherBooking = 1;
                }
            }

            $fareService = app(FareService::class);
            $fareParams = [
                'city_id' => $data['city_id'],
                'ride_type_id' => $data['ride_type_id'],
                'pickup_latitude' => $data['pickup_latitude'],
                'pickup_longitude' => $data['pickup_longitude'],
                'distance' => $distance,
                'duration' => $duration,
            ];
            $fareEstimateForBooking = $fareService->calculateEstimatedFare($fareParams);
            $estimatedFare = $fareEstimateForBooking['total'];

            


            $booking = Booking::create([
                'user_id' => $user->id,
                'city_id' => $data['city_id'],
                'ride_type_id' => null,  // Initially null - will be selected later
                'pickup_zone_id' => $data['pickup_zone_id'] ?? null,
                'dropoff_zone_id' => $data['dropoff_zone_id'] ?? null,
                'pickup_location' => 'POINT(' . (string) $data['pickup_longitude'] . ' ' . (string) $data['pickup_latitude'] . ')',
                'dropoff_location' => 'POINT(' . (string) $data['dropoff_longitude'] . ' ' . (string) $data['dropoff_latitude'] . ')',
                'pickup_latitude' => $data['pickup_latitude'],
                'pickup_longitude' => $data['pickup_longitude'],
                'pickup_address' => $data['pickup_address'],
                'dropoff_latitude' => $data['dropoff_latitude'],
                'dropoff_longitude' => $data['dropoff_longitude'],
                'dropoff_address' => $data['dropoff_address'],
                'distance' => $distance,
                'duration' => $duration,
                'estimated_distance' => $distance,  // Store estimated distance for minimum billing
                'estimated_duration' => $duration,  // Store estimated duration for minimum billing
                'estimated_fare' => $estimatedFare,  // Calculated with default ride type
                'final_fare' => $estimatedFare,
                'surge_multiplier' => $fareEstimateForBooking['surge_multiplier'],
                'surge_amount' => $fareEstimateForBooking['surge_amount'],
                'payment_method' => $data['payment_method'] ?? null,
                'status' => 'pending',
                'booking_contact_id' => $data['booking_contact_id'] ?? null,
                'passenger_name' => $passengerName,
                'is_other_booking' => $isOtherBooking,
                'trip_code' => str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT),
            ]);

            

            

            $availableRideTypes = $cityService->getAvailableRideTypes($data['city_id'], $data['pickup_latitude'], $data['pickup_longitude']);


            

            $rideOptions = $this->getRideOptions($data['city_id'], $distance, $duration, [
                'pickup_latitude' => $data['pickup_latitude'],
                'pickup_longitude' => $data['pickup_longitude']
            ]);


            $fareService = app(FareService::class);
            $etaService = app(ETAService::class);

            $fareParams = [
                'city_id' => $data['city_id'],
                'ride_type_id' => $data['ride_type_id'],  // Default ride type (Bike)
                'pickup_latitude' => $data['pickup_latitude'],
                'pickup_longitude' => $data['pickup_longitude'],
                'distance' => $distance,
                'duration' => $duration,
            ];
            $fareEstimate = $fareService->calculateEstimatedFare($fareParams);

            $etaData = $etaService->calculateETA(
                $data['pickup_latitude'],
                $data['pickup_longitude'],
                $data['city_id'],
                $data['ride_type_id']
            );

            $estimatedArrivalTime = now()->addMinutes($etaData['estimated_eta']);

            $rideTypeEstimate = [
                'fare_breakdown' => $this->formatFareBreakdownToStrings($fareEstimate),
                'distance' => (string) ($distance ?? ''),
                'duration' => (string) ($duration ?? ''),
                'estimated_eta' => (string) ($etaData['estimated_eta'] ?? ''),
                'estimate_arrived_time' => $estimatedArrivalTime ? $estimatedArrivalTime->format('Y-m-d H:i:s') : '',
                'eta_breakdown' => $this->formatEtaBreakdownToStrings($etaData['breakdown'] ?? []),
            ];

            if (isset($etaData['matched_driver'])) {
                $rideTypeEstimate['matched_driver'] = $this->formatDriverInfoToStrings($etaData['matched_driver']);
            }

            $formattedBooking = [
                'id' => (string) $booking->id,
                'user_id' => (string) $booking->user_id,
                'city_id' => (string) $booking->city_id,
                'ride_type_id' => $booking->ride_type_id ? (string) $booking->ride_type_id : '',
                'pickup_zone_id' => $booking->pickup_zone_id ? (string) $booking->pickup_zone_id : '',
                'dropoff_zone_id' => $booking->dropoff_zone_id ? (string) $booking->dropoff_zone_id : '',
                'pickup_latitude' => (string) $booking->pickup_latitude,
                'pickup_longitude' => (string) $booking->pickup_longitude,
                'pickup_address' => $booking->pickup_address ?? '',
                'dropoff_latitude' => (string) $booking->dropoff_latitude,
                'dropoff_longitude' => (string) $booking->dropoff_longitude,
                'dropoff_address' => $booking->dropoff_address ?? '',
                'distance' => (string) $booking->distance,
                'duration' => (string) $booking->duration,
                'estimated_fare' => $booking->estimated_fare ? (string) $booking->estimated_fare : '',
                'final_fare' => $booking->final_fare ? (string) $booking->final_fare : '',
                'payment_method' => $booking->payment_method ?? '',
                'status' => $booking->status ?? '',
                'trip_code' => $booking->trip_code ?? '',
                'created_at' => $booking->created_at ? $booking->created_at->toISOString() : '',
                'updated_at' => $booking->updated_at ? $booking->updated_at->toISOString() : '',
            ];

            $openDebts = UserDebt::where('user_id', $booking->user_id)
                ->where('status', 'pending')
                ->get();

            $totalDebtAmount = $openDebts->sum(function ($debt) {
                return max(0, (float) $debt->amount - (float) $debt->amount_settled);
            });

            $responseData = [
                'booking' => $formattedBooking,
                // 'debt_amount' => (string) ($booking->debt_amount ?? '0'),
                'trip_code' => $booking->trip_code ?? '',
                'ride_type_estimate' => $rideTypeEstimate,  // Ride type estimate data
                'ride_options' => $rideOptions,  // Ride type data (all available options)
                'detected_city' => [
                    'id' => (string) $city->id,
                    'name' => $city->name,
                    'distance_from_center' => $city->distance ? (string) round($city->distance, 2) : '',
                ],
            ];

            // Only include debt_amount if it's greater than 0
            if ($totalDebtAmount > 0) {
                $responseData['debt_amount'] = (string) $totalDebtAmount;
            }

            return response()->json([
                'success' => true,
                'message' => 'Booking created successfully',
                'data' => $responseData,
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
                'message' => 'Failed to create booking',
                'error' => $e->getMessage(),
                'debug_info' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            ], 500);
        }
    }

    public function updateBookingDetails(Request $request, Booking $booking): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user || (int) $booking->user_id !== (int) $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this booking',
                ], 403);
            }

            $data = $request->validate([
                'ride_type_id' => ['required', 'exists:ride_types,id'],
                'payment_method' => ['required', 'in:wallet,cash,card'],
            ]);

            $booking->update([
                'ride_type_id' => $data['ride_type_id'],
                'payment_method' => $data['payment_method'],
            ]);

            $fareService = app(FareService::class);
            $fareParams = [
                'city_id' => $booking->city_id,
                'ride_type_id' => $data['ride_type_id'],
                'pickup_latitude' => $booking->pickup_latitude,
                'pickup_longitude' => $booking->pickup_longitude,
                'distance' => $booking->distance,
                'duration' => $booking->duration,
            ];
            $fareBreakdown = $fareService->calculateEstimatedFare($fareParams);
            $estimatedFare = $fareBreakdown['total'];

            $booking->update([
                'estimated_distance' => $booking->distance,  // Store estimated distance for minimum billing
                'estimated_duration' => $booking->duration,  // Store estimated duration for minimum billing
                'estimated_fare' => $estimatedFare,
                'final_fare' => $estimatedFare,
                'surge_multiplier' => $fareBreakdown['surge_multiplier'],
                'surge_amount' => $fareBreakdown['surge_amount'],
            ]);

            $etaService = app(ETAService::class);
            $etaData = $etaService->calculateETA(
                $booking->pickup_latitude,
                $booking->pickup_longitude,
                $booking->city_id,
                $data['ride_type_id']
            );

            $estimatedArrivalTime = now()->addMinutes($etaData['estimated_eta']);

            $responseData = [
                'booking' => $this->formatBookingToStrings($booking->fresh()),
                'trip_code' => (string) ($booking->trip_code ?? ''),
                'estimated_eta' => (string) ($etaData['estimated_eta'] ?? ''),
                'estimate_arrived_time' => $estimatedArrivalTime ? $estimatedArrivalTime->format('Y-m-d H:i:s') : '',
                'eta_breakdown' => $this->formatEtaBreakdownToStrings($etaData['breakdown'] ?? []),
                'fare_breakdown' => $this->formatFareBreakdownToStrings($fareBreakdown),
            ];

            if (isset($etaData['matched_driver'])) {
                $responseData['matched_driver'] = $this->formatDriverInfoToStrings($etaData['matched_driver']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Booking details updated successfully',
                'data' => $responseData,
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
                'message' => 'Failed to update booking details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updatePaymentMethod(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'booking_id' => ['required', 'exists:bookings,id'],
                'payment_method' => ['required', 'in:paypal,razorpay,cash,paytm,stripe,wallet'],
            ]);

            $user = $request->user();
            $booking = Booking::findOrFail($data['booking_id']);

            if (!$user || (int) $booking->user_id !== (int) $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this booking'
                ], 403);
            }

            if (in_array($booking->status, ['completed', 'cancelled'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update payment method for completed or cancelled booking'
                ], 400);
            }

            $booking->update([
                'payment_method' => $data['payment_method']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment method updated successfully',
                'data' => [
                    'booking_id' => (string) $booking->id,
                    'payment_method' => $booking->payment_method,
                    'status' => $booking->status
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
                'message' => 'Failed to update payment method',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function cancel(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'booking_id' => ['required', 'exists:bookings,id'],
                'reason' => ['required', 'string', 'max:255'],
            ]);

            $user = $request->user();
            $booking = Booking::findOrFail($data['booking_id']);

            if ($booking->driver_id) {
                User::where('id', $booking->driver_id)
                    ->where('current_booking_id', $booking->id)
                    ->update(['current_booking_id' => null]);

                

                
            }

            if (!$user || (int) $booking->user_id !== (int) $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this booking',
                ], 403);
            }

            if ($booking->status === 'cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking Already Canceled',
                ], 400);
            }

            DB::transaction(function () use ($booking, $request, $user) {
                if ($booking->status === 'searching') {
                    $this->driverMatching->cancelMatching($booking, $request->reason);
                } elseif ($booking->status === 'expired') {
                    // Allow cancellation of expired bookings
                    $booking->update([
                        'status' => 'cancelled',
                        'cancelled_by_type' => User::class,
                        'cancelled_by_id' => $user->id,
                        'reason' => $request->reason,
                        'cancelled_at' => now(),
                    ]);
                } else {
                    $this->bookingService->cancelBooking($booking, [
                        'cancelled_by_type' => User::class,
                        'cancelled_by_id' => $user->id,
                        'reason' => $request->reason,
                    ]);
                }

                event(new UserCancelledBooking($booking->fresh()));
            });

            $booking = $booking->refresh()->load(['user', 'driver', 'rideType']);

            return response()->json([
                'success' => true,
                'message' => 'Booking cancelled successfully',
                'data' => [
                    'booking_id' => (string) $booking->id,
                    'status' => (string) $booking->status,
                    'cancellation_charge' => (string) ($booking->cancellation_charge ?? ''),
                    'cancelled_at' => $booking->cancelled_at ? $booking->cancelled_at->toISOString() : '',
                    'customer' => $this->formatCustomerData($booking->user),
                    'driver' => $booking->driver ? $this->formatDriverData($booking->driver) : '',
                    'ride_type' => $booking->rideType ? $this->formatRideTypeData($booking->rideType) : '',
                ]
            ]);
        } catch (ValidationException $e) {
            $errorMessages = [];
            foreach ($e->errors() as $field => $messages) {
                $errorMessages[] = implode(', ', $messages);
            }
            return response()->json([
                'success' => false,
                'message' => 'Validation failed: ' . implode(' ', $errorMessages),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel booking: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getCurrentBooking(Request $request): JsonResponse
    {
        $booking = Booking::where('user_id', $request->user()->id)
            ->whereIn('status', ['pending', 'searching', 'accepted', 'arrived', 'started'])
            ->with(['driver.currentLocation', 'rideType', 'pickupZone', 'dropoffZone'])
            ->latest()
            ->first();

        if (!$booking) {
            return response()->json(['booking' => '']);
        }

        $data = $this->formatBookingToStrings($booking);

        if ($booking->driver_id) {
            $data['driver_location'] = (string) ($booking->driver->currentLocation?->location ?? '');
            $data['driver_heading'] = (string) ($booking->driver->currentLocation?->heading ?? '');
        }

        return response()->json(['booking' => $data]);
    }

    public function getBookingHistory(Request $request): JsonResponse
    {
        $bookings = Booking::where('user_id', $request->user()->id)
            ->whereIn('status', ['completed', 'cancelled'])
            ->with(['driver', 'rideType'])
            ->latest()
            ->paginate($request->input('per_page', 10));

        return response()->json($bookings);
    }

    public function rateDriver(Request $request, Booking $booking): JsonResponse
    {
        if (!$booking->isCompleted() || $booking->user_id !== $request->user()->id) {
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

        $booking->driver->driverProfile->updateStats();

        return response()->json([
            'message' => 'Rating submitted successfully',
            'booking' => $booking->only('id', 'driver_rating', 'driver_review'),
        ]);
    }

    public function selectRideType(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'booking_id' => ['required', 'exists:bookings,id'],
                'ride_type_id' => ['required', 'exists:ride_types,id'],
                'payment_type' => ['nullable', 'string', 'max:255'],
            ]);

            $user = $request->user();
            $booking = Booking::findOrFail($data['booking_id']);
            
            if (!$user || (int) $booking->user_id !== (int) $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this booking',
                ], 403);
            }

            if (!$user->isActive()) {
                $statusMessage = match ($user->status) {
                    'inactive' => 'Your account is inactive. Please contact support to activate your account.',
                    'blocked' => 'Your account has been blocked. Please contact support for assistance.',
                    'under_review' => 'Your account is under review. Please wait for the review to complete before selecting ride types.',
                    default => 'Your account is not active. Please contact support for assistance.',
                };

                return response()->json([
                    'success' => false,
                    'message' => $statusMessage,
                ], 403);
            }

            $applypromocode = $booking->promoUsage ? true : false;

            if ($applypromocode) {
                $rideTypeId = $request->input('ride_type_id');

                $promo = $booking->promoUsage->promoCode;

                $applicableRideTypes = db::table('promo_code_ride_types')->where('promo_code_id', $promo->id)->get();

                $applicableRideTypesIds = $applicableRideTypes->pluck('ride_type_id');

                if (!in_array($rideTypeId, $applicableRideTypesIds->toArray())) {
                    $booking->update(['promo_code' => null]);
                    DB::table('promo_usages')->where('booking_id', $booking->id)->delete();

                    return response()->json([
                        'success' => false,
                        'message' => 'Promo code is not applicable for this ride type'
                    ], 400);
                }
            }

            // Force taxi ride type in demo mode
            if (\App\Services\DemoModeService::isEnabled()) {
                $demoRideTypeId = \App\Services\DemoModeService::getDemoRideTypeId();
                if ($demoRideTypeId) {
                    $data['ride_type_id'] = $demoRideTypeId;
                }
            }
            
            $booking->update([
                'ride_type_id' => $data['ride_type_id'],
                'payment_method' => $data['payment_type'],
                'status' => 'pending'  // Keep as pending until is_conform is called
            ]);
            $fareService = app(FareService::class);
            $fareParams = [
                'city_id' => $booking->city_id,
                'ride_type_id' => $data['ride_type_id'],
                'pickup_latitude' => $booking->pickup_latitude,
                'pickup_longitude' => $booking->pickup_longitude,
                'distance' => $booking->distance,
                'duration' => $booking->duration,
                'discount_amount' => $booking->discount_amount,
            ];
            $fareBreakdown = $fareService->calculateEstimatedFare($fareParams);
            $estimatedFare = $fareBreakdown['total'];

            $booking->update([
                'estimated_distance' => $booking->distance,  // Store estimated distance for minimum billing
                'estimated_duration' => $booking->duration,  // Store estimated duration for minimum billing
                'estimated_fare' => $estimatedFare,
                'final_fare' => $estimatedFare,
                'surge_multiplier' => $fareBreakdown['surge_multiplier'],
                'surge_amount' => $fareBreakdown['surge_amount'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Ride type selected successfully',
                'data' => [
                    'booking' => $this->formatBookingToStrings($booking->fresh()),
                    'fare_breakdown' => $this->formatFareBreakdownToStrings($fareBreakdown),
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
                'message' => 'Failed to select ride type',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updatePickup(Request $request, Booking $booking): JsonResponse
    {
        try {
            $data = $request->validate([
                'pickup_latitude' => ['required', 'numeric', 'between:-90,90'],
                'pickup_longitude' => ['required', 'numeric', 'between:-180,180'],
                'pickup_address' => ['required', 'string', 'max:500'],
            ]);

            $data['pickup_latitude'] = (float) $data['pickup_latitude'];
            $data['pickup_longitude'] = (float) $data['pickup_longitude'];

            $user = $request->user();
            if (!$user || (int) $booking->user_id !== (int) $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this booking',
                ], 403);
            }

            if (!in_array($booking->status, ['pending', 'searching'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pickup location can only be updated for pending or searching bookings',
                ], 400);
            }

            $cityService = app(CityService::class);
            $isServiceable = $cityService->isLocationServiceable(
                $booking->city_id,
                $data['pickup_latitude'],
                $data['pickup_longitude']
            );

            if (!$isServiceable) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not available at the new pickup location',
                ], 400);
            }

            $newPickupZone = Zone::where('city_id', $booking->city_id)
                ->active()
                ->first();  // Get the first active zone for the city

            $googleMapsService = app(GoogleMapsService::class);
            $routeData = $googleMapsService->getDistanceAndDuration(
                $data['pickup_latitude'],
                $data['pickup_longitude'],
                $booking->dropoff_latitude,
                $booking->dropoff_longitude
            );

            $booking->update([
                'pickup_latitude' => $data['pickup_latitude'],
                'pickup_longitude' => $data['pickup_longitude'],
                'pickup_address' => $data['pickup_address'],
                'pickup_location' => 'POINT(' . (string) $data['pickup_longitude'] . ' ' . (string) $data['pickup_latitude'] . ')',
                'pickup_zone_id' => $newPickupZone ? $newPickupZone->id : $booking->pickup_zone_id,
                'distance' => $routeData['distance'],
                'duration' => $routeData['duration'],
                'estimated_distance' => $routeData['distance'],  // Update estimated distance for minimum billing
                'estimated_duration' => $routeData['duration'],  // Update estimated duration for minimum billing
            ]);

            if ($booking->ride_type_id) {
                $fareService = app(FareService::class);
                $fareParams = [
                    'city_id' => $booking->city_id,
                    'ride_type_id' => $booking->ride_type_id,
                    'pickup_latitude' => $data['pickup_latitude'],
                    'pickup_longitude' => $data['pickup_longitude'],
                    'distance' => $routeData['distance'],
                    'duration' => $routeData['duration'],
                    'discount_amount' => $booking->discount_amount,
                ];
                $fareBreakdown = $fareService->calculateEstimatedFare($fareParams);
                $estimatedFare = $fareBreakdown['total'];

                $booking->update([
                    'estimated_distance' => $routeData['distance'],  // Update estimated distance for minimum billing
                    'estimated_duration' => $routeData['duration'],  // Update estimated duration for minimum billing
                    'estimated_fare' => $estimatedFare,
                    'final_fare' => $estimatedFare,
                    'surge_multiplier' => $fareBreakdown['surge_multiplier'],
                    'surge_amount' => $fareBreakdown['surge_amount'],
                ]);
            }

            if ($booking->status === 'searching') {
                $this->driverNotificationService->startDriverNotification($booking);
            }

            $etaData = null;
            if ($booking->ride_type_id) {
                $etaService = app(ETAService::class);
                $etaData = $etaService->calculateETA(
                    $data['pickup_latitude'],
                    $data['pickup_longitude'],
                    $booking->city_id,
                    $booking->ride_type_id
                );
            }

            $responseData = [
                'booking' => $this->formatBookingToStrings($booking->fresh()),
                'distance' => (string) ($routeData['distance'] ?? ''),
                'duration' => (string) ($routeData['duration'] ?? ''),
                'pickup_zone' => $newPickupZone ? [
                    'id' => (string) $newPickupZone->id,
                    'name' => (string) ($newPickupZone->name ?? ''),
                ] : '',
            ];

            if ($booking->ride_type_id) {
                $responseData['fare_breakdown'] = $this->formatFareBreakdownToStrings($fareBreakdown);
            }

            if ($etaData) {
                $estimatedArrivalTime = now()->addMinutes($etaData['estimated_eta']);
                $responseData['estimated_eta'] = (string) ($etaData['estimated_eta'] ?? '');
                $responseData['estimate_arrived_time'] = $estimatedArrivalTime ? $estimatedArrivalTime->format('Y-m-d H:i:s') : '';
                $responseData['eta_breakdown'] = $this->formatEtaBreakdownToStrings($etaData['breakdown'] ?? []);

                if (isset($etaData['matched_driver'])) {
                    $responseData['matched_driver'] = $this->formatDriverInfoToStrings($etaData['matched_driver']);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Pickup location updated successfully',
                'data' => $responseData,
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
                'message' => 'Failed to update pickup location',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;  // Earth's radius in kilometers

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earthRadius * $c;

        return round($distance, 2);
    }

    private function calculateDuration(float $distance): int
    {
        $averageSpeed = 30;  // km/h
        $durationHours = $distance / $averageSpeed;
        $durationMinutes = $durationHours * 60;

        return (int) round($durationMinutes);
    }

    private function calculateFallbackFare(float $distance, float $duration, int $cityId = 1, int $rideTypeId = 1): float
    {
        try {
            $city = City::find($cityId);
            $rideType = RideType::find($rideTypeId);

            if (!$city || !$rideType) {
                return $this->getStaticFallbackFare($distance, $duration);
            }

            $pricing = $rideType->getPriceForCity($city);

            $baseFare = $pricing['base_price'] ?? 50.0;
            $perKmRate = $pricing['price_per_km'] ?? 12.0;
            $perMinuteRate = $pricing['price_per_minute'] ?? 2.0;
            $minimumFare = $pricing['minimum_fare'] ?? 80.0;
            $baseDistance = $pricing['base_distance'] ?? 0.0;

            $extraDistance = max(0, $distance - $baseDistance);
            $distanceFare = $extraDistance * $perKmRate;

            $timeFare = $duration * $perMinuteRate;

            $total = $baseFare + $distanceFare + $timeFare;

            return max($total, $minimumFare);
        } catch (\Exception $e) {
            return $this->getStaticFallbackFare($distance, $duration);
        }
    }

    private function getStaticFallbackFare(float $distance, float $duration): float
    {
        $baseFare = 50.0;
        $perKmRate = 12.0;
        $perMinuteRate = 2.0;

        $distanceFare = $distance * $perKmRate;
        $timeFare = $duration * $perMinuteRate;

        $total = $baseFare + $distanceFare + $timeFare;

        return max($total, 80.0);
    }

    private function calculatePerfectEstimatedFare(array $params): float
    {
        try {
            $city = City::find($params['city_id']);
            $rideType = RideType::find($params['ride_type_id']);

            if (!$city || !$rideType) {
                return $this->getStaticFallbackFare($params['distance'], $params['duration']);
            }

            $pricing = $rideType->getPriceForCity($city);

            $baseFare = $pricing['base_price'] ?? 50.0;
            $perKmRate = $pricing['price_per_km'] ?? 12.0;
            $perMinuteRate = $pricing['price_per_minute'] ?? 2.0;
            $minimumFare = $pricing['minimum_fare'] ?? 80.0;
            $baseDistance = $pricing['base_distance'] ?? 0.0;

            $extraDistance = max(0, $params['distance'] - $baseDistance);
            $distanceFare = $extraDistance * $perKmRate;
            $timeFare = $params['duration'] * $perMinuteRate;

            $subtotal = $baseFare + $distanceFare + $timeFare;

            $nightChargeMultiplier = $this->getNightChargeMultiplier();
            $nightChargeAmount = 0;

            if ($nightChargeMultiplier > 1.0) {
                $nightChargeAmount = $subtotal * ($nightChargeMultiplier - 1.0);
            }

            $total = $subtotal + $nightChargeAmount;

            $total = max($total, $minimumFare);

            $bookingFee = 0;  // $this->getBookingFee($city); // Disabled to match FareService behavior
            $total += $bookingFee;

            $enhancedFareService = app(\App\Services\EnhancedFareCalculationService::class);
            $taxData = $enhancedFareService->calculateTaxes($city, $total);
            $total += $taxData['total_tax_amount'];

            return round($total, 2);
        } catch (\Exception $e) {
            return $this->getStaticFallbackFare($params['distance'], $params['duration']);
        }
    }

    private function isNightTime(): bool
    {
        $currentHour = (int) now()->format('H');
        return $currentHour >= 22 || $currentHour < 6;  // 10 PM to 6 AM
    }

    private function getNightChargeMultiplier(): float
    {
        if ($this->isNightTime()) {
            return 1.25;  // 25% night charge (10 PM - 6 AM)
        }
        return 1.0;  // No night charge
    }

    private function getBookingFee(City $city): float
    {
        switch (strtolower($city->name)) {
            case 'mumbai':
                return 5.0;
            case 'delhi':
                return 7.0;
            case 'bangalore':
                return 6.0;
            default:
                return 5.0;
        }
    }

    private function formatBookingToStrings($booking): array
    {
        if (!$booking) {
            return [];
        }

        return [
            'id' => (string) ($booking->id ?? ''),
            'booking_code' => (string) ($booking->booking_code ?? ''),
            'user_id' => (string) ($booking->user_id ?? ''),
            'driver_id' => (string) ($booking->driver_id ?? ''),
            'city_id' => (string) ($booking->city_id ?? ''),
            'ride_type_id' => (string) ($booking->ride_type_id ?? ''),
            'pickup_zone_id' => (string) ($booking->pickup_zone_id ?? ''),
            'dropoff_zone_id' => (string) ($booking->dropoff_zone_id ?? ''),
            'pickup_latitude' => (string) ($booking->pickup_latitude ?? ''),
            'pickup_longitude' => (string) ($booking->pickup_longitude ?? ''),
            'pickup_address' => (string) ($booking->pickup_address ?? ''),
            'dropoff_latitude' => (string) ($booking->dropoff_latitude ?? ''),
            'dropoff_longitude' => (string) ($booking->dropoff_longitude ?? ''),
            'dropoff_address' => (string) ($booking->dropoff_address ?? ''),
            'distance' => (string) ($booking->distance ?? ''),
            'duration' => (string) ($booking->duration ?? ''),
            'estimated_fare' => (string) ($booking->estimated_fare ?? ''),
            'final_fare' => (string) ($booking->final_fare ?? ''),
            'payment_method' => (string) ($booking->payment_method ?? ''),
            'payment_status' => (string) ($booking->payment_status ?? ''),
            'status' => (string) ($booking->status ?? ''),
            'is_confirm' => (string) ($booking->is_confirm ?? ''),
            'trip_code' => (string) ($booking->trip_code ?? ''),
            'otp' => (string) ($booking->otp ?? ''),
            'base_fare' => (string) ($booking->base_fare ?? ''),
            'distance_fare' => (string) ($booking->distance_fare ?? ''),
            'time_fare' => (string) ($booking->time_fare ?? ''),
            'waiting_charge' => (string) ($booking->waiting_charge ?? ''),
            'cancellation_charge' => (string) ($booking->cancellation_charge ?? ''),
            'night_charge' => (string) ($booking->night_charge ?? ''),
            'surge_multiplier' => (string) ($booking->surge_multiplier ?? ''),
            'surge_amount' => (string) ($booking->surge_amount ?? ''),
            'subtotal' => (string) ($booking->subtotal ?? ''),
            'tax_rate' => (string) ($booking->tax_rate ?? ''),
            'tax_amount' => (string) ($booking->tax_amount ?? ''),
            'total_amount' => (string) ($booking->total_amount ?? ''),
            'created_at' => $booking->created_at ? $booking->created_at->toISOString() : '',
            'updated_at' => $booking->updated_at ? $booking->updated_at->toISOString() : '',
        ];
    }

    private function formatFareBreakdownToStrings($fareBreakdown): array
    {
        if (!is_array($fareBreakdown)) {
            return [];
        }

        $formatted = [];
        foreach ($fareBreakdown as $key => $value) {
            if (is_numeric($value)) {
                $formatted[$key] = (string) $value;
            } elseif (is_array($value)) {
                if ($key === 'tax_breakdown') {
                    $formatted[$key] = $value;
                } else {
                    $formatted[$key] = json_encode($value);
                }
            } else {
                $formatted[$key] = (string) ($value ?? '');
            }
        }
        return $formatted;
    }

    private function sendBookingConfirmationNotification(Booking $booking): void
    {
        try {
            $booking->load('user');

            
        } catch (\Exception $e) {
        }
    }

    private function sendFCMNotificationsToDrivers(Booking $booking, array $notificationResults): void
    {
        try {
            $driverIds = $notificationResults['driver_ids'] ?? [];

            if (empty($driverIds)) {
                
                return;
            }

            if (!is_array($driverIds)) {
                $driverIds = [$driverIds];
            }

            

            foreach ($driverIds as $driverId) {
                try {
                    $driverId = is_string($driverId) ? (int) $driverId : $driverId;

                    $driver = \App\Models\User::find($driverId);

                    if (!$driver) {
                        
                        continue;
                    }

                    $originalDriverId = $booking->driver_id;
                    $booking->driver_id = $driver->id;
                    $booking->setRelation('driver', $driver);

                    $this->notificationService->sendBookingNotificationToDriver(
                        $booking,
                        'new_booking',
                        "New ride request nearby. Fare: ₹{$booking->estimated_fare}"
                    );

                    

                    $booking->driver_id = $originalDriverId;
                    if (!$originalDriverId) {
                        $booking->unsetRelation('driver');
                    }
                } catch (\Exception $e) {
                }
            }
        } catch (\Exception $e) {
        }
    }

    private function formatEtaBreakdownToStrings($etaBreakdown): array
    {
        if (!is_array($etaBreakdown)) {
            return [];
        }

        $formatted = [];
        foreach ($etaBreakdown as $key => $value) {
            if (is_numeric($value)) {
                $formatted[$key] = (string) $value;
            } elseif (is_array($value)) {
                $formatted[$key] = json_encode($value);
            } else {
                $formatted[$key] = (string) ($value ?? '');
            }
        }
        return $formatted;
    }

    private function formatDriverInfoToStrings($driverInfo): array
    {
        if (!is_array($driverInfo)) {
            return [];
        }

        return [
            'id' => (string) ($driverInfo['id'] ?? ''),
            'name' => (string) ($driverInfo['name'] ?? ''),
            'phone' => (string) ($driverInfo['phone'] ?? ''),
            'vehicle' => is_array($driverInfo['vehicle'] ?? null) ? $this->formatVehicleToStrings($driverInfo['vehicle']) : '',
            'distance_to_pickup' => (string) ($driverInfo['distance_to_pickup'] ?? ''),
            'driver_latitude' => (string) ($driverInfo['driver_latitude'] ?? ''),
            'driver_longitude' => (string) ($driverInfo['driver_longitude'] ?? ''),
        ];
    }

    private function formatVehicleToStrings($vehicle): array
    {
        if (!is_array($vehicle)) {
            return [];
        }

        return [
            'id' => (string) ($vehicle['id'] ?? ''),
            'model' => (string) ($vehicle['model'] ?? ''),
            'registration_number' => (string) ($vehicle['registration_number'] ?? ''),
            'color' => (string) ($vehicle['color'] ?? ''),
            'year' => (string) ($vehicle['year'] ?? ''),
        ];
    }

    private function formatNotificationResultsToStrings($results): array
    {
        if (!is_array($results)) {
            return [];
        }

        $formatted = [];
        foreach ($results as $key => $value) {
            if (is_array($value)) {
                $formatted[$key] = $this->formatNotificationResultsToStrings($value);
            } else {
                $formatted[$key] = (string) ($value ?? '');
            }
        }
        return $formatted;
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

    private function getRideOptions(int $cityId, float $distance, float $duration, array $location): array
    {
        $city = City::find($cityId);
        $currentTime = now();
        $rideOptions = [];

        $rideTypeIds = DB::table('available_ride_cities')
            ->where('city_id', $cityId)
            ->pluck('ride_type_id')
            ->toArray();

        $rideTypes = RideType::where('status', 1)
            ->whereIn('id', $rideTypeIds)
            ->get();

        $etaService = app(ETAService::class);
        $fareService = app(FareService::class);

        foreach ($rideTypes as $rideType) {
            

            $realTimeDriverData = $this->getRealTimeDriverData($rideType->id, $cityId, $location);

            $etaData = $etaService->calculateETA(
                $location['pickup_latitude'] ?? 0,
                $location['pickup_longitude'] ?? 0,
                $cityId,
                $rideType->id
            );

            $fareParams = [
                'city_id' => $cityId,
                'ride_type_id' => $rideType->id,
                'pickup_latitude' => $location['pickup_latitude'] ?? 0,
                'pickup_longitude' => $location['pickup_longitude'] ?? 0,
                'distance' => $distance,
                'duration' => $duration,
            ];

            $fareBreakdown = $fareService->calculateEstimatedFare($fareParams);
            $basePrice = $fareBreakdown['total'] ?? 0;

            

            $surgeMultiplier = $this->calculateRealTimeSurge($rideType->id, $cityId, $realTimeDriverData);

            

            $currentPrice = $basePrice;

            $estimatedEta = $etaData['estimated_eta'] ?? $this->calculateFallbackETA($distance);
            $dropoffTime = $currentTime->copy()->addMinutes($estimatedEta + $duration);

            $capacity = $this->getRealTimeCapacity($rideType);

            $discountData = $this->getRealTimeDiscount($rideType, $city, $currentPrice, $realTimeDriverData, $surgeMultiplier);

            $rideOption = $this->createCompletelyDynamicRideOption(
                $rideType,
                $capacity,
                $estimatedEta,
                $dropoffTime,
                $currentPrice,
                $discountData,
                $city,
                $distance,
                $duration,
                $realTimeDriverData,
                $surgeMultiplier,
                $etaData
            );

            if ($rideOption) {
                $rideOptions[] = $rideOption;
            }
        }

        usort($rideOptions, function ($a, $b) {
            if ($a['is_available'] !== $b['is_available']) {
                return $b['is_available'] <=> $a['is_available'];
            }
            return (float) $a['current_price'] <=> (float) $b['current_price'];
        });

        return $rideOptions;
    }

    private function createDynamicRideOption(
        $rideType,
        string $capacity,
        int $estimatedEta,
        $dropoffTime,
        float $currentPrice,
        array $discountData,
        City $city,
        float $distance,
        float $duration
    ): array {
        return [
            'type' => (string) ($rideType->name ?? ''),
            'description' => (string) ($rideType->description ?? $rideType->name ?? ''),
            'icon' => $this->getRideTypeIconUrl($rideType->icon ?? $this->getDefaultIcon($rideType->name ?? '')),
            'capacity' => (string) $capacity,
            'estimated_time' => (string) ($estimatedEta . ' mins'),
            'dropoff_time' => (string) ($dropoffTime ? $dropoffTime->format('g:i A') : ''),
            'current_price' => (string) round($currentPrice, 2),
            'original_price' => (string) ($discountData['original_price'] ?? ''),
            'has_discount' => null,
            'discount_percentage' => (string) ($discountData['discount_percentage'] ?? ''),
            'ride_type_id' => (string) ($rideType->id ?? ''),
            'surge_multiplier' => (string) $this->getSurgeMultiplier($rideType, $city),
            'is_available' => (string) 'true',
            'driver_count' => (string) $this->getAvailableDriverCount($rideType->id, $city->id),
        ];
    }

    private function getRealTimeDriverData(int $rideTypeId, int $cityId, array $location): array
    {
        $drivers = \App\Models\User::where('role_id', 2)  // Driver role
            ->where('status', 'active')  // Use 'active' instead of 'online'
            ->whereHas('vehicles', function ($query) use ($rideTypeId) {
                $query
                    ->where('ride_type_id', $rideTypeId)
                    ->where('status', 'active');
            })
            ->with('currentLocation')  // Eager load currentLocation to prevent lazy loading
            ->get();

        $availableDrivers = 0;
        $nearestDriverDistance = null;
        $averageDistance = 0;

        if ($drivers->count() > 0) {
            $availableDrivers = $drivers->count();

            $distances = [];
            foreach ($drivers as $driver) {
                if ($driver->currentLocation) {
                    try {

                        $distance = $this->calculateDistance(
                            $location['pickup_latitude'],
                            $location['pickup_longitude'],
                            $driver->currentLocation->latitude,
                            $driver->currentLocation->longitude
                        );
                        $distances[] = $distance;

                        if ($nearestDriverDistance === null || $distance < $nearestDriverDistance) {
                            $nearestDriverDistance = $distance;
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }

            $averageDistance = count($distances) > 0 ? array_sum($distances) / count($distances) : 0;
        }

        return [
            'available_drivers' => $availableDrivers,
            'nearest_driver_distance' => $nearestDriverDistance,
            'average_distance' => $averageDistance,
            'total_online_drivers' => $drivers->count(),
        ];
    }

    private function calculateRealTimeSurge(int $rideTypeId, int $cityId, array $driverData): float
    {
        $baseSurge = 1.0;
        $availableDrivers = $driverData['available_drivers'];

        $recentBookings = \App\Models\Booking::where('ride_type_id', $rideTypeId)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count();

        if ($availableDrivers === 0) {
            return 2.0;  // 100% surge if no drivers
        } elseif ($availableDrivers <= 2) {
            $baseSurge = 1.5;  // 50% surge if very few drivers
        } elseif ($availableDrivers <= 5) {
            $baseSurge = 1.2;  // 20% surge if few drivers
        }

        if ($recentBookings > 10) {
            $baseSurge += 0.3;  // Additional 30% for high demand
        } elseif ($recentBookings > 5) {
            $baseSurge += 0.15;  // Additional 15% for medium demand
        }

        $currentHour = (int) now()->format('H');
        if (($currentHour >= 7 && $currentHour <= 9) || ($currentHour >= 17 && $currentHour <= 19)) {
            $baseSurge += 0.2;  // 20% surge during rush hours
        }

        return min($baseSurge, 3.0);  // Cap at 200% surge
    }

    private function calculateFallbackETA(float $distance): int
    {
        return max(2, (int) round(2 + $distance));
    }

    private function getRealTimeCapacity($rideType): string
    {
        $vehicles = \App\Models\Vehicle::where('ride_type_id', $rideType->id)
            ->where('status', 'active')
            ->get();

        if ($vehicles->count() > 0) {
            $capacities = $vehicles->pluck('capacity')->filter()->toArray();
            if (!empty($capacities)) {
                $capacity = array_count_values($capacities);
                $mostCommonCapacity = array_keys($capacity, max($capacity))[0];
                return str_pad($mostCommonCapacity, 2, '0', STR_PAD_LEFT);
            }
        }

        if (!empty($rideType->capacity)) {
            return str_pad($rideType->capacity, 2, '0', STR_PAD_LEFT);
        }

        $capacity = 1;
        $name = strtolower($rideType->name ?? '');

        if (strpos($name, 'bike') !== false || strpos($name, 'motorcycle') !== false) {
            $capacity = 2;
        } elseif (strpos($name, 'auto') !== false || strpos($name, 'rickshaw') !== false) {
            $capacity = 3;
        } elseif (strpos($name, 'cab') !== false || strpos($name, 'car') !== false) {
            $capacity = 4;
        } elseif (strpos($name, 'bus') !== false) {
            $capacity = 20;
        }

        return str_pad($capacity, 2, '0', STR_PAD_LEFT);
    }

    private function getRealTimeDiscount($rideType, City $city, float $currentPrice, array $driverData, float $surgeMultiplier = 1.0): array
    {
        $hasDiscount = false;
        $originalPrice = '';
        $discountPercentage = '';

        if ($this->isOffPeakHours() && $surgeMultiplier > 1.0) {
            $discountPercentage = round((1 - (1 / $surgeMultiplier)) * 100);
            $originalPrice = (string) round($currentPrice * $surgeMultiplier, 2);
            $hasDiscount = true;
        } elseif ($driverData['available_drivers'] > 10) {
            $discountPercentage = 15;
            $originalPrice = (string) round($currentPrice / (1 - $discountPercentage / 100), 2);
            $hasDiscount = true;
        } elseif ($this->isNewUserDiscount()) {
            $discountPercentage = 25;
            $originalPrice = (string) round($currentPrice / (1 - $discountPercentage / 100), 2);
            $hasDiscount = true;
        } else {
            $cityPromotion = $this->getCityPromotion($city);
            if ($cityPromotion && $cityPromotion['ride_type_id'] == $rideType->id) {
                $discountPercentage = $cityPromotion['discount_percentage'];
                $originalPrice = (string) round($currentPrice / (1 - $discountPercentage / 100), 2);
                $hasDiscount = true;
            }
        }

        if (!$hasDiscount && $surgeMultiplier > 1.0) {
            $discountPercentage = round((1 - (1 / $surgeMultiplier)) * 100);
            $originalPrice = (string) round($currentPrice * $surgeMultiplier, 2);
            $hasDiscount = true;
        }

        return [
            'has_discount' => null,
            'original_price' => (string) $originalPrice,
            'discount_percentage' => (string) $discountPercentage,
        ];
    }

    private function isNewUserDiscount(): bool
    {
        return false;
    }

    private function createCompletelyDynamicRideOption(
        $rideType,
        string $capacity,
        int $estimatedEta,
        $dropoffTime,
        float $currentPrice,
        array $discountData,
        City $city,
        float $distance,
        float $duration,
        array $driverData,
        float $surgeMultiplier,
        array $etaData
    ): array {
        return [
            'type' => (string) ($rideType->name ?? ''),
            'description' => (string) ($rideType->description ?? $rideType->name ?? ''),
            'icon' => $this->getRideTypeIconUrl($rideType->icon ?? $this->getDefaultIcon($rideType->name ?? '')),
            'capacity' => (string) $capacity,
            'estimated_time' => (string) (round($estimatedEta + $duration) . ' mins'),
            'dropoff_time' => (string) ($dropoffTime ? $dropoffTime->format('g:i A') : ''),
            'current_price' => (string) round($currentPrice, 2),
            'original_price' => (string) ($discountData['original_price'] ?? ''),
            'has_discount' => null,
            'discount_percentage' => (string) ($discountData['discount_percentage'] ?? ''),
            'ride_type_id' => (string) ($rideType->id ?? ''),
            'surge_multiplier' => (string) round($surgeMultiplier, 2),
            'is_available' => (string) ($driverData['available_drivers'] > 0 ? 'true' : 'false'),
            'driver_count' => (string) $driverData['available_drivers'],
            'nearest_driver_distance' => (string) round($driverData['nearest_driver_distance'] ?? 0, 2),
            'average_driver_distance' => (string) round($driverData['average_distance'] ?? 0, 2),
            'eta_confidence' => (string) ($etaData['confidence'] ?? 'medium'),
            'estimated_dropoff_time' => (string) ($dropoffTime ? $dropoffTime->format('Y-m-d H:i:s') : ''),
        ];
    }

    private function getDynamicDiscount($rideType, City $city, float $currentPrice): array
    {
        $hasDiscount = false;
        $originalPrice = '';
        $discountPercentage = '';

        if ($this->isOffPeakHours()) {
            $discountPercentage = 20;  // 20% off during off-peak
            $originalPrice = (string) round($currentPrice / (1 - $discountPercentage / 100), 2);
            $hasDiscount = true;
        }

        if (isset($rideType->discount_percentage) && $rideType->discount_percentage > 0) {
            $discountPercentage = $rideType->discount_percentage;
            $originalPrice = (string) round($currentPrice / (1 - $discountPercentage / 100), 2);
            $hasDiscount = true;
        }

        $cityPromotion = $this->getCityPromotion($city);
        if ($cityPromotion && $cityPromotion['ride_type_id'] == $rideType->id) {
            $discountPercentage = $cityPromotion['discount_percentage'];
            $originalPrice = (string) round($currentPrice / (1 - $discountPercentage / 100), 2);
            $hasDiscount = true;
        }

        return [
            'has_discount' => null,
            'original_price' => (string) $originalPrice,
            'discount_percentage' => (string) $discountPercentage,
        ];
    }

    private function isRideTypeAvailable(int $rideTypeId, int $cityId, array $location): bool
    {
        $driverCount = \App\Models\User::where('role_id', 2)  // Driver role
            ->where('status', 'active')  // Use 'active' instead of 'online'
            ->whereHas('vehicles', function ($query) use ($rideTypeId) {
                $query
                    ->where('ride_type_id', $rideTypeId)
                    ->where('status', 'active');
            })
            ->count();

        return $driverCount > 0;
    }

    private function getDefaultIcon(string $rideTypeName): string
    {
        $name = strtolower($rideTypeName);

        if (strpos($name, 'bike') !== false || strpos($name, 'motorcycle') !== false) {
            return 'bike';
        } elseif (strpos($name, 'auto') !== false || strpos($name, 'rickshaw') !== false) {
            return 'auto';
        } elseif (strpos($name, 'cab') !== false || strpos($name, 'car') !== false) {
            return 'car';
        } elseif (strpos($name, 'bus') !== false) {
            return 'bus';
        }

        return 'car';  // default
    }

    private function isOffPeakHours(): bool
    {
        $hour = (int) now()->format('H');
        return ($hour >= 22 || $hour < 6) || ($hour >= 14 && $hour < 16);
    }

    private function getCityPromotion(City $city): ?array
    {
        return null;
    }

    private function getSurgeMultiplier($rideType, City $city): string
    {
        $driverCount = $this->getAvailableDriverCount($rideType->id, $city->id);
        $demandLevel = $this->getDemandLevel($city->id);

        if ($driverCount < 3 || $demandLevel > 0.8) {
            return '1.5';  // 50% surge
        } elseif ($driverCount < 5 || $demandLevel > 0.6) {
            return '1.2';  // 20% surge
        }

        return '1.0';  // No surge
    }

    private function getAvailableDriverCount(int $rideTypeId, int $cityId): int
    {
        return \App\Models\User::where('role_id', 2)  // Driver role
            ->where('status', 'active')  // Use 'active' instead of 'online'
            ->whereHas('vehicles', function ($query) use ($rideTypeId) {
                $query
                    ->where('ride_type_id', $rideTypeId)
                    ->where('status', 'active');
            })
            ->count();
    }

    private function getDemandLevel(int $cityId): float
    {
        $recentBookings = \App\Models\Booking::where('created_at', '>=', now()->subMinutes(30))
            ->count();

        return min(1.0, $recentBookings / 50.0);
    }

    private function getRideTypeIdByName(string $name): string
    {
        $rideType = RideType::where('name', 'LIKE', '%' . $name . '%')->first();
        return $rideType ? (string) $rideType->id : '';
    }

    public function isConform(Request $request, Booking $booking): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user || (int) $booking->user_id !== (int) $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this booking',
                ], 403);
            }

            if (!$user->isActive()) {
                $statusMessage = match ($user->status) {
                    'inactive' => 'Your account is inactive. Please contact support to activate your account.',
                    'blocked' => 'Your account has been blocked. Please contact support for assistance.',
                    'under_review' => 'Your account is under review. Please wait for the review to complete before confirming bookings.',
                    default => 'Your account is not active. Please contact support for assistance.',
                };

                return response()->json([
                    'success' => false,
                    'message' => $statusMessage,
                ], 403);
            }

            if (!$booking->ride_type_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ride type must be selected before confirming booking',
                ], 400);
            }

            DB::table('bookings')
                ->where('id', $booking->id)
                ->update([
                    'is_confirm' => 1,
                    'status' => 'searching',
                    'updated_at' => now()
                ]);

            $booking->refresh();

            // Restore driver notification functionality
            sleep(10);

            $notificationResults = $this->driverNotificationService->startDriverNotification($booking);

            $this->sendBookingConfirmationNotification($booking);

            // Restore location confirmation notification to drivers
            if ($booking->ride_type_id && $booking->is_confirm == 1) {
                $this->notificationService->sendLocationConfirmationNotificationToDriver($booking);
                
            }

            return response()->json([
                'success' => true,
                'message' => 'Booking confirmed successfully.',
                'data' => [
                    'booking_id' => (string) $booking->id,
                    'booking_code' => (string) ($booking->booking_code ?? ''),
                    'is_confirm' => (string) ($booking->is_confirm ?? ''),
                    'status' => (string) ($booking->status ?? ''),
                    // 'notification_results' => $this->formatNotificationResultsToStrings($notificationResults),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm booking',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function reviewDriver(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'booking_id' => ['required', 'exists:bookings,id'],
                'rating' => ['required', 'numeric', 'between:1,5'],
                'comment' => ['nullable', 'string', 'max:500'],
            ]);

            $user = $request->user();
            $booking = Booking::findOrFail($data['booking_id']);
            if (!$user || (int) $booking->user_id !== (int) $user->id) {
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
                'user_rating' => $data['rating'],
                'user_comment' => $data['comment'],
            ]);

            $this->updateDriverRating($booking->driver);

            return response()->json([
                'success' => true,
                'message' => 'Driver reviewed successfully',
                'data' => [
                    'booking_id' => $booking->id,
                    'rating' => $data['rating'],
                    'comment' => $data['comment'],
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to review driver',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function updateDriverRating($driver): void
    {
        $averageRating = Booking::where('driver_id', $driver->id)
            ->whereNotNull('user_rating')
            ->avg('user_rating');

        if ($driver->driverProfile) {
            $driver->driverProfile->update([
                'rating' => round($averageRating, 1),
            ]);
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
