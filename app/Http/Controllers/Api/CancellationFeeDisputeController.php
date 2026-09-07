<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CancellationFeeDispute;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Facades\Auth;

class CancellationFeeDisputeController extends Controller
{


    public function getDisputeReasons(): JsonResponse
    {
        try {
            $reasons = CancellationFeeDispute::getDisputeReasons();

            return response()->json([
                'success' => true,
                'message' => 'Dispute reasons retrieved successfully',
                'data' => [
                    'reasons' => $reasons
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dispute reasons',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function submitDispute(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => ['required', 'exists:bookings,id'],
            'dispute_reason' => ['required', 'string', 'in:' . implode(',', [
                CancellationFeeDispute::REASON_PASSENGER_DIDNT_SHOW_UP,
                CancellationFeeDispute::REASON_INCORRECT_FEE_CHARGED,
                CancellationFeeDispute::REASON_ROUTE_BLOCKED_TRAFFIC,
                CancellationFeeDispute::REASON_WRONG_PICKUP_LOCATION,
                CancellationFeeDispute::REASON_NAVIGATION_APP_ERROR,
                CancellationFeeDispute::REASON_OTHER,
            ])],
            'custom_reason' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
            'screenshot' => ['nullable', 'file', 'mimes:jpeg,png,jpg,gif', 'max:5120'], // 5MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = Auth::user();
            $booking = Booking::findOrFail($request->booking_id);

            if ($booking->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only dispute cancellation fees for your own bookings',
                ], 403);
            }

            if ($booking->status !== 'cancelled' || !$booking->cancellation_charge) {
                return response()->json([
                    'success' => false,
                    'message' => 'This booking does not have a cancellation fee to dispute',
                ], 400);
            }

            $existingDispute = CancellationFeeDispute::where('booking_id', $booking->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existingDispute) {
                return response()->json([
                    'success' => false,
                    'message' => 'A dispute has already been submitted for this booking',
                    'data' => [
                        'dispute_id' => (string) $existingDispute->id,
                        'status' => (string) $existingDispute->status,
                        'submitted_at' => $existingDispute->created_at->toISOString(),
                    ]
                ], 409);
            }

            DB::beginTransaction();

            $screenshotPath = '';
            if ($request->hasFile('screenshot')) {
                $file = $request->file('screenshot');
                $screenshotPath = $file->store('dispute-screenshots', 'public');
            }

            $dispute = CancellationFeeDispute::create([
                'booking_id' => $booking->id,
                'user_id' => $user->id,
                'driver_id' => $booking->driver_id,
                'dispute_reason' => $request->dispute_reason,
                'custom_reason' => $request->custom_reason,
                'description' => $request->description,
                'screenshot_path' => $screenshotPath,
                'status' => CancellationFeeDispute::STATUS_PENDING,
                'meta_data' => [
                    'cancellation_charge' => $booking->cancellation_charge,
                    'total_amount' => $booking->total_amount,
                    'cancelled_at' => $booking->cancelled_at?->toISOString(),
                    'cancellation_reason' => $booking->cancellation_reason,
                ]
            ]);



            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cancellation fee dispute submitted successfully',
                'data' => [
                    'dispute_id' => (string) $dispute->id,
                    'booking_id' => (string) $booking->id,
                    'status' => (string) $dispute->status,
                    'dispute_reason' => (string) $dispute->dispute_reason,
                    'dispute_reason_label' => (string) $dispute->dispute_reason_label,
                    'custom_reason' => (string) ($dispute->custom_reason ?? ''),
                    'description' => (string) ($dispute->description ?? ''),
                    'screenshot_url' => (string) $dispute->screenshot_url,
                    'submitted_at' => $dispute->created_at->toISOString(),
                    'cancellation_charge' => (string) $booking->cancellation_charge,
                    'booking' => [
                        'id' => (string) $booking->id,
                        'status' => (string) $booking->status,
                        'cancelled_at' => $booking->cancelled_at ? $booking->cancelled_at->toISOString() : '',
                        'cancellation_reason' => (string) ($booking->cancellation_reason ?? ''),
                    ]
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit dispute',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getDisputeHistory(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $perPage = $request->get('per_page', 15);
            $status = $request->get('status');

            $query = CancellationFeeDispute::where('user_id', $user->id)
                ->with(['booking:id,booking_code,status,cancellation_charge,cancelled_at']);

            if ($status) {
                $query->where('status', $status);
            }

            $disputes = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            $formattedDisputes = $disputes->map(function ($dispute) {
                return [
                    'dispute_id' => (string) $dispute->id,
                    'booking_id' => (string) $dispute->booking_id,
                    'booking_code' => (string) ($dispute->booking->booking_code ?? ''),
                    'status' => (string) $dispute->status,
                    'status_label' => (string) $dispute->status_label,
                    'dispute_reason' => (string) $dispute->dispute_reason,
                    'dispute_reason_label' => (string) $dispute->dispute_reason_label,
                    'custom_reason' => (string) ($dispute->custom_reason ?? ''),
                    'description' => (string) ($dispute->description ?? ''),
                    'screenshot_url' => (string) $dispute->screenshot_url,
                    'admin_response' => (string) ($dispute->admin_response ?? ''),
                    'submitted_at' => $dispute->created_at->toISOString(),
                    'resolved_at' => $dispute->resolved_at ? $dispute->resolved_at->toISOString() : '',
                    'cancellation_charge' => (string) ($dispute->booking->cancellation_charge ?? ''),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Dispute history retrieved successfully',
                'data' => [
                    'disputes' => $formattedDisputes,
                    'pagination' => [
                        'current_page' => (string) $disputes->currentPage(),
                        'last_page' => (string) $disputes->lastPage(),
                        'per_page' => (string) $disputes->perPage(),
                        'total' => (string) $disputes->total(),
                        'has_more_pages' => $disputes->hasMorePages() ? '1' : '0',
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dispute history',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getDisputeDetails($disputeId): JsonResponse
    {
        try {
            $user = Auth::user();

            $dispute = CancellationFeeDispute::where('id', $disputeId)
                ->where('user_id', $user->id)
                ->with(['booking:id,booking_code,status,cancellation_charge,cancelled_at,cancellation_reason'])
                ->first();

            if (!$dispute) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dispute not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Dispute details retrieved successfully',
                'data' => [
                    'dispute_id' => (string) $dispute->id,
                    'booking_id' => (string) $dispute->booking_id,
                    'booking_code' => (string) ($dispute->booking->booking_code ?? ''),
                    'status' => (string) $dispute->status,
                    'status_label' => (string) $dispute->status_label,
                    'dispute_reason' => (string) $dispute->dispute_reason,
                    'dispute_reason_label' => (string) $dispute->dispute_reason_label,
                    'custom_reason' => (string) ($dispute->custom_reason ?? ''),
                    'description' => (string) ($dispute->description ?? ''),
                    'screenshot_url' => (string) $dispute->screenshot_url,
                    'admin_response' => (string) ($dispute->admin_response ?? ''),
                    'submitted_at' => $dispute->created_at->toISOString(),
                    'resolved_at' => $dispute->resolved_at ? $dispute->resolved_at->toISOString() : '',
                    'booking' => [
                        'id' => (string) $dispute->booking->id,
                        'booking_code' => (string) ($dispute->booking->booking_code ?? ''),
                        'status' => (string) $dispute->booking->status,
                        'cancellation_charge' => (string) ($dispute->booking->cancellation_charge ?? ''),
                        'cancelled_at' => $dispute->booking->cancelled_at ? $dispute->booking->cancelled_at->toISOString() : '',
                        'cancellation_reason' => (string) ($dispute->booking->cancellation_reason ?? ''),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dispute details',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function checkDisputeEligibility(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => ['required', 'exists:bookings,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = Auth::user();
            $booking = Booking::findOrFail($request->booking_id);

            if ($booking->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only check eligibility for your own bookings',
                ], 403);
            }

            $isEligible = false;
            $reason = '';
            $existingDispute = null;

            if ($booking->status === 'cancelled' && $booking->cancellation_charge > 0) {
                $existingDispute = CancellationFeeDispute::where('booking_id', $booking->id)
                    ->where('user_id', $user->id)
                    ->first();

                if (!$existingDispute) {
                    $isEligible = true;
                } else {
                    $reason = 'A dispute has already been submitted for this booking';
                }
            } else {
                $reason = 'This booking does not have a cancellation fee to dispute';
            }

            return response()->json([
                'success' => true,
                'message' => 'Eligibility check completed',
                'data' => [
                    'is_eligible' => $isEligible ? '1' : '0',
                    'reason' => (string) $reason,
                    'booking' => [
                        'id' => (string) $booking->id,
                        'status' => (string) $booking->status,
                        'cancellation_charge' => (string) ($booking->cancellation_charge ?? ''),
                        'cancelled_at' => $booking->cancelled_at ? $booking->cancelled_at->toISOString() : '',
                    ],
                    'existing_dispute' => $existingDispute ? [
                        'dispute_id' => (string) $existingDispute->id,
                        'status' => (string) $existingDispute->status,
                        'submitted_at' => $existingDispute->created_at->toISOString(),
                    ] : ''
                ]
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to check eligibility',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
