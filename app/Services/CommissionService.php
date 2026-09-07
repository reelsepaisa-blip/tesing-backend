<?php

namespace App\Services;

use App\Models\Booking;

class CommissionService
{
    
    public function calculateCommission(Booking $booking, float $amount): array
    {
        $commissionRate = $booking->admin_commission_rate ?? null;

        if ($commissionRate === null) {
            $rideType = $booking->rideType ?? null;
            if ($rideType) {
                $city = $booking->pickupZone->city ?? null;
                if ($city) {
                    $pricing = $rideType->getPriceForCity($city);
                    $commissionRate = $pricing['commission_rate'] ?? 20.0;
                } else {
                    $commissionRate = $rideType->commission_rate ?? 20.0;
                }
            } else {
                $commissionRate = 20.0; // 20% default
            }
        }

        $commissionAmount = ($amount * $commissionRate) / 100;

        return [
            'commission_rate' => $commissionRate,
            'commission_amount' => round($commissionAmount, 2),
            'driver_amount' => round($amount - $commissionAmount, 2),
        ];
    }
}
