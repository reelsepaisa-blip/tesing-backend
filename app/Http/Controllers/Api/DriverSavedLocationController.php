<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DriverSavedLocation;
use Illuminate\Support\Facades\Validator;

class DriverSavedLocationController extends Controller
{
    
    public function index(Request $request)
    {
        $driver = auth()->user();

        $savedLocations = DriverSavedLocation::where('driver_id', $driver->id)
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

    
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'address' => 'required|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'type' => 'nullable|string',
            'is_default' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();

        $existingLocation = DriverSavedLocation::where('driver_id', $driver->id)
            ->where('latitude', $request->latitude)
            ->where('longitude', $request->longitude)
            ->first();

        if ($existingLocation) {
            return response()->json([
                'success' => false,
                'message' => 'Location already exists'
            ], 409);
        }

        $type = $request->type ? trim($request->type) : 'custom';

        $isDefault = false;
        if ($request->has('is_default')) {
            $isDefault = $request->is_default == 1 || $request->is_default === true || $request->is_default === '1';
        } elseif (in_array($type, ['home', 'work'])) {
            $isDefault = true;
        }

        if ($isDefault && in_array($type, ['home', 'work'])) {
            DriverSavedLocation::where('driver_id', $driver->id)
                ->where('type', $type)
                ->update(['is_default' => false]);
        }

        $savedLocation = DriverSavedLocation::create([
            'driver_id' => $driver->id,
            'name' => $request->name,
            'address' => $request->address,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'type' => $type,
            'is_default' => $isDefault,
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

    
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'address' => 'sometimes|string',
            'latitude' => 'sometimes|numeric|between:-90,90',
            'longitude' => 'sometimes|numeric|between:-180,180',
            'type' => 'sometimes|string|max:50',
            'is_default' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();
        $location = DriverSavedLocation::where('driver_id', $driver->id)->findOrFail($id);

        $updateData = $request->only(['name', 'address', 'latitude', 'longitude']);

        if ($request->has('type')) {
            $updateData['type'] = trim($request->type);
        }

        if ($request->has('is_default')) {
            $isDefaultValue = $request->is_default == 1 || $request->is_default === true || $request->is_default === '1';
            $updateData['is_default'] = $isDefaultValue;

            if ($isDefaultValue) {
                $locationType = $updateData['type'] ?? $location->type;
                if (in_array($locationType, ['home', 'work'])) {
                    DriverSavedLocation::where('driver_id', $driver->id)
                        ->where('type', $locationType)
                        ->where('id', '!=', $id)
                        ->update(['is_default' => false]);
                }
            }
        }

        $location->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Location updated successfully',
            'data' => [
                'id' => $location->id,
                'name' => $location->name,
                'address' => $location->address,
                'latitude' => $location->latitude,
                'longitude' => $location->longitude,
                'type' => $location->type,
                'is_default' => $location->is_default,
            ]
        ]);
    }

    
    public function destroy($id)
    {
        $driver = auth()->user();
        $location = DriverSavedLocation::where('driver_id', $driver->id)->findOrFail($id);

        $location->delete();

        return response()->json([
            'success' => true,
            'message' => 'Location deleted successfully'
        ]);
    }

    
    public function getByType(Request $request, $type)
    {
        $validator = Validator::make(['type' => $type], [
            'type' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid location type',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();

        $savedLocations = DriverSavedLocation::where('driver_id', $driver->id)
            ->where('type', $type)
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

    
    public function setDefault(Request $request, $id)
    {
        $driver = auth()->user();
        $location = DriverSavedLocation::where('driver_id', $driver->id)->findOrFail($id);

        DriverSavedLocation::where('driver_id', $driver->id)
            ->where('type', $location->type)
            ->where('id', '!=', $id)
            ->update(['is_default' => false]);

        $location->update(['is_default' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Default location updated successfully',
            'data' => [
                'id' => $location->id,
                'name' => $location->name,
                'address' => $location->address,
                'latitude' => $location->latitude,
                'longitude' => $location->longitude,
                'type' => $location->type,
                'is_default' => $location->is_default,
            ]
        ]);
    }
}
