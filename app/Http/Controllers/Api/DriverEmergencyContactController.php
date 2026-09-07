<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmergencyContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DriverEmergencyContactController extends Controller
{
    
    public function index(): JsonResponse
    {
        $driver = Auth::user();

        if ($driver->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Only drivers can access this endpoint.',
            ], 403);
        }

        $contacts = EmergencyContact::byUser($driver->id)
            ->orderBy('is_primary', 'desc')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($contact) {
                return [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'mobile_number' => $contact->mobile_number,
                    'formatted_mobile' => $contact->formatted_mobile,
                    'is_primary' => $contact->is_primary,
                    'created_at' => $contact->created_at,
                    'updated_at' => $contact->updated_at,
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Emergency contacts retrieved successfully',
            'data' => [
                'contacts' => $contacts,
                'total_count' => $contacts->count(),
                'max_limit' => 5,
                'can_add_more' => $contacts->count() < 5,
            ],
        ]);
    }

    
    public function store(Request $request): JsonResponse
    {
        $driver = Auth::user();

        if ($driver->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Only drivers can access this endpoint.',
            ], 403);
        }

        $existingContactsCount = EmergencyContact::byUser($driver->id)->count();
        if ($existingContactsCount >= 5) {
            return response()->json([
                'success' => false,
                'message' => 'Maximum limit of 5 emergency contacts reached',
                'error' => 'CONTACT_LIMIT_EXCEEDED',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'mobile_number' => [
                'required',
                'string',
                'max:15',
                'regex:/^[0-9+\-\s()]+$/',
                Rule::unique('emergency_contacts', 'mobile_number')
                    ->where(fn ($query) => $query->where('user_id', $driver->id)),
            ],
            'is_primary' => 'sometimes|boolean',
        ]);


        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();
        $data['user_id'] = $driver->id;

        if ($existingContactsCount === 0 || ($data['is_primary'] ?? false)) {
            $data['is_primary'] = true;
        } else {
            $data['is_primary'] = false;
        }

        try {
            $contact = EmergencyContact::create($data);

            if ($contact->is_primary) {
                EmergencyContact::where('user_id', $driver->id)
                    ->where('id', '!=', $contact->id)
                    ->update(['is_primary' => false]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Emergency contact added successfully',
                'data' => [
                    'contact' => [
                        'id' => $contact->id,
                        'name' => $contact->name,
                        'mobile_number' => $contact->mobile_number,
                        'formatted_mobile' => $contact->formatted_mobile,
                        'is_primary' => $contact->is_primary,
                        'created_at' => $contact->created_at,
                        'updated_at' => $contact->updated_at,
                    ],
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add emergency contact',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function update(Request $request, $id): JsonResponse
    {
        $driver = Auth::user();

        if ($driver->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Only drivers can access this endpoint.',
            ], 403);
        }

        $contact = EmergencyContact::byUser($driver->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'mobile_number' => 'sometimes|required|string|max:15|regex:/^[0-9+\-\s()]+$/',
            'is_primary' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();

            if (isset($data['is_primary']) && $data['is_primary']) {
                EmergencyContact::where('user_id', $driver->id)
                    ->where('id', '!=', $contact->id)
                    ->update(['is_primary' => false]);
            }

            $contact->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Emergency contact updated successfully',
                'data' => [
                    'contact' => [
                        'id' => $contact->id,
                        'name' => $contact->name,
                        'mobile_number' => $contact->mobile_number,
                        'formatted_mobile' => $contact->formatted_mobile,
                        'is_primary' => $contact->is_primary,
                        'created_at' => $contact->created_at,
                        'updated_at' => $contact->updated_at,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update emergency contact',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function destroy($id): JsonResponse
    {
        $driver = Auth::user();

        if ($driver->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Only drivers can access this endpoint.',
            ], 403);
        }

        $contact = EmergencyContact::byUser($driver->id)->findOrFail($id);

        try {
            $contact->delete();

            return response()->json([
                'success' => true,
                'message' => 'Emergency contact deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete emergency contact',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
