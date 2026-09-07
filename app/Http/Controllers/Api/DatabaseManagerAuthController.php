<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Auth\BaseAuthController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class DatabaseManagerAuthController extends BaseAuthController
{
    /**
     * Exchange Filament/admin email & password for a bearer token so the database-manager.html
     * page can call protected API routes without copying tokens from devtools.
     */
    public function issueToken(Request $request): JsonResponse
    {
        if (! config('etaxi.database_manager_password_login')) {
            return response()->json([
                'success' => false,
                'message' => 'Password sign-in for this tool is turned off. Set ETAXI_DATABASE_MANAGER_PASSWORD_LOGIN=true in .env, or ask your developer for an API bearer token.',
            ], 403);
        }

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = strtolower(trim($validated['email']));
        $password = $validated['password'];

        $user = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->where('role_id', 1)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 401);
        }

        if ($user->isBlocked()) {
            return response()->json([
                'success' => false,
                'message' => 'Account is blocked.',
            ], 403);
        }

        // role_id 1 is the app admin (Filament); Spatie role optional for this tool only.
        $auth = $this->createAuthToken($user);

        return response()->json([
            'success' => true,
            'message' => 'Signed in. You can use Get Database Stats and Truncate now.',
            'token' => $auth['token'],
        ]);
    }
}
