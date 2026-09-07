<?php

namespace App\Http\Controllers\Api;

use App\Events\SupportChatMessage;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\RefundRequest;
use App\Models\SupportChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use Illuminate\Validation\ValidationException;

class RefundController extends Controller
{

    public function submitRefundRequest(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'booking_id' => 'required|exists:bookings,id',
                'reason' => 'required|string|max:255',
                'description' => 'required|string|max:2000',
            ]);

            $user = Auth::user();
            $booking = Booking::findOrFail($validated['booking_id']);
            if ((int)$booking->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to request a refund for this booking.',
                ], 403);
            }

            $existingRequest = RefundRequest::where('booking_id', $booking->id)
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->first();

            if ($existingRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have a pending refund request for this booking.',
                    'refund_request' => $existingRequest,
                ], 422);
            }

            $requestedAmount = $booking->total_amount ?? 0;

            $refundRequest = RefundRequest::create([
                'booking_id' => $booking->id,
                'user_id' => $user->id,
                'reason' => $validated['reason'],
                'description' => $validated['description'],
                'requested_amount' => $requestedAmount,
                'status' => RefundRequest::STATUS_PENDING,
            ]);

            $supportChat = SupportChat::create([
                'user_id' => $user->id,
                'booking_id' => $booking->id,
                'sender_type' => 'user',
                'message' => "Refund Request: {$validated['reason']}\n\n{$validated['description']}",
                'message_type' => 'system',
                'subject' => 'Refund Request',
                'priority' => 'high',
                'status' => 'open',
                'metadata' => [
                    'refund_request_id' => $refundRequest->id,
                    'refund_reason' => $validated['reason'],
                    'requested_amount' => $requestedAmount,
                    'booking_code' => $booking->booking_code,
                ],
            ]);

            event(new SupportChatMessage($supportChat->load(['user', 'booking'])));

            

            return response()->json([
                'success' => true,
                'message' => 'Refund request submitted successfully',
                'refund_request' => [
                    'id' => $refundRequest->id,
                    'booking_id' => $refundRequest->booking_id,
                    'booking_code' => $booking->booking_code,
                    'reason' => $refundRequest->reason,
                    'description' => $refundRequest->description,
                    'requested_amount' => $refundRequest->requested_amount,
                    'status' => $refundRequest->status,
                    'created_at' => $refundRequest->created_at->toISOString(),
                ],
                'support_chat_id' => $supportChat->id,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit refund request. Please try again later.',
            ], 500);
        }
    }


    public function getMyRefundRequests(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            $refundRequests = RefundRequest::where('user_id', $user->id)
                ->with(['booking', 'processedBy'])
                ->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 15));

            return response()->json([
                'success' => true,
                'refund_requests' => $refundRequests->map(function ($request) {
                    return [
                        'id' => $request->id,
                        'booking_id' => $request->booking_id,
                        'booking_code' => $request->booking->booking_code ?? null,
                        'reason' => $request->reason,
                        'description' => $request->description,
                        'requested_amount' => $request->requested_amount,
                        'approved_amount' => $request->approved_amount,
                        'status' => $request->status,
                        'refund_source' => $request->refund_source,
                        'admin_notes' => $request->admin_notes,
                        'processed_by' => $request->processedBy ? [
                            'id' => $request->processedBy->id,
                            'name' => $request->processedBy->name,
                        ] : null,
                        'processed_at' => $request->processed_at ? $request->processed_at->toISOString() : null,
                        'created_at' => $request->created_at->toISOString(),
                        'updated_at' => $request->updated_at->toISOString(),
                    ];
                }),
                'pagination' => [
                    'current_page' => $refundRequests->currentPage(),
                    'last_page' => $refundRequests->lastPage(),
                    'per_page' => $refundRequests->perPage(),
                    'total' => $refundRequests->total(),
                ],
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch refund requests.',
            ], 500);
        }
    }


    public function getRefundRequestDetails($id): JsonResponse
    {
        try {
            $user = Auth::user();

            $refundRequest = RefundRequest::where('id', $id)
                ->where('user_id', $user->id)
                ->with(['booking', 'processedBy'])
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'refund_request' => [
                    'id' => $refundRequest->id,
                    'booking_id' => $refundRequest->booking_id,
                    'booking' => [
                        'id' => $refundRequest->booking->id,
                        'booking_code' => $refundRequest->booking->booking_code,
                        'total_amount' => $refundRequest->booking->total_amount,
                        'status' => $refundRequest->booking->status,
                    ],
                    'reason' => $refundRequest->reason,
                    'description' => $refundRequest->description,
                    'requested_amount' => $refundRequest->requested_amount,
                    'approved_amount' => $refundRequest->approved_amount,
                    'status' => $refundRequest->status,
                    'refund_source' => $refundRequest->refund_source,
                    'admin_notes' => $refundRequest->admin_notes,
                    'processed_by' => $refundRequest->processedBy ? [
                        'id' => $refundRequest->processedBy->id,
                        'name' => $refundRequest->processedBy->name,
                    ] : null,
                    'processed_at' => $refundRequest->processed_at ? $refundRequest->processed_at->toISOString() : null,
                    'created_at' => $refundRequest->created_at->toISOString(),
                    'updated_at' => $refundRequest->updated_at->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Refund request not found.',
            ], 404);
        }
    }


    public function getRefundReasons(): JsonResponse
    {
        $reasons = [
            'Ride not Good',
            'Driver was late',
            'Driver cancelled',
            'Payment issue',
            'Route issue',
            'Vehicle issue',
            'Driver behavior',
            'Safety concern',
            'Other',
        ];

        return response()->json([
            'success' => true,
            'reasons' => $reasons,
        ]);
    }


    public function getAllRefundRequests(Request $request): JsonResponse
    {
        try {
            $refundRequests = RefundRequest::with(['booking', 'user', 'processedBy'])
                ->orderBy('created_at', 'desc')
                ->when($request->status, function ($query, $status) {
                    return $query->where('status', $status);
                })
                ->when($request->user_id, function ($query, $userId) {
                    return $query->where('user_id', $userId);
                })
                ->when($request->booking_id, function ($query, $bookingId) {
                    return $query->where('booking_id', $bookingId);
                })
                ->paginate($request->input('per_page', 15));

            return response()->json([
                'success' => true,
                'refund_requests' => $refundRequests->map(function ($request) {
                    return [
                        'id' => $request->id,
                        'booking_id' => $request->booking_id,
                        'booking_code' => $request->booking->booking_code ?? null,
                        'user' => [
                            'id' => $request->user->id,
                            'name' => $request->user->name,
                            'phone' => $request->user->phone,
                        ],
                        'reason' => $request->reason,
                        'description' => $request->description,
                        'requested_amount' => $request->requested_amount,
                        'approved_amount' => $request->approved_amount,
                        'status' => $request->status,
                        'refund_source' => $request->refund_source,
                        'admin_notes' => $request->admin_notes,
                        'processed_by' => $request->processedBy ? [
                            'id' => $request->processedBy->id,
                            'name' => $request->processedBy->name,
                        ] : null,
                        'processed_at' => $request->processed_at ? $request->processed_at->toISOString() : null,
                        'created_at' => $request->created_at->toISOString(),
                        'updated_at' => $request->updated_at->toISOString(),
                    ];
                }),
                'pagination' => [
                    'current_page' => $refundRequests->currentPage(),
                    'last_page' => $refundRequests->lastPage(),
                    'per_page' => $refundRequests->perPage(),
                    'total' => $refundRequests->total(),
                ],
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch refund requests.',
            ], 500);
        }
    }


    public function getAdminRefundRequestDetails($id): JsonResponse
    {
        try {
            $refundRequest = RefundRequest::with(['booking', 'user', 'processedBy'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'refund_request' => [
                    'id' => $refundRequest->id,
                    'booking_id' => $refundRequest->booking_id,
                    'booking' => [
                        'id' => $refundRequest->booking->id,
                        'booking_code' => $refundRequest->booking->booking_code,
                        'total_amount' => $refundRequest->booking->total_amount,
                        'status' => $refundRequest->booking->status,
                        'payment_method' => $refundRequest->booking->payment_method,
                        'payment_status' => $refundRequest->booking->payment_status,
                    ],
                    'user' => [
                        'id' => $refundRequest->user->id,
                        'name' => $refundRequest->user->name,
                        'phone' => $refundRequest->user->phone,
                        'email' => $refundRequest->user->email,
                    ],
                    'reason' => $refundRequest->reason,
                    'description' => $refundRequest->description,
                    'requested_amount' => $refundRequest->requested_amount,
                    'approved_amount' => $refundRequest->approved_amount,
                    'status' => $refundRequest->status,
                    'refund_source' => $refundRequest->refund_source,
                    'admin_notes' => $refundRequest->admin_notes,
                    'processed_by' => $refundRequest->processedBy ? [
                        'id' => $refundRequest->processedBy->id,
                        'name' => $refundRequest->processedBy->name,
                    ] : null,
                    'processed_at' => $refundRequest->processed_at ? $refundRequest->processed_at->toISOString() : null,
                    'created_at' => $refundRequest->created_at->toISOString(),
                    'updated_at' => $refundRequest->updated_at->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Refund request not found.',
            ], 404);
        }
    }


    public function approveRefundRequest(Request $request, $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'approved_amount' => 'required|numeric|min:0',
                'refund_source' => 'required|in:' . RefundRequest::SOURCE_ADMIN_ACCOUNT . ',' . RefundRequest::SOURCE_DRIVER_WALLET,
                'admin_notes' => 'nullable|string|max:1000',
            ]);

            $refundRequest = RefundRequest::with(['booking', 'user'])->findOrFail($id);

            if (!$refundRequest->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This refund request has already been processed.',
                ], 422);
            }

            $admin = Auth::user();
            $approvedAmount = $validated['approved_amount'];
            $requestedAmount = $refundRequest->requested_amount;

            if ($approvedAmount >= $requestedAmount) {
                $status = RefundRequest::STATUS_APPROVED;
                $approvedAmount = $requestedAmount; // Don't approve more than requested
            } else {
                $status = RefundRequest::STATUS_PARTIALLY_APPROVED;
            }

            $refundRequest->refund_source = $validated['refund_source'];
            $refundRequest->approved_amount = $approvedAmount;

            $refundRequest->update([
                'status' => $status,
                'approved_amount' => $approvedAmount,
                'refund_source' => $validated['refund_source'],
                'admin_notes' => $validated['admin_notes'] ?? null,
                'processed_by' => $admin->id,
                'processed_at' => now(),
            ]);

            try {
                $refundRequest->processRefund();
            } catch (\Exception $e) {
                $refundRequest->update([
                    'status' => RefundRequest::STATUS_PENDING,
                    'processed_by' => null,
                    'processed_at' => null,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process refund: ' . $e->getMessage() . '. Refund status has been reverted to pending.',
                ], 500);
            }

            $supportChat = SupportChat::create([
                'user_id' => $refundRequest->user_id,
                'booking_id' => $refundRequest->booking_id,
                'admin_id' => $admin->id,
                'sender_type' => 'admin',
                'message' => "Your refund request has been {$status}. Approved amount: $" . number_format($approvedAmount, 2) . ($validated['admin_notes'] ? "\n\nNotes: {$validated['admin_notes']}" : ''),
                'message_type' => 'system',
                'subject' => 'Refund Request ' . ucfirst(str_replace('_', ' ', $status)),
                'priority' => 'high',
                'status' => 'open',
                'metadata' => [
                    'refund_request_id' => $refundRequest->id,
                    'refund_status' => $status,
                    'approved_amount' => $approvedAmount,
                ],
            ]);

            event(new SupportChatMessage($supportChat->load(['user', 'admin'])));

            

            return response()->json([
                'success' => true,
                'message' => 'Refund request approved successfully',
                'refund_request' => [
                    'id' => $refundRequest->id,
                    'status' => $refundRequest->status,
                    'approved_amount' => $refundRequest->approved_amount,
                    'processed_at' => $refundRequest->processed_at->toISOString(),
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve refund request. Please try again later.',
            ], 500);
        }
    }


    public function rejectRefundRequest(Request $request, $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'admin_notes' => 'required|string|max:1000',
            ]);

            $refundRequest = RefundRequest::with(['booking', 'user'])->findOrFail($id);

            if (!$refundRequest->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This refund request has already been processed.',
                ], 422);
            }

            $admin = Auth::user();

            $refundRequest->update([
                'status' => RefundRequest::STATUS_REJECTED,
                'admin_notes' => $validated['admin_notes'],
                'processed_by' => $admin->id,
                'processed_at' => now(),
            ]);

            $supportChat = SupportChat::create([
                'user_id' => $refundRequest->user_id,
                'booking_id' => $refundRequest->booking_id,
                'admin_id' => $admin->id,
                'sender_type' => 'admin',
                'message' => "Your refund request has been rejected.\n\nReason: {$validated['admin_notes']}",
                'message_type' => 'system',
                'subject' => 'Refund Request Rejected',
                'priority' => 'high',
                'status' => 'open',
                'metadata' => [
                    'refund_request_id' => $refundRequest->id,
                    'refund_status' => RefundRequest::STATUS_REJECTED,
                ],
            ]);

            event(new SupportChatMessage($supportChat->load(['user', 'admin'])));

            

            return response()->json([
                'success' => true,
                'message' => 'Refund request rejected successfully',
                'refund_request' => [
                    'id' => $refundRequest->id,
                    'status' => $refundRequest->status,
                    'processed_at' => $refundRequest->processed_at->toISOString(),
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject refund request. Please try again later.',
            ], 500);
        }
    }
}
