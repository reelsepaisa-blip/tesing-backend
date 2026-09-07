<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\DriverAttendance;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

use Symfony\Component\HttpFoundation\Response;

class BearerTokenAuth
{

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            // Log missing token at info level (expected for unauthorized access attempts)

            return response()->json([
                'success' => false,
                'message' => 'Token Is Missing',
                'error_code' => 'TOKEN_MISSING',
                'requires_logout' => true,
            ], 401);
        }

        // $request->bearerToken() already strips "Bearer " prefix from Authorization header
        // So $token is the raw token value (e.g., "Bearer_abc123" or "abc123")
        $normalizedToken = str_replace('Bearer_', '', $token);
        $tokenHash = hash('sha256', $normalizedToken);
        $driverId = $this->extractDriverIdFromToken($normalizedToken);

        $sanctumToken = PersonalAccessToken::query()
            ->where('token', $tokenHash)
            ->where('tokenable_type', User::class)
            ->first();

        if ($sanctumToken && $sanctumToken->tokenable instanceof User) {
            $user = $sanctumToken->tokenable;

            if ($user->token_expires_at && $user->token_expires_at->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User logged in on another device',
                    'error' => 'Invalid or expired token',
                    'error_code' => 'TOKEN_INVALID',
                    'requires_logout' => true,
                    'logout_reason' => 'token_expired',
                ], 401);
            }

            if ($driverId !== null && $user->id !== $driverId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token driver_id mismatch',
                    'error_code' => 'TOKEN_MISMATCH',
                    'requires_logout' => true,
                ], 401);
            }

            if ($user->isBlocked()) {
                if ($user->isDriver()) {
                    $this->markDriverOffline($user->id);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Account is blocked',
                ], 403);
            }

            Auth::setUser($user);
            $request->merge(['user' => $user]);

            if ($driverId !== null) {
                $request->merge(['driver_id' => $driverId]);
            }

            return $next($request);
        }

        // Always check database directly - bypass cache for strict validation
        // Query for users with matching token (check both formats)
        $users = User::without('driverProfile')
            ->select(['id', 'role_id', 'status', 'bearer_token', 'token_expires_at', 'is_online', 'is_verified', 'email', 'phone', 'name'])
            ->where(function ($query) use ($normalizedToken, $token, $tokenHash) {
                $query->where('bearer_token', $tokenHash)
                    ->orWhere('bearer_token', $normalizedToken)
                    ->orWhere('bearer_token', 'Bearer_' . $normalizedToken)
                    ->orWhere('bearer_token', $token)
                    ->orWhere('bearer_token', 'Bearer_' . str_replace('Bearer_', '', $token));
            })
            ->whereNotNull('bearer_token')
            ->where(function ($query) {
                // All users can have null token_expires_at (never expires) or valid future expiration
                $query->whereNull('token_expires_at')
                    ->orWhere('token_expires_at', '>', now());
            })
            ->get();

        // Strict validation: find user with exact token match
        $user = null;
        foreach ($users as $candidateUser) {
            $userToken = $candidateUser->bearer_token;
            $normalizedUserToken = str_replace('Bearer_', '', $userToken);

            // Exact match check
            if (
                $userToken === $tokenHash ||
                $userToken === $token ||
                $userToken === 'Bearer_' . $normalizedToken ||
                $normalizedUserToken === $normalizedToken ||
                $normalizedUserToken === str_replace('Bearer_', '', $token)
            ) {
                $user = $candidateUser;
                break;
            }
        }

        // If no exact match found, token is invalid
        if (!$user) {
            $cacheKey = 'auth:bearer_user:' . $normalizedToken;
            Cache::forget($cacheKey);

            // Log debug information for "User logged in on another device"
            $tokenPreview = strlen($normalizedToken) > 20
                ? substr($normalizedToken, 0, 20) . '...'
                : $normalizedToken;


            // Additional debug: Check if token exists in database but expired
            if ($users->count() > 0) {
                $expiredUser = $users->first();
            } else {
                
            }

            $this->handleExpiredDriverToken($normalizedToken, $driverId);

            // Determine if token was expired or completely invalid (user logged in elsewhere)
            $isExpired = $users->count() > 0;
            $logoutReason = $isExpired ? 'token_expired' : 'user_logged_in_elsewhere';

            // User-friendly message for display
            $responseMessage = 'User logged in on another device';

            $response = response()->json([
                'success' => false,
                'message' => $responseMessage,
                // Keep "Invalid or expired token" in error field - mobile app checks this for logout
                'error' => 'Invalid or expired token',
                'error_code' => 'TOKEN_INVALID',
                'requires_logout' => true,
                'logout_reason' => $logoutReason,
            ], 401);

            // Log the response being sent for debugging

            return $response;
        }

        // Verify driver_id matches if token contains driver_id
        if ($driverId !== null && $user->id !== $driverId) {
            // Clear cache for mismatched token
            $cacheKey = 'auth:bearer_user:' . $normalizedToken;
            Cache::forget($cacheKey);

            // Log token mismatch

            return response()->json([
                'success' => false,
                'message' => 'Token driver_id mismatch',
                'error_code' => 'TOKEN_MISMATCH',
                'requires_logout' => true,
            ], 401);
        }

        // Cache the validated user for performance (only after validation passes)
        $cacheKey = 'auth:bearer_user:' . $normalizedToken;
        if ($user->token_expires_at === null) {
            Cache::put($cacheKey, $user, 3600);
        } elseif ($user->token_expires_at) {
            $secondsUntilExpiry = now()->diffInSeconds($user->token_expires_at, false);
            if ($secondsUntilExpiry > 0) {
                $jitter = random_int(5, 20);
                Cache::put($cacheKey, $user, max(60, min(600, $secondsUntilExpiry)) + $jitter);
            }
        }

        if ($user->isBlocked()) {

            if ($user->isDriver()) {
                $this->markDriverOffline($user->id);
            }

            return response()->json([
                'success' => false,
                'message' => 'Account is blocked',
            ], 403);
        }

        Auth::setUser($user);

        $request->merge(['user' => $user]);

        if ($driverId !== null) {
            $request->merge(['driver_id' => $driverId]);
        }

        return $next($request);
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


    private function handleExpiredDriverToken(string $token, ?int $driverIdFromToken): void
    {
        try {
            $driverId = null;

            if ($driverIdFromToken !== null) {
                $driverId = $driverIdFromToken;
            } else {


                $expiredUser = User::select(['id', 'role_id'])
                    ->where(function ($query) use ($token) {
                        $query->where('bearer_token', $token)
                            ->orWhere('bearer_token', 'Bearer_' . $token);
                    })
                    ->first();

                if ($expiredUser && $expiredUser->isDriver()) {
                    $driverId = $expiredUser->id;
                }
            }

            if ($driverId !== null) {
                $this->markDriverOffline($driverId);
            }
        } catch (\Exception $e) {
        }
    }


    private function markDriverOffline(int $driverId): void
    {
        try {

            User::where('id', $driverId)->update(['is_online' => 0]);

            $activeSession = DriverAttendance::getCurrentOnlineSession($driverId);
            if ($activeSession) {
                $activeSession->markOffline();
            }
        } catch (\Exception $e) {
        }
    }
}
