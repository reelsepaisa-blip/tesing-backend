<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthService
{
    
    public function register(array $data, string $role = 'user'): User
    {
        DB::beginTransaction();
        try {
            $referralCode = $this->generateNameBasedReferralCode($data['name']);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'country_code' => $data['country_code'] ?? '+91',
                'password' => Hash::make($data['password']),
                'referral_code' => $referralCode,
                'referred_by' => $data['referral_code'] ? User::where('referral_code', $data['referral_code'])->first()?->id : null,
                'device_token' => $data['device_token'] ?? null,
                'current_device_id' => $data['device_id'] ?? null,
            ]);

            $user->assignRole($role);

            $user->wallet()->create([
                'balance' => 0,
                'hold_amount' => 0,
                'status' => \App\Models\Wallet::STATUS_ACTIVE,
            ]);

            DB::commit();
            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    
    private function generateNameBasedReferralCode(string $name): string
    {
        $cleanName = strtoupper(preg_replace('/[^A-Za-z]/', '', $name));

        $namePrefix = substr($cleanName, 0, 3);

        if (strlen($namePrefix) < 3) {
            $namePrefix = str_pad($namePrefix, 3, 'X', STR_PAD_RIGHT);
        }

        do {
            $code = $namePrefix . rand(1000, 9999);
        } while (User::where('referral_code', $code)->exists());

        return $code;
    }

    
    public function login(array $credentials): array
    {
        $user = User::where('phone', $credentials['phone'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'phone' => ['Your account has been deactivated. Please contact support.'],
            ]);
        }

        if ($user->hasRole('driver')) {
            if ($credentials['device_id'] !== $user->current_device_id && $user->current_device_id !== null) {
                throw ValidationException::withMessages([
                    'device' => ['You can only login from one device at a time.'],
                ]);
            }
            $user->update(['current_device_id' => $credentials['device_id']]);
        }

        if (isset($credentials['device_token'])) {
            $user->update(['device_token' => $credentials['device_token']]);
        }

        $abilities = $this->getAbilitiesForUser($user);
        $token = $user->createToken('auth_token', $abilities)->plainTextToken;

        return [
            'token' => $token,
            'user' => $user->load('roles'),
        ];
    }

    
    protected function getAbilitiesForUser(User $user): array
    {
        $abilities = ['*']; // Base abilities

        if ($user->hasRole('driver')) {
            $abilities = [
                'driver:profile',
                'driver:trips',
                'driver:documents',
                'driver:earnings',
                'driver:location',
            ];
        } elseif ($user->hasRole('user')) {
            $abilities = [
                'user:profile',
                'user:trips',
                'user:payments',
                'user:addresses',
            ];
        }

        return $abilities;
    }

    
    public function logout(User $user): void
    {
        if ($user->hasRole('driver')) {
            $user->update(['current_device_id' => null]);
        }
        $user->tokens()->delete();
    }

    
    public function sendPhoneVerificationOtp(string $phone): array
    {
        $otp = rand(100000, 999999);

        $key = 'phone_verification_' . $phone;
        cache()->put($key, [
            'otp' => $otp,
            'attempts' => 0,
        ], now()->addMinutes(5));

        return [
            'message' => 'OTP sent successfully',
            'otp' => $otp, // Remove this in production
            'expires_in' => 60,
        ];
    }

    
    public function verifyPhoneWithOtp(string $phone, string $otp): bool
    {
        $key = 'phone_verification_' . $phone;
        $data = cache()->get($key);

        if (!$data) {
            throw ValidationException::withMessages([
                'otp' => ['OTP has expired. Please request a new one.'],
            ]);
        }

        if ($data['attempts'] >= 3) {
            cache()->forget($key);
            throw ValidationException::withMessages([
                'otp' => ['Too many invalid attempts. Please request a new OTP.'],
            ]);
        }

        if ($data['otp'] !== $otp) {
            cache()->put($key, [
                'otp' => $data['otp'],
                'attempts' => $data['attempts'] + 1,
            ], now()->addMinutes(5));

            throw ValidationException::withMessages([
                'otp' => ['Invalid OTP.'],
            ]);
        }

        $user = User::where('phone', $phone)->first();
        if ($user) {
            $user->update(['phone_verified_at' => now()]);
        }

        cache()->forget($key);
        return true;
    }

    
    public function requestPasswordResetOtp(string $phone): array
    {
        $user = User::where('phone', $phone)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'phone' => ['No account found with this phone number.'],
            ]);
        }

        $otp = rand(100000, 999999);

        $key = 'password_reset_' . $phone;
        cache()->put($key, [
            'otp' => $otp,
            'attempts' => 0,
        ], now()->addMinutes(5));

        return [
            'message' => 'OTP sent successfully',
            'otp' => $otp, // Remove this in production
            'expires_in' => 60,
        ];
    }

    
    public function resetPasswordWithOtp(string $phone, string $otp, string $password): bool
    {
        $key = 'password_reset_' . $phone;
        $data = cache()->get($key);

        if (!$data) {
            throw ValidationException::withMessages([
                'otp' => ['OTP has expired. Please request a new one.'],
            ]);
        }

        if ($data['attempts'] >= 3) {
            cache()->forget($key);
            throw ValidationException::withMessages([
                'otp' => ['Too many invalid attempts. Please request a new OTP.'],
            ]);
        }

        if ($data['otp'] !== $otp) {
            cache()->put($key, [
                'otp' => $data['otp'],
                'attempts' => $data['attempts'] + 1,
            ], now()->addMinutes(5));

            throw ValidationException::withMessages([
                'otp' => ['Invalid OTP.'],
            ]);
        }

        $user = User::where('phone', $phone)->first();
        if (!$user) {
            throw ValidationException::withMessages([
                'phone' => ['No account found with this phone number.'],
            ]);
        }

        $user->update(['password' => Hash::make($password)]);
        cache()->forget($key);
        return true;
    }
}
