<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashCollectionPoint;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CashCollectionPointController extends Controller
{
    
    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            ]);

            $query = CashCollectionPoint::with('city')
                ->active()
                ->orderBy('name');

            if ($request->has('city_id') && $request->city_id) {
                $query->where('city_id', $request->city_id);
            }

            $cashCollectionPoints = $query->get()->map(function ($point) {
                return [
                    'id' => (string) $point->id,
                    'city_id' => (string) $point->city_id,
                    'city_name' => $point->city->name ?? '',
                    'name' => $point->name ?? '',
                    'address' => $point->address ?? '',
                    'contact_person' => $point->contact_person ?? '',
                    'contact_phone' => $point->contact_phone ?? '',
                    'contact_email' => $point->contact_email ?? '',
                    'latitude' => $point->latitude ? (string) $point->latitude : '',
                    'longitude' => $point->longitude ? (string) $point->longitude : '',
                    'operating_hours' => $point->operating_hours ?? [],
                    'is_active' => (string) ($point->is_active ? '1' : '0'),
                    'created_at' => $point->created_at ? $point->created_at->toISOString() : '',
                    'updated_at' => $point->updated_at ? $point->updated_at->toISOString() : '',
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Cash collection points retrieved successfully',
                'data' => [
                    'total_points' => (string) $cashCollectionPoints->count(),
                    'cash_collection_points' => $cashCollectionPoints,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve cash collection points',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

