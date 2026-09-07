<?php

namespace App\Services;

use App\Models\City;
use App\Models\RideType;
use App\Models\CityTaxRule;
use App\Models\Booking;

class EnhancedFareCalculationService
{
    
    public function calculateFareWithTaxes(
        City $city,
        RideType $rideType,
        float $distance,
        int $duration,
        ?float $waitingTime = 0,
        ?float $surgeMultiplier = 1.0,
        bool $isCancelled = false
    ): array {
        $baseFare = $this->calculateBaseFare($rideType, $city, $distance, $duration, $waitingTime, $isCancelled);

        if ($isCancelled) {
            return $baseFare;
        }

        $subtotal = $baseFare['subtotal'] * $surgeMultiplier;
        $surgeAmount = $subtotal - $baseFare['subtotal'];

        $taxRules = CityTaxRule::where('city_id', $city->id)
            ->active()
            ->ordered()
            ->get();

        $taxBreakdown = [];
        $totalTaxAmount = 0;

        foreach ($taxRules as $taxRule) {
            $taxAmount = $subtotal * ($taxRule->tax_rate / 100);
            $taxBreakdown[] = [
                'name' => $taxRule->tax_name,
                'rate' => $taxRule->tax_rate,
                'amount' => round($taxAmount, 2),
            ];
            $totalTaxAmount += $taxAmount;
        }

        $total = $subtotal + $totalTaxAmount;

        return [
            'base_fare' => round($baseFare['base_fare'], 2),
            'distance_fare' => round($baseFare['distance_fare'], 2),
            'time_fare' => round($baseFare['time_fare'], 2),
            'waiting_charge' => round($baseFare['waiting_charge'], 2),
            'night_charge' => round($baseFare['night_charge'], 2),
            'surge_amount' => round($surgeAmount, 2),
            'subtotal' => round($subtotal, 2),
            'tax_breakdown' => $taxBreakdown,
            'total_tax' => round($totalTaxAmount, 2),
            'total' => round($total, 2),
            'commission' => round($total * ($rideType->commission_rate / 100), 2),
            'driver_payout' => round($total * (1 - $rideType->commission_rate / 100), 2),
        ];
    }

    
    protected function calculateBaseFare(
        RideType $rideType,
        City $city,
        float $distance,
        int $duration,
        float $waitingTime,
        bool $isCancelled
    ): array {
        $pricing = $rideType->getPriceForCity($city);
        $multiplier = $city->getFareMultiplier();

        if ($isCancelled) {
            return [
                'base_fare' => 0,
                'distance_fare' => 0,
                'time_fare' => 0,
                'waiting_charge' => 0,
                'night_charge' => 0,
                'subtotal' => $pricing['cancellation_charge'],
            ];
        }

        $baseFare = $pricing['base_price'];

        $extraDistance = max(0, $distance - $pricing['base_distance']);
        $distanceFare = $extraDistance * $pricing['price_per_km'];

        $timeFare = $duration * $pricing['price_per_minute'];

        $waitingCharge = 0;
        if ($waitingTime > $pricing['waiting_time_limit']) {
            $extraWaitingTime = $waitingTime - $pricing['waiting_time_limit'];
            $waitingCharge = $extraWaitingTime * $pricing['waiting_charge_per_minute'];
        }

        $subtotal = ($baseFare + $distanceFare + $timeFare + $waitingCharge) * $multiplier;

        $subtotal = max($subtotal, $pricing['minimum_fare']);

        $nightCharge = $multiplier > 1 ? ($subtotal / $multiplier) * ($multiplier - 1) : 0;

        return [
            'base_fare' => $baseFare,
            'distance_fare' => $distanceFare,
            'time_fare' => $timeFare,
            'waiting_charge' => $waitingCharge,
            'night_charge' => $nightCharge,
            'subtotal' => $subtotal,
        ];
    }

    
    public function getCityTaxSummary(City $city): array
    {
        $taxRules = CityTaxRule::where('city_id', $city->id)
            ->active()
            ->ordered()
            ->get();

        return $taxRules->map(function ($rule) {
            return [
                'name' => $rule->tax_name,
                'rate' => $rule->tax_rate,
                'description' => $rule->description,
            ];
        })->toArray();
    }

    
    public function calculateTaxes(City $city, float $amount): array
    {
        $taxRules = CityTaxRule::where('city_id', $city->id)
            ->active()
            ->ordered()
            ->get();

        $taxBreakdown = [];
        $totalTaxAmount = 0;
        $totalTaxRate = 0;

        foreach ($taxRules as $taxRule) {
            $taxAmount = $amount * ($taxRule->tax_rate / 100);
            $taxBreakdown[] = [
                'name' => $taxRule->tax_name,
                'rate' => $taxRule->tax_rate,
                'amount' => round($taxAmount, 2),
            ];
            $totalTaxAmount += $taxAmount;
            $totalTaxRate += $taxRule->tax_rate;
        }

        return [
            'tax_breakdown' => $taxBreakdown,
            'total_tax_amount' => round($totalTaxAmount, 2),
            'total_tax_rate' => round($totalTaxRate, 2),
        ];
    }
}
