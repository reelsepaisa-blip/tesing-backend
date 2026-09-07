<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GoogleMapsService
{
    private ?string $apiKey = null;
    private string $baseUrl = 'https://maps.googleapis.com/maps/api';

    /**
     * Get API key dynamically (lazy loading) to always read fresh config
     * This prevents stale config cache issues
     */
    private function getApiKey(): ?string
    {
        if ($this->apiKey === null) {
            $enabled = config('services.google_maps.enabled', true);
            $apiKey = config('services.google_maps.api_key');
            
            if (!$enabled) {
                $this->apiKey = '';
                return '';
            }
            
            if (empty($apiKey)) {
            }
            
            $this->apiKey = $apiKey ?? '';
        }
        return $this->apiKey ?: null;
    }

    /**
     * Force refresh API key from config (useful for debugging)
     */
    public function refreshApiKey(): void
    {
        $this->apiKey = null;
        $this->getApiKey();
    }

    public function hasApiKey(): bool
    {
        return !empty($this->getApiKey());
    }

    public function getDistanceAndDuration(float $originLat, float $originLng, float $destLat, float $destLng, string $mode = 'driving'): array
    {
        if (!$this->hasApiKey()) {
            return $this->calculateHaversineDistance($originLat, $originLng, $destLat, $destLng);
        }

        $cacheKey = "google_directions_{$originLat}_{$originLng}_{$destLat}_{$destLng}_{$mode}";

        return Cache::remember($cacheKey, 3600, function () use ($originLat, $originLng, $destLat, $destLng, $mode) {
            $url = "{$this->baseUrl}/directions/json";

            $response = Http::timeout(30)  // Set 30 second timeout
                ->retry(3, 1000)  // Retry 3 times with 1 second delay
                ->get($url, [
                    'origin' => "{$originLat},{$originLng}",
                    'destination' => "{$destLat},{$destLng}",
                    'key' => $this->getApiKey(),
                    'mode' => $mode,
                    'units' => 'metric',
                    'traffic_model' => 'best_guess',
                    'departure_time' => 'now',
                    'alternatives' => 'true',  // Get alternative routes
                    'optimize' => 'true',  // Optimize for best route
                ]);

            if ($response->successful()) {
                $data = $response->json();

                if ($data['status'] === 'OK' && !empty($data['routes'])) {
                    $bestRoute = null;
                    $shortestDistance = PHP_FLOAT_MAX;

                    foreach ($data['routes'] as $route) {
                        $leg = $route['legs'][0];
                        $distance = $leg['distance']['value'] / 1000;

                        if ($distance < $shortestDistance) {
                            $shortestDistance = $distance;
                            $bestRoute = $route;
                        }
                    }

                    if ($bestRoute) {
                        $leg = $bestRoute['legs'][0];

                        return [
                            'distance' => $leg['distance']['value'] / 1000,  // Convert meters to kilometers
                            'duration' => $leg['duration']['value'] / 60,  // Convert seconds to minutes
                            'duration_in_traffic' => isset($leg['duration_in_traffic']) ? $leg['duration_in_traffic']['value'] / 60 : null,
                            'polyline' => $bestRoute['overview_polyline']['points'] ?? null,
                        ];
                    }
                }
            }

            return $this->calculateHaversineDistance($originLat, $originLng, $destLat, $destLng);
        });
    }

    /**
     * Get detailed route with steps for waypoint calculation
     */
    public function getRouteWithSteps(float $originLat, float $originLng, float $destLat, float $destLng, string $mode = 'driving'): ?array
    {
        if (!$this->hasApiKey()) {
            return null;
        }

        $cacheKey = "google_route_steps_{$originLat}_{$originLng}_{$destLat}_{$destLng}_{$mode}";

        return Cache::remember($cacheKey, 3600, function () use ($originLat, $originLng, $destLat, $destLng, $mode) {
            $url = "{$this->baseUrl}/directions/json";

            $response = Http::timeout(30)
                ->retry(3, 1000)
                ->get($url, [
                    'origin' => "{$originLat},{$originLng}",
                    'destination' => "{$destLat},{$destLng}",
                    'key' => $this->getApiKey(),
                    'mode' => $mode,
                    'units' => 'metric',
                ]);

            if ($response->successful()) {
                $data = $response->json();

                if ($data['status'] === 'OK' && !empty($data['routes'])) {
                    $bestRoute = null;
                    $shortestDistance = PHP_FLOAT_MAX;

                    foreach ($data['routes'] as $route) {
                        $leg = $route['legs'][0];
                        $distance = $leg['distance']['value'] / 1000;

                        if ($distance < $shortestDistance) {
                            $shortestDistance = $distance;
                            $bestRoute = $route;
                        }
                    }

                    if ($bestRoute && !empty($bestRoute['legs'][0]['steps'])) {
                        return [
                            'steps' => $bestRoute['legs'][0]['steps'],
                            'polyline' => $bestRoute['overview_polyline']['points'] ?? null,
                        ];
                    }
                }
            }

            return null;
        });
    }

    /**
     * Decode Google Maps polyline to array of lat/lng points
     */
    public function decodePolyline(string $encoded): array
    {
        $points = [];
        $index = 0;
        $len = strlen($encoded);
        $lat = 0;
        $lng = 0;

        while ($index < $len) {
            $b = 0;
            $shift = 0;
            $result = 0;
            do {
                $b = ord($encoded[$index++]) - 63;
                $result |= ($b & 0x1F) << $shift;
                $shift += 5;
            } while ($b >= 0x20);
            $dlat = (($result & 1) ? ~($result >> 1) : ($result >> 1));
            $lat += $dlat;

            $shift = 0;
            $result = 0;
            do {
                $b = ord($encoded[$index++]) - 63;
                $result |= ($b & 0x1F) << $shift;
                $shift += 5;
            } while ($b >= 0x20);
            $dlng = (($result & 1) ? ~($result >> 1) : ($result >> 1));
            $lng += $dlng;

            $points[] = [
                'lat' => $lat * 1.0e-5,
                'lng' => $lng * 1.0e-5,
            ];
        }

        return $points;
    }

    private function calculateHaversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): array
    {
        if (abs($lat1 - $lat2) < 0.000001 && abs($lon1 - $lon2) < 0.000001) {
            return [
                'distance' => 0.0,
                'duration' => 0,
                'duration_in_traffic' => null,
                'polyline' => null,
            ];
        }

        $earthRadius = 6371;  // Earth's radius in kilometers

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lonDelta / 2) * sin($lonDelta / 2);

        if ($a >= 1.0) {
            $a = 0.999999;
        }

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earthRadius * $c;

        $averageSpeed = 30;  // km/h
        $duration = ($distance / $averageSpeed) * 60;  // Convert to minutes

        return [
            'distance' => round($distance, 2),
            'duration' => (int) round($duration),
            'duration_in_traffic' => null,
            'polyline' => null,
        ];
    }

    public function getPlaceDetails(string $placeId): ?array
    {
        if (!$this->hasApiKey()) {
            return null;
        }

        $cacheKey = "google_place_{$placeId}";

        return Cache::remember($cacheKey, 86400, function () use ($placeId) {
            $url = "{$this->baseUrl}/place/details/json";

            $response = Http::timeout(30)
                ->retry(2, 1000)
                ->get($url, [
                    'place_id' => $placeId,
                    'key' => $this->getApiKey(),
                    'fields' => 'formatted_address,geometry,name,place_id',
                ]);

            if ($response->successful()) {
                $data = $response->json();

                if ($data['status'] === 'OK') {
                    return $data['result'];
                }
            }

            return null;
        });
    }

    public function geocodeAddress(string $address): ?array
    {
        if (!$this->hasApiKey()) {
            return null;
        }

        $cacheKey = 'google_geocode_' . md5($address);

        return Cache::remember($cacheKey, 86400, function () use ($address) {
            $url = "{$this->baseUrl}/geocode/json";

            $response = Http::timeout(30)
                ->retry(2, 1000)
                ->get($url, [
                    'address' => $address,
                    'key' => $this->getApiKey(),
                ]);

            if ($response->successful()) {
                $data = $response->json();

                if ($data['status'] === 'OK' && !empty($data['results'])) {
                    $result = $data['results'][0];
                    $location = $result['geometry']['location'];

                    return [
                        'latitude' => $location['lat'],
                        'longitude' => $location['lng'],
                        'formatted_address' => $result['formatted_address'],
                        'place_id' => $result['place_id'],
                    ];
                }
            }

            return null;
        });
    }

    public function reverseGeocode(float $latitude, float $longitude): ?array
    {
        if (!$this->hasApiKey()) {
            return null;
        }

        $cacheKey = "google_reverse_geocode_{$latitude}_{$longitude}";

        return Cache::remember($cacheKey, 86400, function () use ($latitude, $longitude) {
            $url = "{$this->baseUrl}/geocode/json";

            $response = Http::timeout(30)
                ->retry(2, 1000)
                ->get($url, [
                    'latlng' => "{$latitude},{$longitude}",
                    'key' => $this->getApiKey(),
                ]);

            if ($response->successful()) {
                $data = $response->json();

                if ($data['status'] === 'OK' && !empty($data['results'])) {
                    $result = $data['results'][0];

                    return [
                        'formatted_address' => $result['formatted_address'],
                        'place_id' => $result['place_id'],
                        'components' => $result['address_components'] ?? [],
                    ];
                }
            }

            return null;
        });
    }
}
