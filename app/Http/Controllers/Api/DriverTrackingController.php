<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Booking;
use App\Models\DriverLocation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DriverTrackingController extends Controller
{
    
    public function getAllDrivers(Request $request): JsonResponse
    {
        try {
            $search = $request->get('search', '');
            $status = $request->get('status', '');
            $cityId = $request->get('city_id', '');
            $serviceIds = $request->get('services', []);

            $query = User::drivers()
                ->with(['vehicles.rideType', 'vehicles.driver', 'driverProfile']);

            if (!empty($search)) {
                $query->where('name', 'LIKE', '%' . $search . '%');
            }

            if (!empty($cityId)) {
                $query->where('city_id', $cityId);
            }

            if (!empty($serviceIds) && is_array($serviceIds)) {
                $query->whereHas('vehicles', function ($vehicleQuery) use ($serviceIds) {
                    $vehicleQuery->whereIn('ride_type_id', $serviceIds);
                });
            }

            $drivers = $query->get()
                ->map(function ($driver) {
                    $latestLocation = DriverLocation::where('driver_id', $driver->id)
                        ->where('is_active', true)
                        ->latest('recorded_at')
                        ->first();

                    $currentBooking = Booking::where('driver_id', $driver->id)
                        ->whereIn('status', ['accepted', 'started', 'in_progress'])
                        ->first();

                    $status = $this->getDriverStatus($driver, $currentBooking);

                    $latitude = null;
                    $longitude = null;

                    if ($latestLocation && $latestLocation->location) {
                        if (preg_match('/POINT\(([^)]+)\)/', $latestLocation->location, $matches)) {
                            $coordinates = explode(' ', $matches[1]);
                            if (count($coordinates) >= 2) {
                                $longitude = (float) $coordinates[0];
                                $latitude = (float) $coordinates[1];
                            }
                        }
                    }

                    if ($latitude === null || $longitude === null) {
                        $latitude = $driver->last_latitude;
                        $longitude = $driver->last_longitude;
                    }

                    $lastLocationAt = $latestLocation ? $latestLocation->recorded_at : $driver->last_location_at;

                    return [
                        'driver_id' => $driver->id,
                        'driver_name' => $driver->name,
                        'driver_phone' => $driver->phone,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'heading' => $latestLocation ? $latestLocation->heading : null,
                        'address' => $latestLocation ? $latestLocation->address : null,
                        'status' => $status,
                        'status_color' => $this->getStatusColor($status),
                        'marker_color' => $this->getMarkerColor($status),
                        'is_online' => (bool) $driver->is_online,
                        'last_location_at' => $lastLocationAt,
                        'vehicles' => $driver->vehicles->map(function ($vehicle) {
                            return [
                                'vehicle_id' => $vehicle->id,
                                'model' => $vehicle->model,
                                'registration_number' => $vehicle->registration_number,
                                'ride_type' => $vehicle->rideType ? $vehicle->rideType->name : 'Unknown'
                            ];
                        }),
                        'current_booking' => $currentBooking ? [
                            'booking_id' => $currentBooking->id,
                            'status' => $currentBooking->status,
                            'pickup_address' => $currentBooking->pickup_address,
                            'dropoff_address' => $currentBooking->dropoff_address,
                            'customer_name' => $currentBooking->user->name ?? 'Unknown'
                        ] : null
                    ];
                });

            if (!empty($status)) {
                $drivers = $drivers->filter(function ($driver) use ($status) {
                    return $driver['status'] === $status;
                });
            }

            return response()->json([
                'success' => true,
                'message' => 'Driver tracking data retrieved successfully',
                'data' => [
                    'drivers' => $drivers,
                    'total_drivers' => $drivers->count(),
                    'online_drivers' => $drivers->where('is_online', true)->count(),
                    'offline_drivers' => $drivers->where('is_online', false)->count(),
                    'busy_drivers' => $drivers->where('status', 'busy')->count(),
                    'available_drivers' => $drivers->where('status', 'available')->count(),
                    'last_updated' => now()->format('Y-m-d H:i:s')
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve driver tracking data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    private function getDriverStatus(User $driver, ?Booking $currentBooking): string
    {
        if (!$driver->is_online) {
            return 'offline';
        }

        if ($currentBooking) {
            return 'busy';
        }

        return 'available';
    }

    
    private function getStatusColor(string $status): string
    {
        return match ($status) {
            'offline' => '#000000', // Black
            'available' => '#28a745', // Green
            'busy' => '#dc3545', // Red
            default => '#6c757d' // Gray
        };
    }

    
    private function getMarkerColor(string $status): string
    {
        return match ($status) {
            'offline' => 'black', // Black marker for offline
            'available' => 'green', // Green marker for available
            'busy' => 'red', // Red marker for busy/on ride
            default => 'gray'
        };
    }

    
    public function updateLocation(Request $request): JsonResponse
    {
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        try {
            $driver = $request->user();

            if ($driver->role_id !== 2) { // Check if user is a driver
                return response()->json([
                    'success' => false,
                    'message' => 'Only drivers can update location'
                ], 403);
            }

            $driver->update([
                'last_latitude' => $request->latitude,
                'last_longitude' => $request->longitude,
                'last_location_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Location updated successfully',
                'data' => [
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'updated_at' => now()->format('Y-m-d H:i:s')
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update location',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function updateOnlineStatus(Request $request): JsonResponse
    {
        $request->validate([
            'is_online' => 'required|boolean',
        ]);

        try {
            $driver = $request->user();

            if ($driver->role_id !== 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only drivers can update online status'
                ], 403);
            }

            $driver->update([
                'is_online' => $request->is_online,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Online status updated successfully',
                'data' => [
                    'is_online' => (bool) $request->is_online,
                    'updated_at' => now()->format('Y-m-d H:i:s')
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update online status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
