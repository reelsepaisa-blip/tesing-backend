<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use App\Services\ZoneService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ZoneController extends Controller
{
    protected ZoneService $zoneService;

    public function __construct(ZoneService $zoneService)
    {
        $this->zoneService = $zoneService;
    }

    
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'city_id' => ['required', 'integer', 'exists:cities,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $zones = $this->zoneService->getZonesByCity($request->city_id);

            return response()->json([
                'zones' => $zones,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch zones',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function getZonesForPoint(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $zones = $this->zoneService->getZonesContainingPoint(
                $request->latitude,
                $request->longitude
            );

            return response()->json([
                'zones' => $zones,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch zones',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function checkPoint(Request $request, Zone $zone): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $isInZone = $this->zoneService->isPointInZone(
                $zone,
                $request->latitude,
                $request->longitude
            );

            return response()->json([
                'is_in_zone' => $isInZone,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to check point',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function getArea(Zone $zone): JsonResponse
    {
        try {
            $area = $this->zoneService->calculateZoneArea($zone);

            return response()->json([
                'area' => $area,
                'unit' => 'square_kilometers',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to calculate zone area',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
