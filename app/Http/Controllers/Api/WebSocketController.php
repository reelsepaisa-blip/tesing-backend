<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use App\Services\RealTimeTripService;
use App\Services\PushNotificationService;
use App\Services\DriverAutoLocationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\Cache;

class WebSocketController extends Controller
{
    protected $realTimeService;
    protected $pushNotificationService;
    protected $driverAutoLocationService;

    public function __construct(
        RealTimeTripService $realTimeService,
        PushNotificationService $pushNotificationService,
        DriverAutoLocationService $driverAutoLocationService
    ) {
        $this->realTimeService = $realTimeService;
        $this->pushNotificationService = $pushNotificationService;
        $this->driverAutoLocationService = $driverAutoLocationService;
    }


    public function updateDriverLocation(Request $request): JsonResponse
    {
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'heading' => 'nullable|numeric|between:0,360',
            'speed' => 'nullable|numeric|min:0',
            'booking_id' => 'nullable|exists:bookings,id'
        ]);

        try {
            $driver = Auth::user();

            if ($driver->role_id !== 2) {
                return response()->json(['error' => 'Only drivers can update location'], 403);
            }

            $this->realTimeService->updateDriverLocation(
                $driver,
                $request->latitude,
                $request->longitude,
                $request->heading ?? 0,
                $request->speed
            );

            if ($request->booking_id) {
                $booking = Booking::find($request->booking_id);
                if ($booking && $booking->driver_id === $driver->id) {
                    $this->updateTripProgress($booking, $request->latitude, $request->longitude);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Location updated successfully',
                'timestamp' => now()->timestamp
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update location'], 500);
        }
    }


    public function updateTripStatus(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'status' => 'required|in:accepted,arrived,started,completed,cancelled',
            'metadata' => 'nullable|array'
        ]);

        try {
            $user = Auth::user();
            $booking = Booking::find($request->booking_id);

            if (!$this->canUpdateBooking($user, $booking)) {
                return response()->json(['error' => 'Unauthorized to update this booking'], 403);
            }

            $this->realTimeService->updateTripStatus(
                $booking,
                $request->status,
                $request->metadata ?? []
            );

            $this->sendStatusNotifications($booking, $request->status, $request->metadata ?? []);

            return response()->json([
                'success' => true,
                'message' => 'Trip status updated successfully',
                'new_status' => $request->status,
                'timestamp' => now()->timestamp
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update trip status'], 500);
        }
    }


    public function getTripInfo(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id'
        ]);

        try {
            $user = Auth::user();
            $booking = Booking::find($request->booking_id);

            if (!$this->canAccessBooking($user, $booking)) {
                return response()->json(['error' => 'Unauthorized to access this booking'], 403);
            }

            $tripInfo = $this->realTimeService->getTripInfo($booking);

            return response()->json([
                'success' => true,
                'trip_info' => $tripInfo,
                'timestamp' => now()->timestamp
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to get trip information'], 500);
        }
    }


    public function getNearbyDrivers(Request $request): JsonResponse
    {
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'ride_type_id' => 'required|exists:ride_types,id',
            'city_id' => 'required|exists:cities,id',
            'radius_km' => 'nullable|numeric|min:0.5|max:50'
        ]);

        // Return demo drivers in demo mode
        if (\App\Services\DemoModeService::isEnabled()) {
            $demoDrivers = \App\Services\DemoModeService::getDemoNearbyDrivers();
            return response()->json([
                'success' => true,
                'nearby_drivers' => $demoDrivers,
                'count' => count($demoDrivers),
                'timestamp' => now()->timestamp
            ]);
        }

        try {
            $location = new \MatanYadaev\EloquentSpatial\Objects\Point(
                $request->latitude,
                $request->longitude
            );

            $nearbyDrivers = $this->realTimeService->getNearbyDrivers(
                $location,
                $request->ride_type_id,
                $request->city_id,
                $request->radius_km ?? 5
            );

            return response()->json([
                'success' => true,
                'nearby_drivers' => $nearbyDrivers,
                'count' => count($nearbyDrivers),
                'timestamp' => now()->timestamp
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to get nearby drivers'], 500);
        }
    }


    public function updateFCMToken(Request $request): JsonResponse
    {
        $request->validate([
            'fcm_token' => 'required|string|min:100'
        ]);

        try {
            $user = Auth::user();

            $success = $this->pushNotificationService->updateUserToken($user, $request->fcm_token);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'FCM token updated successfully'
                ]);
            } else {
                return response()->json(['error' => 'Failed to update FCM token'], 500);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update FCM token'], 500);
        }
    }


    public function getConnectionStatus(): JsonResponse
    {
        try {
            $user = Auth::user();
            $status = [
                'user_id' => $user->id,
                'user_type' => $user->hasRole('driver') ? 'driver' : 'user',
                'websocket_url' => config('app.websocket_url', 'ws://localhost:6001'),
                'pusher_config' => [
                    'key' => config('broadcasting.connections.pusher.key'),
                    'cluster' => config('broadcasting.connections.pusher.options.cluster'),
                    'encrypted' => config('broadcasting.connections.pusher.options.encrypted')
                ],
                'timestamp' => now()->timestamp
            ];

            return response()->json([
                'success' => true,
                'connection_status' => $status
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to get connection status'], 500);
        }
    }


    public function startAutoLocationUpdates(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if ($user->role_id !== 2) {
                return response()->json(['error' => 'Only drivers can start auto location updates'], 403);
            }

            $started = $this->driverAutoLocationService->startAutoLocationUpdates($user);

            if ($started) {
                return response()->json([
                    'success' => true,
                    'message' => 'Auto location updates started',
                    'update_interval' => 10,
                    'timestamp' => now()->timestamp
                ]);
            } else {
                return response()->json(['error' => 'Failed to start auto location updates'], 500);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to start auto location updates'], 500);
        }
    }


    public function stopAutoLocationUpdates(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if ($user->role_id !== 2) {
                return response()->json(['error' => 'Only drivers can stop auto location updates'], 403);
            }

            $stopped = $this->driverAutoLocationService->stopAutoLocationUpdates($user);

            if ($stopped) {
                return response()->json([
                    'success' => true,
                    'message' => 'Auto location updates stopped',
                    'timestamp' => now()->timestamp
                ]);
            } else {
                return response()->json(['error' => 'Failed to stop auto location updates'], 500);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to stop auto location updates'], 500);
        }
    }


    public function getAutoLocationStatus(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if ($user->role_id !== 2) {
                return response()->json(['error' => 'Only drivers can check auto location status'], 403);
            }

            $cacheKey = "auto_location_driver_{$user->id}";
            $cacheData = Cache::get($cacheKey);

            $status = [
                'driver_id' => $user->id,
                'is_active' => $cacheData ? $cacheData['is_active'] : false,
                'started_at' => $cacheData ? $cacheData['started_at'] : null,
                'last_update' => $cacheData ? $cacheData['last_update'] : null,
                'update_interval' => $cacheData ? $cacheData['update_interval'] : 10,
                'is_online' => $user->is_online,
                'timestamp' => now()->timestamp
            ];

            return response()->json([
                'success' => true,
                'auto_location_status' => $status
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to get auto location status'], 500);
        }
    }


    public function storeRealtimeLocation(Request $request): JsonResponse
    {
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'heading' => 'nullable|numeric|between:0,360',
            'speed' => 'nullable|numeric|min:0',
            'accuracy' => 'nullable|numeric|min:0',
            'battery_level' => 'nullable|integer|between:0,100',
            'is_charging' => 'nullable|boolean'
        ]);

        try {
            $user = Auth::user();

            if ($user->role_id !== 2) {
                return response()->json(['error' => 'Only drivers can store location'], 403);
            }

            $locationData = [
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'heading' => $request->heading ?? 0,
                'speed' => $request->speed ?? 0,
                'accuracy' => $request->accuracy ?? 10,
                'battery_level' => $request->battery_level ?? 80,
                'is_charging' => $request->is_charging ?? false,
                'timestamp' => now()->timestamp
            ];

            $stored = $this->driverAutoLocationService->storeRealtimeLocation($user, $locationData);

            if ($stored) {
                return response()->json([
                    'success' => true,
                    'message' => 'Real-time location stored successfully',
                    'location' => $locationData
                ]);
            } else {
                return response()->json(['error' => 'Failed to store real-time location'], 500);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to store real-time location'], 500);
        }
    }


    protected function canUpdateBooking(User $user, Booking $booking): bool
    {
        return $user->id === $booking->user_id || $user->id === $booking->driver_id;
    }


    protected function canAccessBooking(User $user, Booking $booking): bool
    {
        return $user->id === $booking->user_id || $user->id === $booking->driver_id;
    }


    protected function updateTripProgress(Booking $booking, float $latitude, float $longitude): void
    {
        if ($booking->status !== 'started') {
            return;
        }

        $progressKey = "trip_progress_{$booking->id}";
        $progress = Cache::get($progressKey, []);

        $progress[] = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'timestamp' => now()->timestamp
        ];

        if (count($progress) > 100) {
            $progress = array_slice($progress, -100);
        }

        Cache::put($progressKey, $progress, 3600); // 1 hour
    }


    protected function sendStatusNotifications(Booking $booking, string $status, array $metadata): void
    {
        try {
            switch ($status) {
                case 'accepted':
                    if ($booking->user) {
                        $eta = $metadata['eta'] ?? null;
                        $this->pushNotificationService->sendTripNotification(
                            $booking->user,
                            $booking,
                            'driver_assigned',
                            ['eta' => $eta]
                        );
                    }
                    break;

                case 'arrived':
                    if ($booking->user) {
                        $this->pushNotificationService->sendTripNotification(
                            $booking->user,
                            $booking,
                            'driver_arrived'
                        );
                    }
                    break;

                case 'started':
                    if ($booking->user) {
                        $this->pushNotificationService->sendTripNotification(
                            $booking->user,
                            $booking,
                            'trip_started'
                        );
                    }
                    break;

                case 'completed':
                    // User notification is handled by NotificationService::tripCompleted()
                    // to avoid duplicate notifications
                    if ($booking->driver) {
                        $this->pushNotificationService->sendDriverNotification(
                            $booking->driver,
                            'payment_received',
                            ['amount' => $booking->driver_earnings ?? 0]
                        );
                    }
                    break;

                case 'cancelled':
                    if ($booking->user) {
                        $this->pushNotificationService->sendTripNotification(
                            $booking->user,
                            $booking,
                            'trip_cancelled',
                            ['reason' => $metadata['reason'] ?? 'Trip cancelled']
                        );
                    }
                    if ($booking->driver) {
                        $this->pushNotificationService->sendDriverNotification(
                            $booking->driver,
                            'booking_cancelled'
                        );
                    }
                    break;
            }
        } catch (\Exception $e) {
            // Log error silently
        }
    }
}
