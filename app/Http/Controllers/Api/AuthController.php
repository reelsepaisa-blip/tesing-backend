<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users'],
            'phone' => ['required', 'string', 'max:20', 'unique:users'],
            'country_code' => ['nullable', 'string', 'regex:/^\+[1-9]\d{0,3}$/', 'max:5'],
            'password' => ['required', Password::defaults()],
            'device_token' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string'],
            'referral_code' => ['nullable', 'string', 'exists:users,referral_code'],
            'role' => ['required', 'string', 'in:user,driver'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $user = $this->authService->register(
                $validator->validated(),
                $request->input('role')
            );

            return response()->json([
                'message' => 'Registration successful',
                'user' => $user->load('roles'),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_token' => ['nullable', 'string'],
            'device_id' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $result = $this->authService->login($validator->validated());

            return response()->json([
                'message' => 'Login successful',
                'token' => $result['token'],
                'user' => $result['user'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Login failed',
                'error' => $e->getMessage(),
            ], 401);
        }
    }

    
    public function logout(Request $request): JsonResponse
    {
        try {
            $this->authService->logout($request->user());

            return response()->json([
                'message' => 'Successfully logged out',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Logout failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function sendPhoneVerificationOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $result = $this->authService->sendPhoneVerificationOtp($request->phone);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to send OTP',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function verifyPhoneWithOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'max:20'],
            'otp' => ['required', 'string', 'size:6'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $verified = $this->authService->verifyPhoneWithOtp(
                $request->phone,
                $request->otp
            );

            return response()->json([
                'message' => 'Phone number verified successfully',
                'verified' => $verified,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Verification failed',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    
    public function requestPasswordResetOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $result = $this->authService->requestPasswordResetOtp($request->phone);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to send OTP',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    
    public function resetPasswordWithOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'max:20'],
            'otp' => ['required', 'string', 'size:6'],
            'password' => ['required', Password::defaults()],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $reset = $this->authService->resetPasswordWithOtp(
                $request->phone,
                $request->otp,
                $request->password
            );

            return response()->json([
                'message' => 'Password reset successfully',
                'reset' => $reset,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Password reset failed',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
