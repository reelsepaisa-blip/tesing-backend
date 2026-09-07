<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\DriverNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


class DriverResponseController extends Controller
{
    protected $driverNotificationService;

    public function __construct(DriverNotificationService $driverNotificationService)
    {
        $this->driverNotificationService = $driverNotificationService;
    }

    
    public function accept(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'booking_id' => 'required|exists:bookings,id'
            ]);

            $driver = Auth::user();
            $booking = Booking::findOrFail($request->booking_id);

            if (!$this->canAcceptBooking($driver, $booking)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to accept this booking'
                ], 403);
            }

            $success = $this->driverNotificationService->handleDriverAcceptance($booking, $driver);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Booking accepted successfully',
                    'data' => [
                        'booking_id' => $booking->id,
                        'status' => $booking->status,
                        'accepted_at' => $booking->accepted_at
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to accept booking - may no longer be available'
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to accept booking'
            ], 500);
        }
    }

    
    public function reject(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'booking_id' => 'required|exists:bookings,id',
                'reason' => 'nullable|string|max:255'
            ]);

            $driver = Auth::user();
            $booking = Booking::findOrFail($request->booking_id);

            if (!$this->canAcceptBooking($driver, $booking)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to reject this booking'
                ], 403);
            }

            $this->driverNotificationService->handleDriverRejection($booking, $driver);

            return response()->json([
                'success' => true,
                'message' => 'Booking rejected successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject booking'
            ], 500);
        }
    }

    
    protected function canAcceptBooking($driver, $booking): bool
    {
        if (!$driver || !$driver->hasRole('driver')) {
            return false;
        }

        if (!$driver->is_online) {
            return false;
        }

        if ($driver->bookings()->where('status', 'accepted')->exists()) {
            return false;
        }

        if ($booking->status !== 'searching') {
            return false;
        }

        if ($booking->driver_id) {
            return false;
        }

        $offerKey = "booking_offer_{$booking->id}_{$driver->id}";
        $offer = \Illuminate\Support\Facades\Cache::get($offerKey);

        if (!$offer || $offer['status'] !== 'pending') {
            return false;
        }

        return true;
    }
}
