<?php

namespace App\Services;

use App\Models\DriverMatchingSetting;
use App\Models\User;
use App\Models\Booking;
use Illuminate\Support\Collection;


class AdvancedDriverScoringService
{
    protected $idleWeights;
    protected $fallbackWeights;

    public function __construct()
    {
        $this->idleWeights = DriverMatchingSetting::getWeights('idle');
        $this->fallbackWeights = DriverMatchingSetting::getWeights('fallback');
    }

    
    public function scoreIdleDrivers(Collection $drivers, Booking $booking): Collection
    {

        $scoredDrivers = $drivers->map(function ($driver) use ($booking) {
            $scores = $this->calculateIdleDriverScores($driver, $booking);
            $driver->scoring_data = $scores;
            $driver->final_score = $this->calculateFinalScore($scores, $this->idleWeights);

            

            return $driver;
        })->sortByDesc('final_score')->values();


        return $scoredDrivers;
    }

    
    public function scoreFallbackDrivers(Collection $drivers, Booking $booking): Collection
    {

        $scoredDrivers = $drivers->map(function ($driver) use ($booking) {
            $scores = $this->calculateFallbackDriverScores($driver, $booking);
            $driver->scoring_data = $scores;
            $driver->final_score = $this->calculateFinalScore($scores, $this->fallbackWeights);

            

            return $driver;
        })->sortByDesc('final_score')->values();


        return $scoredDrivers;
    }

    
    protected function calculateIdleDriverScores(User $driver, Booking $booking): array
    {
        $profile = $driver->driverProfile;
        $currentLocation = $driver->currentLocation;

        $distance = $currentLocation ?
            $currentLocation->distanceTo($booking->pickup_latitude, $booking->pickup_longitude) :
            10000; // Max distance if no location
        $distanceScore = max(0, 100 - ($distance / 100)); // -1 point per 100m

        $tripsToday = $profile->trips_today ?? 0;
        $tripsTodayScore = max(0, 100 - ($tripsToday * 5)); // -5 points per trip

        $ratingScore = ((float) ($profile->rating ?? 0)) * 20; // 5-star = 100 points

        $totalRequests = ($profile->total_trips ?? 0) + ($profile->cancelled_trips ?? 0);
        $acceptanceRate = $totalRequests > 0 ?
            (($profile->total_trips ?? 0) / $totalRequests) * 100 : 100;
        $acceptanceRateScore = $acceptanceRate;

        $lastBookingAt = $driver->last_booking_at ?? $driver->created_at;
        $idleTime = now()->diffInMinutes($lastBookingAt);
        $idleTimeScore = min(100, max(0, $idleTime / 2)); // Max score after 200 minutes, minimum 0

        $cancelRate = $totalRequests > 0 ?
            (($profile->cancelled_trips ?? 0) / $totalRequests) * 100 : 0;
        $cancelRateScore = max(0, 100 - $cancelRate);

        return [
            'distance' => $distanceScore,
            'trips_today' => $tripsTodayScore,
            'rating' => $ratingScore,
            'acceptance_rate' => $acceptanceRateScore,
            'idle_time' => $idleTimeScore,
            'cancel_rate' => $cancelRateScore,
        ];
    }

    
    protected function calculateFallbackDriverScores(User $driver, Booking $booking): array
    {
        $profile = $driver->driverProfile;
        $currentLocation = $driver->currentLocation;

        $d2pDistance = $currentLocation ?
            $currentLocation->distanceTo($booking->pickup_latitude, $booking->pickup_longitude) :
            10000;
        $d2pDistanceScore = max(0, 100 - ($d2pDistance / 100));

        $tripsToday = $profile->trips_today ?? 0;
        $tripsTodayScore = max(0, 100 - ($tripsToday * 5));

        $ratingScore = ((float) ($profile->rating ?? 0)) * 20;

        $totalRequests = ($profile->total_trips ?? 0) + ($profile->cancelled_trips ?? 0);
        $acceptanceRate = $totalRequests > 0 ?
            (($profile->total_trips ?? 0) / $totalRequests) * 100 : 100;
        $acceptanceRateScore = $acceptanceRate;

        $fairnessScore = $this->calculateFairnessScore($driver, $booking);

        $cancelRate = $totalRequests > 0 ?
            (($profile->cancelled_trips ?? 0) / $totalRequests) * 100 : 0;
        $cancelRateScore = max(0, 100 - $cancelRate);

        return [
            'd2p_distance' => $d2pDistanceScore,
            'trips_today' => $tripsTodayScore,
            'rating' => $ratingScore,
            'acceptance_rate' => $acceptanceRateScore,
            'fairness_balance' => $fairnessScore,
            'cancel_rate' => $cancelRateScore,
        ];
    }

    
    protected function calculateFairnessScore(User $driver, Booking $booking): float
    {
        $recentBookings = Booking::where('driver_id', $driver->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->where('pickup_latitude', '>=', $booking->pickup_latitude - 0.01)
            ->where('pickup_latitude', '<=', $booking->pickup_latitude + 0.01)
            ->where('pickup_longitude', '>=', $booking->pickup_longitude - 0.01)
            ->where('pickup_longitude', '<=', $booking->pickup_longitude + 0.01)
            ->count();

        return max(0, 100 - ($recentBookings * 20));
    }

    
    protected function calculateFinalScore(array $scores, array $weights): float
    {
        $finalScore = 0;

        foreach ($scores as $parameter => $score) {
            if (isset($weights[$parameter])) {
                $finalScore += ($score / 100) * ($weights[$parameter] / 100);
            }
        }

        return $finalScore * 100; // Convert back to 0-100 scale
    }

    
    public function getScoringBreakdown(User $driver, Booking $booking, string $type = 'idle'): array
    {
        $weights = $type === 'idle' ? $this->idleWeights : $this->fallbackWeights;
        $scores = $type === 'idle' ?
            $this->calculateIdleDriverScores($driver, $booking) :
            $this->calculateFallbackDriverScores($driver, $booking);

        $breakdown = [];
        foreach ($scores as $parameter => $score) {
            $breakdown[$parameter] = [
                'score' => $score,
                'weight' => $weights[$parameter] ?? 0,
                'weighted_score' => ($score / 100) * (($weights[$parameter] ?? 0) / 100) * 100,
            ];
        }

        return $breakdown;
    }
}
