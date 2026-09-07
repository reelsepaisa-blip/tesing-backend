<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\SavedLocation;
use App\Models\RecentSearch;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    
    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:2',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius' => 'nullable|integer|min:1000|max:50000', // 1km to 50km
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $query = $request->input('query');
        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        $radius = $request->input('radius', 10000); // Default 10km

        $cacheKey = "location_search_{$query}_{$latitude}_{$longitude}_{$radius}";

        if (Cache::has($cacheKey)) {
            return response()->json([
                'success' => true,
                'data' => Cache::get($cacheKey)
            ]);
        }

        try {
            $apiKey = config('app.etaxi.google_places_api_key');
            $url = "https://maps.googleapis.com/maps/api/place/textsearch/json";

            $params = [
                'query' => $query,
                'key' => $apiKey,
                'language' => 'en',
                'type' => 'establishment',
            ];

            if ($latitude && $longitude) {
                $params['location'] = "{$latitude},{$longitude}";
                $params['radius'] = $radius;
            }

            $response = Http::get($url, $params);
            $data = $response->json();

            if ($data['status'] === 'OK') {
                $results = collect($data['results'])->map(function ($place) {
                    return [
                        'id' => $place['place_id'],
                        'name' => $place['name'],
                        'address' => $place['formatted_address'],
                        'latitude' => $place['geometry']['location']['lat'],
                        'longitude' => $place['geometry']['location']['lng'],
                        'types' => $place['types'],
                        'rating' => $place['rating'] ?? null,
                        'photos' => isset($place['photos']) ? array_slice($place['photos'], 0, 3) : [],
                    ];
                })->take(20); // Limit to 20 results

                Cache::put($cacheKey, $results, 3600);

                if (auth()->check()) {
                    $this->saveRecentSearch(auth()->id(), $query, $results->first());
                }

                return response()->json([
                    'success' => true,
                    'data' => $results
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No locations found',
                'data' => []
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Location search failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function getSavedLocations(Request $request)
    {
        $user = auth()->user();

        $savedLocations = SavedLocation::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($location) {
                return [
                    'id' => $location->id,
                    'name' => $location->name,
                    'address' => $location->address,
                    'latitude' => $location->latitude,
                    'longitude' => $location->longitude,
                    'type' => $location->type,
                    'is_default' => $location->is_default,
                    'created_at' => $location->created_at->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $savedLocations
        ]);
    }

    
    public function saveLocation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'address' => 'required|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'type' => 'nullable|string|in:home,work,custom',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();

        $existingLocation = SavedLocation::where('user_id', $user->id)
            ->where('latitude', $request->latitude)
            ->where('longitude', $request->longitude)
            ->first();

        if ($existingLocation) {
            return response()->json([
                'success' => false,
                'message' => 'Location already exists'
            ], 409);
        }

        $type = $request->type ? strtolower($request->type) : 'custom';

        if (in_array($type, ['home', 'work'])) {
            SavedLocation::where('user_id', $user->id)
                ->where('type', $type)
                ->update(['is_default' => false]);
        }

        $savedLocation = SavedLocation::create([
            'user_id' => $user->id,
            'name' => $request->name,
            'address' => $request->address,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'type' => $type,
            'is_default' => in_array($type, ['home', 'work']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Location saved successfully',
            'data' => [
                'id' => $savedLocation->id,
                'name' => $savedLocation->name,
                'address' => $savedLocation->address,
                'latitude' => $savedLocation->latitude,
                'longitude' => $savedLocation->longitude,
                'type' => $savedLocation->type,
                'is_default' => $savedLocation->is_default,
            ]
        ]);
    }

    
    public function updateLocation(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'address' => 'sometimes|string',
            'latitude' => 'sometimes|numeric|between:-90,90',
            'longitude' => 'sometimes|numeric|between:-180,180',
            'type' => 'sometimes|string|in:home,work,custom',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();
        $location = SavedLocation::where('user_id', $user->id)->findOrFail($id);

        $updateData = $request->only(['name', 'address', 'latitude', 'longitude']);

        if ($request->has('type')) {
            $updateData['type'] = strtolower($request->type);
        }

        $location->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Location updated successfully',
            'data' => $location
        ]);
    }

    
    public function deleteLocation($id)
    {
        $user = auth()->user();
        $location = SavedLocation::where('user_id', $user->id)->findOrFail($id);

        $location->delete();

        return response()->json([
            'success' => true,
            'message' => 'Location deleted successfully'
        ]);
    }

    
    public function getRecentSearches(Request $request)
    {
        $user = auth()->user();

        $recentSearches = RecentSearch::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->map(function ($search) {
                return [
                    'id' => $search->id,
                    'query' => $search->query,
                    'name' => $search->name,
                    'address' => $search->address,
                    'latitude' => $search->latitude,
                    'longitude' => $search->longitude,
                    'searched_at' => $search->created_at->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $recentSearches
        ]);
    }

    
    public function getCurrentLocation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $latitude = $request->latitude;
        $longitude = $request->longitude;

        try {
            $apiKey = config('app.etaxi.google_geocoding_api_key');
            $url = "https://maps.googleapis.com/maps/api/geocode/json";

            $response = Http::get($url, [
                'latlng' => "{$latitude},{$longitude}",
                'key' => $apiKey,
                'language' => 'en',
            ]);

            $data = $response->json();

            if ($data['status'] === 'OK' && !empty($data['results'])) {
                $result = $data['results'][0];

                return response()->json([
                    'success' => true,
                    'data' => [
                        'name' => $this->extractLocationName($result),
                        'address' => $result['formatted_address'],
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'components' => $this->extractAddressComponents($result),
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Could not determine location',
                'data' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Location lookup failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    private function saveRecentSearch($userId, $query, $location = null)
    {
        RecentSearch::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->skip(19)
            ->take(100)
            ->delete();

        $existingSearch = RecentSearch::where('user_id', $userId)
            ->where('query', $query)
            ->first();

        if ($existingSearch) {
            $existingSearch->update(['created_at' => now()]);
            return;
        }

        RecentSearch::create([
            'user_id' => $userId,
            'query' => $query,
            'name' => $location['name'] ?? null,
            'address' => $location['address'] ?? null,
            'latitude' => $location['latitude'] ?? null,
            'longitude' => $location['longitude'] ?? null,
        ]);
    }

    
    private function extractLocationName($result)
    {
        $types = $result['types'];

        $priorityTypes = ['establishment', 'point_of_interest', 'premise', 'subpremise'];

        foreach ($priorityTypes as $type) {
            if (in_array($type, $types)) {
                return $result['name'];
            }
        }

        if (!empty($result['address_components'])) {
            foreach ($result['address_components'] as $component) {
                if (in_array('establishment', $component['types'])) {
                    return $component['long_name'];
                }
            }
        }

        return $result['formatted_address'];
    }

    
    private function extractAddressComponents($result)
    {
        $components = [];

        foreach ($result['address_components'] as $component) {
            $types = $component['types'];

            if (in_array('street_number', $types)) {
                $components['street_number'] = $component['long_name'];
            } elseif (in_array('route', $types)) {
                $components['street'] = $component['long_name'];
            } elseif (in_array('locality', $types)) {
                $components['city'] = $component['long_name'];
            } elseif (in_array('administrative_area_level_1', $types)) {
                $components['state'] = $component['long_name'];
            } elseif (in_array('country', $types)) {
                $components['country'] = $component['long_name'];
            } elseif (in_array('postal_code', $types)) {
                $components['postal_code'] = $component['long_name'];
            }
        }

        return $components;
    }
}
