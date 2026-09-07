<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingContact;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BookingContactController extends Controller
{
    
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $bookingId = $request->query('booking_id');
        $selectedContactId = null;

        if ($bookingId) {
            $booking = Booking::where('id', $bookingId)
                ->where('user_id', $user->id)
                ->first();

            if ($booking) {
                $selectedContactId = $booking->booking_contact_id;
                if ($selectedContactId === null || $selectedContactId === 0) {
                    $selectedContactId = 0;
                }
            }
        }

        $contacts = BookingContact::byUser($user->id)
            ->orderBy('is_primary', 'desc')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($contact) use ($selectedContactId) {
                return [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'mobile_number' => $contact->mobile_number,
                    'profile_pic' => $this->getProfilePicUrl($contact->profile_pic),
                    'is_primary' => $contact->is_primary,
                    'is_selected' => $selectedContactId !== null && $selectedContactId == $contact->id,
                    'created_at' => $contact->created_at,
                    'updated_at' => $contact->updated_at,
                ];
            });

        $myselfOption = [
            'id' => 0,
            'name' => 'MySelf',
            'mobile_number' => $user->phone ?? '',
            'is_primary' => false,
            'is_myself' => true,
            'is_selected' => $selectedContactId === null || $selectedContactId === 0,
        ];

        $privacyMessage = 'Contact name Won\'t be shared with captain';

        return response()->json([
            'success' => true,
            'message' => 'Booking contacts retrieved successfully',
            'data' => [
                'contacts' => array_merge([$myselfOption], $contacts->toArray()),
                'total_count' => $contacts->count() + 1,
                'selected_contact_id' => $selectedContactId,
                'privacy_message' => $privacyMessage,
            ],
        ]);
    }

    
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        $existingContactsCount = BookingContact::byUser($user->id)->count();
        if ($existingContactsCount >= 10) {
            return response()->json([
                'success' => false,
                'message' => 'Maximum limit of 10 contacts reached',
                'error' => 'CONTACT_LIMIT_EXCEEDED',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'mobile_number' => 'nullable|string|max:15|regex:/^[0-9+\-\s()]+$/',
            'profile_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // 2MB max
            'is_primary' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => implode(' ', $validator->errors()->all()), // all messages in one line
            ], 422);
        }


        $data = $validator->validated();
        $data['user_id'] = $user->id;

        if ($request->hasFile('profile_pic')) {
            $path = $request->file('profile_pic')->store('booking-contacts/profile-pics', 'public');
            $data['profile_pic'] = $path;
        } else {
            unset($data['profile_pic']);
        }

        if ($data['is_primary'] ?? false) {
            $data['is_primary'] = true;
        } else {
            $data['is_primary'] = false;
        }

        try {
            $contact = BookingContact::create($data);

            if ($contact->is_primary) {
                BookingContact::where('user_id', $user->id)
                    ->where('id', '!=', $contact->id)
                    ->update(['is_primary' => false]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Contact added successfully',
                'data' => [
                    'contact' => [
                        'id' => $contact->id,
                        'name' => $contact->name,
                        'mobile_number' => $contact->mobile_number,
                        'profile_pic' => $this->getProfilePicUrl($contact->profile_pic),
                        'is_primary' => $contact->is_primary,
                        'created_at' => $contact->created_at,
                        'updated_at' => $contact->updated_at,
                    ],
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add contact',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function update(Request $request, $id): JsonResponse
    {
        $user = Auth::user();
        $contact = BookingContact::byUser($user->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'mobile_number' => 'sometimes|nullable|string|max:15|regex:/^[0-9+\-\s()]+$/',
            'profile_pic' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // 2MB max
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

            if ($request->hasFile('profile_pic')) {
                if ($contact->profile_pic) {
                    Storage::disk('public')->delete($contact->profile_pic);
                }
                $path = $request->file('profile_pic')->store('booking-contacts/profile-pics', 'public');
                $data['profile_pic'] = $path;
            } else {
                unset($data['profile_pic']);
            }

            if (isset($data['is_primary']) && $data['is_primary']) {
                BookingContact::where('user_id', $user->id)
                    ->where('id', '!=', $contact->id)
                    ->update(['is_primary' => false]);
            }

            $contact->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Contact updated successfully',
                'data' => [
                    'contact' => [
                        'id' => $contact->id,
                        'name' => $contact->name,
                        'mobile_number' => $contact->mobile_number,
                        'profile_pic' => $this->getProfilePicUrl($contact->profile_pic),
                        'is_primary' => $contact->is_primary,
                        'created_at' => $contact->created_at,
                        'updated_at' => $contact->updated_at,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update contact',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function destroy($id): JsonResponse
    {
        $user = Auth::user();
        $contact = BookingContact::byUser($user->id)->findOrFail($id);

        try {
            $wasPrimary = $contact->is_primary;
            $contact->delete();

            if ($wasPrimary) {
                $nextContact = BookingContact::byUser($user->id)->first();
                if ($nextContact) {
                    $nextContact->update(['is_primary' => true]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Contact deleted successfully',
                'data' => [
                    'deleted_contact_id' => $id,
                    'was_primary' => $wasPrimary,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete contact',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function setPrimary($id): JsonResponse
    {
        $user = Auth::user();
        $contact = BookingContact::byUser($user->id)->findOrFail($id);

        try {
            $contact->setAsPrimary();

            return response()->json([
                'success' => true,
                'message' => 'Primary contact updated successfully',
                'data' => [
                    'contact' => [
                        'id' => $contact->id,
                        'name' => $contact->name,
                        'mobile_number' => $contact->mobile_number,
                        'profile_pic' => $this->getProfilePicUrl($contact->profile_pic),
                        'is_primary' => $contact->is_primary,
                        'created_at' => $contact->created_at,
                        'updated_at' => $contact->updated_at,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to set primary contact',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    protected function getProfilePicUrl(?string $profilePic): ?string
    {
        if (empty($profilePic)) {
            return null;
        }

        if (filter_var($profilePic, FILTER_VALIDATE_URL)) {
            return $profilePic;
        }

        return url('storage/' . $profilePic);
    }
}
