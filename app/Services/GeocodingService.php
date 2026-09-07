<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;


class GeocodingService
{
    private ?string $apiKey;
    private string $baseUrl = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function __construct()
    {
        if (!config('services.google_maps.enabled')) {
            $this->apiKey = null;
            return;
        }
        $this->apiKey = config('services.google_maps.api_key');
    }


    public function getAddressFromCoordinates(float $latitude, float $longitude): string
    {
        if (empty($this->apiKey)) {

            return '';
        }

        try {
            $response = Http::timeout(10)->get($this->baseUrl, [
                'latlng' => "{$latitude},{$longitude}",
                'key' => $this->apiKey,
                'language' => 'en',
                'result_type' => 'street_address|route|locality|administrative_area_level_1|country'
            ]);

            if ($response->successful()) {
                $data = $response->json();

                if ($data['status'] === 'OK' && !empty($data['results'])) {
                    return $data['results'][0]['formatted_address'] ?? '';
                }
            }
        } catch (\Exception $e) {
        }

        return '';
    }


    public function getCoordinatesFromAddress(string $address): ?array
    {
        if (empty($this->apiKey) || empty($address)) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get($this->baseUrl, [
                'address' => $address,
                'key' => $this->apiKey,
                'language' => 'en'
            ]);

            if ($response->successful()) {
                $data = $response->json();

                if ($data['status'] === 'OK' && !empty($data['results'])) {
                    $location = $data['results'][0]['geometry']['location'] ?? null;
                    if ($location) {
                        return [
                            'latitude' => $location['lat'],
                            'longitude' => $location['lng']
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
        }

        return null;
    }


    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }


    public static function getDriverHotspots(int $driverId, int $limit = 5): array
    {
        try {
            $bookings = \App\Models\Booking::where('driver_id', $driverId)
                ->where('status', 'completed')
                ->whereNotNull('pickup_latitude')
                ->whereNotNull('pickup_longitude')
                ->select('pickup_latitude', 'pickup_longitude', 'pickup_address')
                ->get();

            if ($bookings->isEmpty()) {
                return [];
            }

            $locationGroups = [];
            foreach ($bookings as $booking) {
                $lat = round($booking->pickup_latitude, 3);
                $lng = round($booking->pickup_longitude, 3);
                $key = "{$lat},{$lng}";

                if (!isset($locationGroups[$key])) {
                    $locationGroups[$key] = [
                        'latitude' => $booking->pickup_latitude,
                        'longitude' => $booking->pickup_longitude,
                        'address' => $booking->pickup_address,
                        'count' => 0
                    ];
                }
                $locationGroups[$key]['count']++;
            }

            uasort($locationGroups, function ($a, $b) {
                return $b['count'] - $a['count'];
            });

            $hotspots = array_slice($locationGroups, 0, $limit, true);

            $result = [];
            foreach ($hotspots as $key => $hotspot) {
                $result[] = [
                    'location' => $hotspot['address'] ?: "Location ({$hotspot['latitude']}, {$hotspot['longitude']})",
                    'latitude' => (string) $hotspot['latitude'],
                    'longitude' => (string) $hotspot['longitude'],
                    'booking_count' => (string) $hotspot['count']
                ];
            }

            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }
}
