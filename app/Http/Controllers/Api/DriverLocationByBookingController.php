<?php

namespace App\Http\Controllers\Api;

use App\Events\DriverLocationByBookingUpdated;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\DriverLocation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\Cache;

class DriverLocationByBookingController extends Controller
{
    /**
     * Handle WebSocket client event for driver location update by booking
     */
    public function handleLocationUpdate(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'booking_id' => 'required|integer|exists:bookings,id',
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
            ]);

            $bookingId = $request->input('booking_id');
            $latitude = $request->input('latitude');
            $longitude = $request->input('longitude');

            $booking = Booking::findOrFail($bookingId);
            $actor = $request->user();

            if (!$actor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $isAdmin = (int) ($actor->role_id ?? 0) === 1;
            if (!$isAdmin && (int) $actor->id !== (int) $booking->driver_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this booking location'
                ], 403);
            }

            if (!$booking->driver_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No driver assigned to this booking'
                ], 422);
            }

            $driver = User::find($booking->driver_id);

            if (!$driver || $driver->role_id !== 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver not found or invalid'
                ], 404);
            }

            // Check if driver is a demo/auto-generated driver (email ends with @etaxi.com)
            $isDemo = str_ends_with($driver->email, '@etaxi.com') ? 1 : 0;
            if ($isDemo == 1) {
                
                return response()->json([
                    'success' => false,
                    'message' => 'Demo driver cannot update location'
                ], 200);
            }

            // Update driver location in database
            $driverLocation = DriverLocation::where('driver_id', $driver->id)
                ->where('is_active', true)
                ->first();

            if (!$driverLocation) {
                $driverLocation = new DriverLocation();
                $driverLocation->driver_id = $driver->id;
                $driverLocation->is_active = true;
            }

            $driverLocation->latitude = $latitude;
            $driverLocation->longitude = $longitude;
            $driverLocation->heading = $request->input('heading', 0);
            $driverLocation->speed = $request->input('speed', 0);
            $driverLocation->accuracy = $request->input('accuracy', 10);
            $driverLocation->recorded_at = now();
            $driverLocation->save();

            // Update driver's last location
            $driver->update([
                'last_latitude' => $latitude,
                'last_longitude' => $longitude,
                'last_location_at' => now(),
            ]);

            // Broadcast to private channel
            event(new DriverLocationByBookingUpdated(
                $bookingId,
                $latitude,
                $longitude,
                $driver->id
            ));

            

            return response()->json([
                'success' => true,
                'message' => 'Location updated successfully',
                'data' => [
                    'booking_id' => $bookingId,
                    'driver_id' => $driver->id,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'timestamp' => now()->timestamp,
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

    /**
     * Authenticate WebSocket connection for private driver location channel
     */
    public function authenticateWebSocket(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'socket_id' => 'required|string',
                'channel_name' => 'required|string',
            ]);

            $socketId = $request->input('socket_id');
            $channelName = $request->input('channel_name');

            // Extract booking_id from channel name: private-driver-location.booking.{booking_id}
            if (!preg_match('/private-driver-location\.booking\.(\d+)/', $channelName, $matches)) {
                return response()->json([
                    'error' => 'Invalid channel format'
                ], 400);
            }

            $bookingId = (int) $matches[1];
            $booking = Booking::find($bookingId);

            if (!$booking) {
                return response()->json([
                    'error' => 'Booking not found'
                ], 404);
            }

            // Get authenticated user
            $user = Auth::user();

            if (!$user) {
                // Try to get user from bearer token
                $token = $request->bearerToken();
                if ($token) {
                    $user = User::where('bearer_token', $token)->first();
                }
            }

            if (!$user) {
                return response()->json([
                    'error' => 'Unauthorized'
                ], 401);
            }

            // Check if user has access to this booking
            // User can access if they are the customer, driver, or admin
            $hasAccess = false;

            if ($user->role_id === 1) {
                // Admin has access to all bookings
                $hasAccess = true;
            } elseif ($user->id === $booking->user_id) {
                // Customer has access
                $hasAccess = true;
            } elseif ($user->id === $booking->driver_id) {
                // Driver has access
                $hasAccess = true;
            }

            if (!$hasAccess) {
                return response()->json([
                    'error' => 'Unauthorized to access this channel'
                ], 403);
            }

            // Generate Pusher auth signature
            $pusherAppSecret = config('broadcasting.connections.pusher.secret');
            $stringToSign = $socketId . ':' . $channelName;
            $authSignature = hash_hmac('sha256', $stringToSign, $pusherAppSecret);

            // Store socket_id to user_id mapping for webhook identification
            Cache::put("socket_user:{$socketId}", $user->id, now()->addHours(24));
            

            return response()->json([
                'auth' => config('broadcasting.connections.pusher.key') . ':' . $authSignature,
                'user_data' => [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'user_type' => $user->isDriver() ? 'driver' : ($user->role_id === 1 ? 'admin' : 'user'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Authentication failed'
            ], 500);
        }
    }
}
