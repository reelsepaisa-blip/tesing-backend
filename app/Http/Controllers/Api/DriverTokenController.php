<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverTokenController extends Controller
{
    
    public function getDriverInfo(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            $driverId = $request->input('driver_id');

            $token = $request->bearerToken();

            return response()->json([
                'success' => true,
                'message' => 'Driver information retrieved successfully',
                'data' => [
                    'user_id' => (string) $user->id,
                    'driver_id_from_token' => $driverId ? (string) $driverId : '',
                    'driver_id_from_user' => (string) $user->id,
                    'token_format' => $this->getTokenFormat($token),
                    'is_driver' => (string) ($user->isDriver() ? '1' : '0'),
                    'role_id' => (string) $user->role_id,
                    'name' => $user->name ?? '',
                    'phone' => $user->phone ?? '',
                    'is_online' => (string) ($user->is_online ? '1' : '0'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving driver information: ' . $e->getMessage(),
            ], 500);
        }
    }

    
    public function extractDriverId(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No token provided',
                ], 400);
            }

            $driverId = $this->extractDriverIdFromToken($token);

            return response()->json([
                'success' => true,
                'message' => 'Driver ID extracted successfully',
                'data' => [
                    'token' => $token,
                    'driver_id' => $driverId ? (string) $driverId : '',
                    'has_driver_id' => $driverId !== null ? '1' : '0',
                    'token_format' => $this->getTokenFormat($token),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error extracting driver ID: ' . $e->getMessage(),
            ], 500);
        }
    }

    
    private function extractDriverIdFromToken(string $token): ?int
    {
        $token = str_replace('Bearer_', '', $token);

        if (strpos($token, '_') !== false) {
            $parts = explode('_', $token, 2);
            if (count($parts) === 2 && is_numeric(hexdec($parts[0]))) {
                return (int) hexdec($parts[0]);
            }
        }

        return null;
    }

    
    private function getTokenFormat(string $token): string
    {
        $driverId = $this->extractDriverIdFromToken($token);
        return $driverId !== null ? 'new_format_with_driver_id' : 'old_format';
    }
}
