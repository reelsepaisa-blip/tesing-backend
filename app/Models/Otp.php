<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Otp extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'country_code',
        'otp',
        'type',
        'status',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeValid($query)
    {
        return $query->where('status', 'pending')
            ->where('expires_at', '>', now());
    }

    public function scopeByPhone($query, $phone)
    {
        return $query->where('phone', $phone);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->status === 'used';
    }

    public function isValid(): bool
    {
        return $this->status === 'pending' && !$this->isExpired();
    }

    public function markAsUsed(): void
    {
        $this->update([
            'status' => 'used',
            'used_at' => now(),
        ]);
    }

    public function markAsExpired(): void
    {
        $this->update([
            'status' => 'expired',
        ]);
    }

    public static function createOtp(string $phone, string $type = 'user_login', string $countryCode = '+91'): self
    {
        self::where('phone', $phone)
            ->where('type', $type)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);

        return self::create([
            'phone' => $phone,
            'country_code' => $countryCode,
            'otp' => self::generateOtp(),
            'type' => $type,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(1),
        ]);
    }

    public static function generateOtp(): string
    {
        return str_pad((string) rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function verifyOtp(string $phone, string $otp, string $type = 'user_login'): ?self
    {
        // Accept demo OTP in demo mode
        if (\App\Services\DemoModeService::isEnabled() && \App\Services\DemoModeService::isDemoOtp($otp)) {
            // Create a virtual OTP record for demo mode
            $otpRecord = new self();
            $otpRecord->phone = $phone;
            $otpRecord->otp = $otp;
            $otpRecord->type = $type;
            $otpRecord->status = 'pending';
            return $otpRecord;
        }
        
        $otpRecord = self::where('phone', $phone)
            ->where('otp', $otp)
            ->where('type', $type)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if ($otpRecord) {
            $otpRecord->markAsUsed();
            return $otpRecord;
        }

        return null;
    }

    
    public static function verifyOtpWithDetails(string $phone, string $otp, string $type = 'user_login'): array
    {
        // Accept demo OTP in demo mode
        if (\App\Services\DemoModeService::isEnabled() && \App\Services\DemoModeService::isDemoOtp($otp)) {
            // Create a virtual OTP record for demo mode
            $otpRecord = new self();
            $otpRecord->phone = $phone;
            $otpRecord->otp = $otp;
            $otpRecord->type = $type;
            $otpRecord->status = 'pending';
            $otpRecord->expires_at = now()->addMinutes(10); // Set future expiry for demo
            return ['status' => 'valid', 'otp' => $otpRecord];
        }
        
        $otpRecord = self::where('phone', $phone)
            ->where('otp', $otp)
            ->where('type', $type)
            ->first();

        if (!$otpRecord) {
            return ['status' => 'invalid', 'otp' => null];
        }

        if ($otpRecord->status === 'pending' && !$otpRecord->isExpired()) {
            $otpRecord->markAsUsed();
            return ['status' => 'valid', 'otp' => $otpRecord];
        }

        $newerValidOtp = self::where('phone', $phone)
            ->where('type', $type)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->where('created_at', '>', $otpRecord->created_at)
            ->latest()
            ->first();

        if ($newerValidOtp) {
            return ['status' => 'invalidated', 'otp' => null];
        }

        return ['status' => 'invalid', 'otp' => null];
    }

    public static function getValidOtp(string $phone, string $type = 'user_login'): ?self
    {
        return self::where('phone', $phone)
            ->where('type', $type)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
    }

    public static function cleanupExpiredOtps(): int
    {
        return self::where('expires_at', '<', now())
            ->where('status', 'pending')
            ->update(['status' => 'expired']);
    }
}
