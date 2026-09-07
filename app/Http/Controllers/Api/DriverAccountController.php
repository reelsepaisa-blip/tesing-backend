<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirebaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class DriverAccountController extends Controller
{
    public function deleteAccount(Request $request): JsonResponse
    {
        try {
            $driver = $request->user();
            if (!$driver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver not authenticated',
                ], 401);
            }

            if ($driver->role_id != 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Only drivers can access this endpoint.',
                ], 403);
            }

            $activeBookings = $driver
                ->bookingsAsDriver()
                ->whereIn('status', ['pending', 'accepted', 'started'])
                ->count();

            if ($activeBookings > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete account with active bookings. Please complete or cancel your active rides first.',
                    'data' => [
                        'active_bookings_count' => (string) $activeBookings,
                    ]
                ], 422);
            }
            $isDemo = false;
            if ($driver->email && str_ends_with($driver->email, '@etaxi.com')) {
                $isDemo = true;
                $demoReason = 'email_ends_with_etaxi';
            } elseif ($driver->phone && str_starts_with($driver->phone, '999999')) {
                $isDemo = true;
                $demoReason = 'phone_starts_with_999999';
            }
            if ($isDemo) {


                return response()->json([
                    'success' => true,
                    'message' => 'Demo driver account deletion ignored'
                ], 200);
            }
            $walletBalance = $driver->wallet?->balance ?? 0;

            if ($walletBalance > 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete account with wallet balance. Please withdraw your balance first.',
                    'data' => [
                        'wallet_balance' => (string) $walletBalance,
                    ]
                ], 422);
            }

            // Refresh driver from database to ensure we have latest data including firebase_uid
            $driver->refresh();

            $driverId = $driver->id;
            $driverEmail = $driver->email;
            $driverPhone = $driver->phone;
            $driverName = $driver->name;
            $driverFirebaseUid = $driver->firebase_uid;

            DB::beginTransaction();

            try {

                if ($driver->wallet) {
                    $driver->wallet->transactions()->delete();
                    $driver->wallet->delete();
                }

                $driver->promoUsages()->delete();

                $driver->supportTickets()->delete();

                $driver->emergencyContacts()->delete();

                $driver->documents()->delete();

                $driver->notifications()->delete();

                \App\Models\Booking::withTrashed()
                    ->where(function ($query) use ($driverId) {
                        $query
                            ->where('driver_id', $driverId)
                            ->orWhere('user_id', $driverId);
                    })
                    ->forceDelete();

                $driver->referrals()->update(['referred_by' => null]);

                if ($driver->driverProfile) {
                    $driver->driverProfile->delete();
                }

                $driver->vehicles()->delete();

                $driver->driverAttendance()->delete();

                $driver->driverLocations()->delete();

                // Delete Firebase user before deleting from database
                try {
                    $firebaseService = app(FirebaseService::class);
                    // Accept firebase_uid from request if provided, otherwise use stored value or email
                    $firebaseUid = $request->input('firebase_uid') ?? $driverFirebaseUid ?? $driver->firebase_uid;
                    $firebaseService->deleteUser($firebaseUid, $driverEmail ?? $driver->email, $driverPhone ?? $driver->phone);
                } catch (\Exception $e) {
                    // Continue with database deletion even if Firebase deletion fails
                }

                $driver->forceDelete();

                DB::commit();


                return response()->json([
                    'success' => true,
                    'message' => 'Driver account deleted successfully. All your data has been permanently removed.',
                    'data' => [
                        'deleted_at' => now()->toISOString(),
                        'driver_id' => (string) $driverId,
                    ]
                ]);
            } catch (\Exception $e) {
                DB::rollback();


                throw $e;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Driver account deletion failed: ' . $e->getMessage(),
                'error_details' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            ], 500);
        }
    }

    public function deactivateAccount(Request $request): JsonResponse
    {
        try {
            $driver = $request->user();

            if (!$driver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver not authenticated',
                ], 401);
            }

            if ($driver->role_id != 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Only drivers can access this endpoint.',
                ], 403);
            }

            $activeBookings = $driver
                ->bookingsAsDriver()
                ->whereIn('status', ['pending', 'accepted', 'started'])
                ->count();

            if ($activeBookings > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot deactivate account with active bookings. Please complete or cancel your active rides first.',
                    'data' => [
                        'active_bookings_count' => (string) $activeBookings,
                    ]
                ], 422);
            }

            $driverId = $driver->id;
            $driverEmail = $driver->email;
            $driverPhone = $driver->phone;
            $driverName = $driver->name;

            DB::beginTransaction();

            try {

                $driver->update([
                    'is_online' => false,
                    'status' => 'inactive',
                    'deactivated_at' => now(),
                ]);

                DB::commit();


                return response()->json([
                    'success' => true,
                    'message' => 'Driver account deactivated successfully. You can reactivate your account by logging in again.',
                    'data' => [
                        'deactivated_at' => now()->toISOString(),
                        'driver_id' => (string) $driverId,
                    ]
                ]);
            } catch (\Exception $e) {
                DB::rollback();


                throw $e;
            }
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Driver account deactivation failed: ' . $e->getMessage(),
                'error_details' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            ], 500);
        }
    }
}
