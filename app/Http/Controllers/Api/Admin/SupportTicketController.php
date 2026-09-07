<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportActivity;
use App\Models\SupportTicket;
use App\Models\WalletTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SupportTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::query()
            ->with(['user', 'booking', 'assignedTo'])
            ->withCount(['messages', 'attachments']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($priority = $request->input('priority')) {
            $query->where('priority', $priority);
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($assignedTo = $request->input('assigned_to')) {
            $query->where('assigned_to', $assignedTo);
        }

        if ($request->boolean('unassigned')) {
            $query->whereNull('assigned_to');
        }

        if ($request->boolean('needs_attention')) {
            $query->needsAttention();
        }

        if ($search = $request->input('search')) {
            $query->where(function ($query) use ($search) {
                $query->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        $sortField = $request->input('sort_field', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $tickets = $query->paginate($request->input('per_page', 10));

        return response()->json($tickets);
    }

    public function timelineByBooking(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_id' => ['required', 'exists:bookings,id'],
        ]);

        $tickets = SupportTicket::query()
            ->where('booking_id', $data['booking_id'])
            ->with([
                'user:id,name,email,phone',
                'assignedTo:id,name,email,phone',
                'activities' => function ($query) {
                    $query->with(['user:id,name,email,phone'])->orderBy('created_at');
                },
                'messages' => function ($query) {
                    $query->with([
                        'user:id,name,email,phone',
                        'attachments',
                    ])->orderBy('created_at');
                },
            ])
            ->orderBy('created_at')
            ->get();

        if ($tickets->isEmpty()) {
            return response()->json([
                'booking_id' => $data['booking_id'],
                'timeline' => [],
                'tickets' => [],
            ]);
        }

        $messageMap = [];
        foreach ($tickets as $ticket) {
            foreach ($ticket->messages as $message) {
                $messageMap[$message->id] = $message;
            }
        }

        $timeline = [];
        foreach ($tickets as $ticket) {
            foreach ($ticket->activities as $activity) {
                $createdAt = $activity->created_at;

                $entry = [
                    'ticket_id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'type' => $activity->type,
                    'description' => $activity->description ?? '',
                    'created_at' => $createdAt ? $createdAt->toISOString() : '',
                    'created_at_human' => $createdAt ? $createdAt->diffForHumans() : '',
                    'actor' => $activity->user ? [
                        'id' => $activity->user->id,
                        'name' => $activity->user->name,
                        'email' => $activity->user->email ?? '',
                        'phone' => $activity->user->phone ?? '',
                    ] : null,
                ];

                if ($activity->type === SupportActivity::TYPE_MESSAGE) {
                    $messageId = $activity->meta_data['message_id'] ?? null;
                    $message = $messageId ? ($messageMap[$messageId] ?? null) : null;

                    if ($message) {
                        $entry['message'] = [
                            'id' => $message->id,
                            'text' => $message->message ?? '',
                            'is_internal' => (bool) $message->is_internal,
                            'sender' => $message->user ? [
                                'id' => $message->user->id,
                                'name' => $message->user->name,
                                'email' => $message->user->email ?? '',
                                'phone' => $message->user->phone ?? '',
                            ] : null,
                            'receiver' => $message->user_id === $ticket->user_id
                                ? [
                                    'type' => 'support_team',
                                    'name' => $ticket->assignedTo->name ?? 'Support Team',
                                ]
                                : [
                                    'type' => 'ticket_owner',
                                    'name' => $ticket->user->name ?? '',
                                ],
                            'attachments' => $message->attachments->map(function ($attachment) {
                                $filePath = $attachment->file_path ?? '';

                                return [
                                    'id' => $attachment->id,
                                    'name' => $attachment->name ?? '',
                                    'file_name' => $attachment->file_name ?? '',
                                    'file_path' => $filePath,
                                    'url' => $filePath !== ''
                                        ? Storage::disk('public')->url($filePath)
                                        : '',
                                ];
                            })->values()->all(),
                        ];
                    }
                }

                $timeline[] = $entry;
            }
        }

        usort($timeline, function ($a, $b) {
            $aTime = $a['created_at'] ?? '';
            $bTime = $b['created_at'] ?? '';
            if ($aTime === $bTime) {
                return 0;
            }
            if ($aTime === '') {
                return -1;
            }
            if ($bTime === '') {
                return 1;
            }
            return strcmp($aTime, $bTime);
        });

        return response()->json([
            'booking_id' => $data['booking_id'],
            'timeline' => array_values($timeline),
            'tickets' => $tickets->map(function ($ticket) {
                return [
                    'id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'subject' => $ticket->subject ?? '',
                    'status' => $ticket->status ?? '',
                    'priority' => $ticket->priority ?? '',
                ];
            })->values()->all(),
        ]);
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        $ticket->load([
            'user',
            'booking',
            'assignedTo',
            'messages' => function ($query) {
                $query->with(['user', 'attachments'])->latest();
            },
            'activities' => function ($query) {
                $query->with('user')->latest();
            },
        ]);

        return response()->json($ticket);
    }

    public function update(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'priority' => ['nullable', 'string', 'in:' . implode(',', [
                SupportTicket::PRIORITY_LOW,
                SupportTicket::PRIORITY_MEDIUM,
                SupportTicket::PRIORITY_HIGH,
                SupportTicket::PRIORITY_URGENT,
            ])],
            'status' => ['nullable', 'string', 'in:' . implode(',', [
                SupportTicket::STATUS_OPEN,
                SupportTicket::STATUS_IN_PROGRESS,
                SupportTicket::STATUS_WAITING,
                SupportTicket::STATUS_RESOLVED,
                SupportTicket::STATUS_CLOSED,
            ])],
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);

        try {
            DB::beginTransaction();

            if (isset($data['priority']) && $data['priority'] !== $ticket->priority) {
                $oldPriority = $ticket->priority;
                $ticket->update(['priority' => $data['priority']]);

                $ticket->activities()->create([
                    'type' => SupportActivity::TYPE_PRIORITY,
                    'description' => "Priority changed from {$oldPriority} to {$data['priority']}",
                    'meta_data' => [
                        'old_priority' => $oldPriority,
                        'new_priority' => $data['priority'],
                    ],
                ]);
            }

            if (isset($data['status']) && $data['status'] !== $ticket->status) {
                $oldStatus = $ticket->status;
                $ticket->update(['status' => $data['status']]);

                $ticket->activities()->create([
                    'type' => SupportActivity::TYPE_STATUS,
                    'description' => "Status changed from {$oldStatus} to {$data['status']}",
                    'meta_data' => [
                        'old_status' => $oldStatus,
                        'new_status' => $data['status'],
                    ],
                ]);
            }

            if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== $ticket->assigned_to) {
                $oldAssignee = $ticket->assigned_to;
                $newAssignee = $data['assigned_to'] ? User::find($data['assigned_to']) : null;

                $ticket->update(['assigned_to' => $data['assigned_to']]);

                $ticket->activities()->create([
                    'type' => SupportActivity::TYPE_ASSIGNED,
                    'description' => $newAssignee
                        ? "Ticket assigned to {$newAssignee->name}"
                        : "Ticket unassigned",
                    'meta_data' => [
                        'old_assignee' => $oldAssignee,
                        'new_assignee' => $data['assigned_to'],
                    ],
                ]);
            }

            DB::commit();

            $ticket->load(['assignedTo', 'activities']);

            return response()->json([
                'message' => 'Ticket updated successfully',
                'ticket' => $ticket,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        if (!$ticket->isOpen()) {
            throw ValidationException::withMessages([
                'ticket' => ['This ticket is closed and cannot be replied to.'],
            ]);
        }

        $data = $request->validate([
            'message' => ['required', 'string'],
            'is_internal' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['required', 'file', 'max:10240'], // 10MB max
        ]);

        try {
            DB::beginTransaction();

            $message = $ticket->messages()->create([
                'user_id' => auth()->id(),
                'message' => $data['message'],
                'is_internal' => $data['is_internal'] ?? false,
            ]);

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store("support/{$ticket->id}", 'public');

                    $ticket->attachments()->create([
                        'message_id' => $message->id,
                        'user_id' => auth()->id(),
                        'name' => $file->getClientOriginalName(),
                        'file_name' => basename($path),
                        'file_path' => $path,
                        'file_size' => $file->getSize(),
                        'file_type' => $file->getMimeType(),
                        'is_internal' => $data['is_internal'] ?? false,
                    ]);
                }
            }

            if (!($data['is_internal'] ?? false)) {
                $ticket->update(['status' => SupportTicket::STATUS_IN_PROGRESS]);
            }

            DB::commit();

            $message->load(['user', 'attachments']);

            return response()->json([
                'message' => ($data['is_internal'] ?? false) ? 'Internal note added successfully' : 'Reply sent successfully',
                'reply' => $message,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function resolve(Request $request, SupportTicket $ticket): JsonResponse
    {
        if ($ticket->isResolved()) {
            throw ValidationException::withMessages([
                'ticket' => ['This ticket is already resolved.'],
            ]);
        }

        $data = $request->validate([
            'note' => ['required', 'string'],
        ]);

        $ticket->resolve($data['note']);

        return response()->json([
            'message' => 'Ticket resolved successfully',
            'ticket' => $ticket->fresh(['activities']),
        ]);
    }

    public function close(Request $request, SupportTicket $ticket): JsonResponse
    {
        if ($ticket->isClosed()) {
            throw ValidationException::withMessages([
                'ticket' => ['This ticket is already closed.'],
            ]);
        }

        $data = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $ticket->close($data['note'] ?? null);

        return response()->json([
            'message' => 'Ticket closed successfully',
            'ticket' => $ticket->fresh(['activities']),
        ]);
    }

    public function reopen(Request $request, SupportTicket $ticket): JsonResponse
    {
        if (!$ticket->isClosed()) {
            throw ValidationException::withMessages([
                'ticket' => ['This ticket is not closed.'],
            ]);
        }

        $data = $request->validate([
            'reason' => ['required', 'string'],
        ]);

        $ticket->reopen($data['reason']);

        return response()->json([
            'message' => 'Ticket reopened successfully',
            'ticket' => $ticket->fresh(['activities']),
        ]);
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticket_ids' => ['required', 'array'],
            'ticket_ids.*' => ['required', 'exists:support_tickets,id'],
            'assigned_to' => ['required', 'exists:users,id'],
        ]);

        $assignee = User::find($data['assigned_to']);

        DB::transaction(function () use ($data, $assignee) {
            foreach ($data['ticket_ids'] as $ticketId) {
                $ticket = SupportTicket::find($ticketId);
                if ($ticket && $ticket->assigned_to !== $assignee->id) {
                    $ticket->assign($assignee);
                }
            }
        });

        return response()->json([
            'message' => count($data['ticket_ids']) . ' tickets assigned successfully',
        ]);
    }

    public function bulkClose(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticket_ids' => ['required', 'array'],
            'ticket_ids.*' => ['required', 'exists:support_tickets,id'],
            'note' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['ticket_ids'] as $ticketId) {
                $ticket = SupportTicket::find($ticketId);
                if ($ticket && !$ticket->isClosed()) {
                    $ticket->close($data['note'] ?? null);
                }
            }
        });

        return response()->json([
            'message' => count($data['ticket_ids']) . ' tickets closed successfully',
        ]);
    }

    public function stats(): JsonResponse
    {
        $stats = [
            'total' => SupportTicket::count(),
            'open' => SupportTicket::whereNull('closed_at')->count(),
            'resolved' => SupportTicket::whereNotNull('resolved_at')->count(),
            'closed' => SupportTicket::whereNotNull('closed_at')->count(),
            'unassigned' => SupportTicket::whereNull('assigned_to')->count(),
            'needs_attention' => SupportTicket::needsAttention()->count(),
            'by_priority' => [
                'low' => SupportTicket::where('priority', SupportTicket::PRIORITY_LOW)->count(),
                'medium' => SupportTicket::where('priority', SupportTicket::PRIORITY_MEDIUM)->count(),
                'high' => SupportTicket::where('priority', SupportTicket::PRIORITY_HIGH)->count(),
                'urgent' => SupportTicket::where('priority', SupportTicket::PRIORITY_URGENT)->count(),
            ],
            'by_category' => [
                'booking' => SupportTicket::where('category', SupportTicket::CATEGORY_BOOKING)->count(),
                'payment' => SupportTicket::where('category', SupportTicket::CATEGORY_PAYMENT)->count(),
                'driver' => SupportTicket::where('category', SupportTicket::CATEGORY_DRIVER)->count(),
                'account' => SupportTicket::where('category', SupportTicket::CATEGORY_ACCOUNT)->count(),
                'technical' => SupportTicket::where('category', SupportTicket::CATEGORY_TECHNICAL)->count(),
                'other' => SupportTicket::where('category', SupportTicket::CATEGORY_OTHER)->count(),
            ],
        ];

        return response()->json($stats);
    }

    
    public function approvePenaltyRefund(SupportTicket $ticket): JsonResponse
    {
        if (!$ticket->booking_id || !$ticket->user_id) {
            return response()->json([
                'message' => 'Ticket must be linked to a booking and driver.'
            ], 422);
        }

        $driver = $ticket->user;

        return DB::transaction(function () use ($ticket, $driver) {
            $penalty = WalletTransaction::whereHas('wallet', function ($q) use ($driver) {
                $q->where('user_id', $driver->id);
            })
                ->where('amount', '<', 0)
                ->where(function ($q) use ($ticket) {
                    $q->where('description', 'like', '%Penalty for Late Arrival%');
                })
                ->where('meta_data->booking_id', $ticket->booking_id)
                ->latest()
                ->first();

            if (!$penalty) {
                return response()->json([
                    'message' => 'No penalty transaction found for this booking.'
                ], 404);
            }

            $wallet = $driver->wallet ?? $driver->wallet()->create(['balance' => 0]);
            $refundAmount = abs((float) $penalty->amount);

            $creditTx = $wallet->credit(
                $refundAmount,
                WalletTransaction::TYPE_ADJUSTMENT,
                'Refund: Late Arrival Penalty (Ticket ' . ($ticket->ticket_number ?? $ticket->id) . ')',
                [
                    'ticket_id' => $ticket->id,
                    'booking_id' => $ticket->booking_id,
                    'original_transaction_id' => $penalty->id,
                    'refunded_at' => now()->toDateTimeString(),
                    'late_penalty_refund_approved' => true,
                ]
            );

            $penalty->update([
                'meta_data' => array_merge($penalty->meta_data ?? [], [
                    'late_penalty_refund_approved' => true,
                    'refund_transaction_id' => $creditTx->id,
                    'refund_ticket_id' => $ticket->id,
                    'refund_ticket_number' => $ticket->ticket_number,
                ]),
            ]);

            if (!$ticket->isResolved()) {
                $ticket->resolve('Penalty refund approved and credited to driver wallet.');
            }

            return response()->json([
                'message' => 'Penalty refund approved and credited successfully.',
                'refund_transaction_id' => $creditTx->id,
            ]);
        });
    }

    
    public function rejectPenaltyRefund(SupportTicket $ticket): JsonResponse
    {
        if (!$ticket->isResolved()) {
            $ticket->resolve('Penalty refund request rejected.');
        }

        if ($ticket->booking_id && $ticket->user_id) {
            $driver = $ticket->user;
            $penalty = WalletTransaction::whereHas('wallet', function ($q) use ($driver) {
                $q->where('user_id', $driver->id);
            })
                ->where('amount', '<', 0)
                ->where(function ($q) {
                    $q->where('description', 'like', '%Penalty for Late Arrival%');
                })
                ->where('meta_data->booking_id', $ticket->booking_id)
                ->latest()
                ->first();

            if ($penalty) {
                $penalty->update([
                    'meta_data' => array_merge($penalty->meta_data ?? [], [
                        'late_penalty_refund_approved' => false,
                        'refund_rejected_at' => now()->toDateTimeString(),
                        'refund_ticket_id' => $ticket->id,
                        'refund_ticket_number' => $ticket->ticket_number,
                    ]),
                ]);
            }
        }

        return response()->json([
            'message' => 'Penalty refund rejected. No wallet changes made.'
        ]);
    }
}
