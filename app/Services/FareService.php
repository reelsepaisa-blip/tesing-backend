<?php

namespace App\Services;

use App\Models\City;
use App\Models\RideType;

use Illuminate\Support\Collection;

class FareService
{
    protected CityService $cityService;

    public function __construct(CityService $cityService)
    {
        $this->cityService = $cityService;
    }

    public function calculateEstimatedFare(array $params): array
    {
        $discount_amount = $params['discount_amount'] ?? 0;
        $rideType = RideType::findOrFail($params['ride_type_id']);
        $city = City::findOrFail($params['city_id']);
        $pricing = $rideType->getPriceForCity($city);
        
        $surgeMultiplier = $this->cityService->getSurgeMultiplier(
            $city->id,
            $params['pickup_latitude'],
            $params['pickup_longitude']
        );

        $nightChargesMultiplier = $this->cityService->getNightChargesMultiplier($city);

        $baseFare = $this->calculateBaseFare($pricing);

        $distanceFare = $this->calculateDistanceFare($rideType, $params['distance'], $pricing);

        $timeFare = $this->calculateTimeFare($rideType, $params['duration'], $pricing);

        $subtotal = $baseFare + $distanceFare + $timeFare;

        $nightChargesAmount = $subtotal * ($nightChargesMultiplier - 1);
        $subtotalWithNight = $subtotal + $nightChargesAmount;

        if ($surgeMultiplier > 1) {
            $total = $subtotalWithNight * $surgeMultiplier;
            $surgeAmount = $total - $subtotalWithNight;
        } else {
            $total = $subtotalWithNight;
            $surgeAmount = 0;
        }

        $total = max($total, (float) ($pricing['minimum_fare'] ?? $rideType->minimum_fare));

        $bookingFee = $this->getBookingFee($rideType, $city);
        $total += $bookingFee;

        $enhancedFareService = app(EnhancedFareCalculationService::class);
        $taxData = $enhancedFareService->calculateTaxes($city, $total);
        $finalTotal = $total + $taxData['total_tax_amount'];

        $discount = (float) $discount_amount;

        $totalBeforeDiscount = $finalTotal;
        $finalTotal = max(0, $finalTotal - $discount);  // prevent negative

        return [
            'base_fare' => round($baseFare, 2),
            'distance_fare' => round($distanceFare, 2),
            'time_fare' => round($timeFare, 2),
            'subtotal' => round($subtotal, 2),
            'surge_multiplier' => $surgeMultiplier,
            'surge_amount' => round($surgeAmount, 2),
            'night_charges_multiplier' => $nightChargesMultiplier,
            'night_charges_amount' => round($nightChargesAmount, 2),
            'booking_fee' => round($bookingFee, 2),
            'tax_rate' => $taxData['total_tax_rate'],
            'tax_amount' => round($taxData['total_tax_amount'], 2),
            'tax_breakdown' => $taxData['tax_breakdown'],
            'total' => round($finalTotal, 2),
            'total_before_discount' => round($totalBeforeDiscount, 2),
            'discount_amount' => round($discount, 2),
            'currency' => $city->currency ?? 'INR',
        ];
    }

    protected function calculateBaseFare(array $pricing): float
    {
        return (float) ($pricing['base_price'] ?? 0);
    }

    protected function calculateDistanceFare(RideType $rideType, float $distance, array $pricing): float
    {
        // Ensure minimum distance of 1 km if distance is less than 0
        if ($distance < 0) {
            $distance = 1.0;
        }
        
        $chargeableDistance = max(0, $distance - (float) ($pricing['base_distance'] ?? 0));

        if ($chargeableDistance <= 0) {
            return 0;
        }

        if (empty($rideType->distance_slabs)) {
            $rate = (float) ($pricing['price_per_km'] ?? $rideType->price_per_km);
            return $chargeableDistance * $rate;
        }

        $fare = 0;
        $remainingDistance = $chargeableDistance;
        $slabs = collect($rideType->distance_slabs)
            ->sortBy('from')
            ->values();

        foreach ($slabs as $slab) {
            if ($remainingDistance <= 0) {
                break;
            }

            $slabDistance = isset($slab['to'])
                ? min($remainingDistance, $slab['to'] - $slab['from'])
                : $remainingDistance;

            $fare += $slabDistance * $slab['rate'];
            $remainingDistance -= $slabDistance;
        }

        if ($remainingDistance > 0 && $slabs->isNotEmpty()) {
            $lastSlab = $slabs->last();
            $fare += $remainingDistance * $lastSlab['rate'];
        }

        return $fare;
    }

    protected function calculateTimeFare(RideType $rideType, float $duration, array $pricing): float
    {
        $pricePerMinute = (float) ($pricing['price_per_minute'] ?? $rideType->price_per_minute);
        $baseFare = $duration * $pricePerMinute;

        if (empty($rideType->time_multipliers)) {
            return $baseFare;
        }

        $currentHour = (int) now()->format('H');

        $multiplier = collect($rideType->time_multipliers)
            ->first(function ($timeSlot) use ($currentHour) {
                return $currentHour >= $timeSlot['from'] && $currentHour <= $timeSlot['to'];
            });

        return $baseFare * ($multiplier['rate'] ?? 1);
    }

