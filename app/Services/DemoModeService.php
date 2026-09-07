<?php

namespace App\Services;

class DemoModeService
{
    /**
     * Check if demo mode is enabled
     */
    public static function isEnabled(): bool
    {
        return config('app.demo_mode', false);
    }

    /**
     * Get demo OTP (6 digits)
     */
    public static function getDemoOtp(): string
    {
        return '123456';
    }
    
    /**
     * Get demo OTP for booking (can be 4 or 6 digits based on validation)
     */
    public static function getDemoBookingOtp(): string
    {
        return '123456'; // 6 digits, but will be validated flexibly
    }

    /**
     * Get demo pickup location (Bhuj Railway Station)
     */
    public static function getDemoPickupLocation(): array
    {
        return [
            'latitude' => 23.2500,  // Bhuj Railway Station approximate coordinates
            'longitude' => 69.6700,
            'address' => 'Bhuj Railway Station, Bhuj, Gujarat, India',
        ];
    }

    /**
     * Get demo dropoff location (Garden)
     */
    public static function getDemoDropoffLocation(): array
    {
        return [
            'latitude' => 23.2600,  // Garden location approximate coordinates (near railway station)
            'longitude' => 69.6800,
            'address' => 'Garden, Bhuj, Gujarat, India',
        ];
    }

    /**
     * Get demo ride type ID (taxi)
     */
    public static function getDemoRideTypeId(): ?int
    {
        $rideType = \App\Models\RideType::where('name', 'LIKE', '%taxi%')
            ->orWhere('name', 'LIKE', '%Taxi%')
            ->orWhere('name', 'LIKE', '%TAXI%')
            ->where('status', 1)
            ->first();

        return $rideType ? $rideType->id : null;
    }

    /**
     * Check if OTP matches demo OTP (accepts both 4 and 6 digit formats)
     */
    public static function isDemoOtp(string $otp): bool
    {
        if (!self::isEnabled()) {
            return false;
        }
        
        // Accept both 123456 (6 digits) and 1234 (4 digits) for backward compatibility
        $demoOtp = self::getDemoOtp(); // 123456
        $demoOtpShort = substr($demoOtp, 0, 4); // 1234
        
        return $otp === $demoOtp || $otp === $demoOtpShort;
    }
    
    /**
     * Get demo OTP for login (6 digits)
     */
    public static function getDemoLoginOtp(): string
    {
        return '123456';
    }

    /**
     * Get demo zone IDs (skip zone check by returning first available zones)
     */
    public static function getDemoZoneIds(int $cityId): array
    {
        $zones = \App\Models\Zone::where('city_id', $cityId)
            ->where('status', 1)
            ->limit(2)
            ->get();

        $pickupZoneId = $zones->first()?->id ?? null;
        $dropoffZoneId = $zones->count() > 1 ? $zones->last()?->id : $pickupZoneId;

        return [
            'pickup_zone_id' => $pickupZoneId,
            'dropoff_zone_id' => $dropoffZoneId,
        ];
    }

    /**
     * Get demo wallet balance
     */
    public static function getDemoWalletBalance(): float
    {
        return 5000.00; // ₹5000 demo balance
    }

    /**
     * Get demo wallet transactions
     */
    public static function getDemoWalletTransactions(): array
    {
        return [
            [
                'id' => 1,
                'type' => 'credit',
                'description' => 'Trip earnings from booking #BK240101A1B2',
                'amount' => 250.00,
                'time' => now()->subHours(2)->format('g:i A'),
                'is_positive' => 1,
            ],
            [
                'id' => 2,
                'type' => 'credit',
                'description' => 'Trip earnings from booking #BK240101C3D4',
                'amount' => 180.50,
                'time' => now()->subHours(5)->format('g:i A'),
                'is_positive' => 1,
            ],
            [
                'id' => 3,
                'type' => 'debit',
                'description' => 'Commission deduction',
                'amount' => -50.00,
                'time' => now()->subDays(1)->format('g:i A'),
                'is_positive' => 0,
            ],
        ];
    }

    /**
     * Get demo earnings data
     */
    public static function getDemoEarningsData(): array
    {
        $today = now();
        $startOfWeek = $today->copy()->startOfWeek();
        
        $dailyEarnings = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $startOfWeek->copy()->addDays($i);
            $dailyEarnings[] = [
                'date' => (string) $date->day,
                'earning' => rand(200, 800), // Random demo earnings between ₹200-₹800
                'highlighted' => $date->day == $today->day ? 1 : 0,
            ];
        }

        return [
            'weekly_summary' => [
                'date_range' => $startOfWeek->format('d M') . ' - ' . $startOfWeek->copy()->addDays(6)->format('d M'),
                'total_earning_this_week' => (string) array_sum(array_column($dailyEarnings, 'earning')),
            ],
            'daily_earnings_chart' => [
                'y_axis_label' => 'USD',
                'y_axis_min' => 0,
                'y_axis_max' => 1000,
                'daily_data' => $dailyEarnings,
            ],
            'ride_summary' => [
                'time_online_hrs' => '8.5',
                'total_rides' => 15,
                'completed_rides' => 14,
                'completion_rate_percent' => 93,
                'average_rating' => 4.8,
            ],
        ];
    }

    /**
     * Get demo nearby drivers
     */
    public static function getDemoNearbyDrivers(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Demo Driver 1',
                'phone' => '9876543210',
                'rating' => 4.8,
                'distance' => 1.2,
                'vehicle' => [
                    'model' => 'Demo Car',
                    'registration_number' => 'DEMO-1234',
                ],
                'latitude' => 23.2510,
                'longitude' => 69.6710,
            ],
            [
                'id' => 2,
                'name' => 'Demo Driver 2',
                'phone' => '9876543211',
                'rating' => 4.9,
                'distance' => 2.5,
                'vehicle' => [
                    'model' => 'Demo Taxi',
                    'registration_number' => 'DEMO-5678',
                ],
                'latitude' => 23.2520,
                'longitude' => 69.6720,
            ],
        ];
    }

    /**
     * Get demo city (fallback city for demo mode)
     */
    public static function getDemoCity(): ?\App\Models\City
    {
        return \App\Models\City::where('status', 1)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->first();
    }
}

