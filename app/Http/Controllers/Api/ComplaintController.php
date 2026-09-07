<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Services\ComplaintService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ComplaintController extends Controller
{
    protected ComplaintService $complaintService;

    public function __construct(ComplaintService $complaintService)
    {
        $this->complaintService = $complaintService;
    }

    
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
            'type' => ['required', 'string', 'in:driver_behavior,safety,overcharge,route,cleanliness,cancellation,refund,technical,payment,other'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'], // 10MB max per file
            'meta_data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $complaint = $this->complaintService->createComplaint(
                $request->user(),
                $validator->validated()
            );

            return response()->json([
                'message' => 'Complaint created successfully',
                'complaint' => $complaint->load(['messages', 'attachments']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create complaint',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function addMessage(Request $request, Complaint $complaint): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'], // 10MB max per file
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $message = $this->complaintService->addMessage(
                $complaint,
                $request->user(),
                $request->message,
                $request->attachments
            );

            return response()->json([
                'message' => 'Message added successfully',
                'complaint_message' => $message->load('attachments'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to add message',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    
    public function messages(Request $request, Complaint $complaint): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $messages = $complaint->messages()
            ->with(['user', 'attachments'])
            ->latest()
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'messages' => $messages,
        ]);
    }

    
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['nullable', 'string', 'in:pending,assigned,in_progress,resolved,closed,escalated'],
            'type' => ['nullable', 'string', 'in:driver_behavior,safety,overcharge,route,cleanliness,cancellation,refund,technical,payment,other'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = $request->user()
            ->complaints()
            ->with(['booking', 'agent'])
            ->latest();

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->type) {
            $query->where('type', $request->type);
        }

        if ($request->start_date) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->end_date) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $complaints = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'complaints' => $complaints,
        ]);
    }

    
    public function show(Complaint $complaint): JsonResponse
    {
        return response()->json([
            'complaint' => $complaint->load([
                'booking',
                'agent',
                'messages' => function ($query) {
                    $query->with(['user', 'attachments'])->latest();
                },
                'attachments',
            ]),
        ]);
    }

    
    public function updateStatus(Request $request, Complaint $complaint): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'in:assigned,in_progress,resolved,closed,escalated'],
            'note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $complaint = $this->complaintService->updateStatus(
                $complaint,
                \App\Enums\ComplaintStatus::from($request->status),
                $request->note
            );

            return response()->json([
                'message' => 'Complaint status updated successfully',
                'complaint' => $complaint->fresh(['messages', 'agent']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update complaint status',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    
    public function processRefund(Request $request, Complaint $complaint): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $complaint = $this->complaintService->processRefund(
                $complaint,
                $validator->validated()
            );

            return response()->json([
                'message' => 'Refund processed successfully',
                'complaint' => $complaint->fresh(['messages']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to process refund',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