    protected function getBookingFee(RideType $rideType, City $city): float
    {
        return 0;  // No booking fee for now
    }

    protected function calculateNightCharges(RideType $rideType, City $city): float
    {
        $currentHour = (int) now()->format('H');
        $nightStart = (int) date('H', strtotime($city->night_start_time));
        $nightEnd = (int) date('H', strtotime($city->night_end_time));
        $nightMultiplier = $city->night_charge_multiplier;

        $isNightTime = ($currentHour >= $nightStart || $currentHour <= $nightEnd);

        if (!$isNightTime) {
            return 0;
        }

        $nightCharge = $rideType->base_price * 0.1;  // 10% of base price

        return $nightCharge * $nightMultiplier;
    }

    public function getCancellationCharge(array $params): array
    {
        $rideType = RideType::findOrFail($params['ride_type_id']);
        $city = City::findOrFail($params['city_id']);
        $bookingDuration = $params['booking_duration'] ?? 0;
        $tripAmount = $params['trip_amount'] ?? 0;
        $bookingStatus = $params['booking_status'] ?? null;

        $policy = $this->cityService->getCancellationPolicy($city, $params['ride_type_id']);

        if (!$policy) {
            

            return [
                'charge' => 0,
                'currency' => $city->currency ?? 'INR',
            ];
        }

        if ($bookingStatus && !in_array($bookingStatus, ['accepted', 'arrived', 'started'], true)) {
            return [
                'charge' => 0,
                'currency' => $city->currency ?? 'INR',
                'can_cancel' => true,
            ];
        }

        if ($policy->allow_customer_cancellation && $bookingDuration <= $policy->free_cancellation_window) {
            

            return [
                'charge' => 0,
                'currency' => $city->currency ?? 'INR',
            ];
        }

        $response = [
            'currency' => $city->currency ?? 'INR',
            'can_cancel' => true,
        ];

        $charge = $policy->calculateCancellationFee($tripAmount);

        

        return array_merge($response, [
            'charge' => $charge,
            'policy' => $policy,
        ]);
    }

    public function calculateWaitingCharge(RideType $rideType, int $waitingTime): float
    {
        $waitingChargePerMinute = $rideType->settings['waiting_charge_per_minute'] ?? 0;
        $waitingChargeFreeLimit = $rideType->settings['waiting_charge_free_limit'] ?? 0;

        if ($waitingTime <= $waitingChargeFreeLimit) {
            return 0;
        }

        $chargeableTime = $waitingTime - $waitingChargeFreeLimit;
        return $chargeableTime * $waitingChargePerMinute;
    }

    public function calculateDriverCommission(float $tripAmount, float $commissionRate): array
    {
        $commission = $tripAmount * ($commissionRate / 100);
        $driverEarning = $tripAmount - $commission;

        return [
            'commission' => round($commission, 2),
            'driver_earning' => round($driverEarning, 2),
        ];
    }

    public function getAvailablePromotions(array $params): Collection
    {
        return collect([]);
    }

    public function applyPromotion(array $fare, string $promoCode): array
    {
        return $fare;
    }

    public function calculateFinalFare(array $params): array
    {
        $rideType = RideType::findOrFail($params['ride_type_id']);
        $city = City::findOrFail($params['city_id']);

        $pricing = $rideType->getPriceForCity($city);
        $actualDistance = $params['actual_distance'];
        $actualDuration = $params['actual_duration'];
        $waitingTime = $params['waiting_time'] ?? 0;
        $baseFare = $params['base_fare'];
        $surgeMultiplier = $params['surge_multiplier'] ?? 1;

        $distanceFare = $this->calculateDistanceFare($rideType, $actualDistance, $pricing);

        $timeFare = $this->calculateTimeFare($rideType, $actualDuration, $pricing);

        $waitingCharge = $this->calculateWaitingCharge($rideType, $waitingTime);

        $nightCharge = $this->calculateNightCharges($rideType, $city);

        $baseBeforeSurge = $baseFare + $distanceFare + $timeFare + $waitingCharge + $nightCharge;

        if ($surgeMultiplier > 1) {
            $subtotal = $baseBeforeSurge * $surgeMultiplier;
            $surgeAmount = $subtotal - $baseBeforeSurge;
        } else {
            $subtotal = $baseBeforeSurge;
            $surgeAmount = 0;
        }

        $taxService = app(EnhancedFareCalculationService::class);
        $taxData = $taxService->calculateTaxes($city, $subtotal);
        $taxRate = $taxData['total_tax_rate'];
        $taxAmount = $taxData['total_tax_amount'];

        $total = $subtotal + $taxAmount;

        return [
            'base_fare' => round($baseFare, 2),
            'distance_fare' => round($distanceFare, 2),
            'time_fare' => round($timeFare, 2),
            'waiting_charge' => round($waitingCharge, 2),
            'night_charge' => round($nightCharge, 2),
            'surge_amount' => round($surgeAmount, 2),
            'subtotal' => round($subtotal, 2),
            'tax_rate' => $taxRate,
            'tax_amount' => round($taxAmount, 2),
            'tax_breakdown' => $taxData['tax_breakdown'],
            'total' => round($total, 2),
        ];
    }
}
