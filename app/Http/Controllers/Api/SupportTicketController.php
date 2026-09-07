<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportAttachment;
use App\Models\SupportChat;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SupportTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tickets = SupportTicket::where('user_id', auth()->id())
            ->with(['messages' => function ($query) {
                $query->public()->latest()->limit(1);
            }])
            ->latest()
            ->paginate($request->input('per_page', 10));

        $collection = $tickets->getCollection()->map(function (SupportTicket $ticket) {
            return $this->serializeTicketForList($ticket);
        });
        $tickets->setCollection($collection);

        return response()->json($tickets);
    }

    private function serializeTicketForList(SupportTicket $ticket): array
    {
        $latestMessage = optional($ticket->messages->first());

        return [
            'id' => (int) $ticket->getKey(),
            'ticket_number' => $ticket->ticket_number ?? '',
            'user_id' => $ticket->user_id ?? '',
            'booking_id' => $ticket->booking_id ?? '',
            'category' => $ticket->category ?? '',
            'subject' => $ticket->subject ?? '',
            'priority' => $ticket->priority ?? '',
            'status' => $ticket->status ?? '',
            'assigned_to' => $ticket->assigned_to ?? '',
            'last_reply_at' => $ticket->last_reply_at ? $ticket->last_reply_at->toISOString() : '',
            'resolved_at' => $ticket->resolved_at ? $ticket->resolved_at->toISOString() : '',
            'closed_at' => $ticket->closed_at ? $ticket->closed_at->toISOString() : '',
            'created_at' => $ticket->created_at ? $ticket->created_at->toISOString() : '',
            'updated_at' => $ticket->updated_at ? $ticket->updated_at->toISOString() : '',
            'latest_message' => $latestMessage->exists() ? [
                'id' => (int) $latestMessage->getKey(),
                'user_id' => $latestMessage->user_id ?? '',
                'message' => $latestMessage->message ?? '',
                'created_at' => $latestMessage->created_at ? $latestMessage->created_at->toISOString() : '',
            ] : '',
        ];
    }

    private function serializeMessage(SupportMessage $message): array
    {
        return [
            'id' => (string) $message->getKey(),
            'ticket_id' => $message->ticket_id !== null ? (string) $message->ticket_id : '',
            'user_id' => $message->user_id !== null ? (string) $message->user_id : '',
            'message' => $message->message ? strip_tags($message->message) : '',
            'read_at' => $message->read_at ? $message->read_at->toISOString() : '',
            'created_at' => $message->created_at ? $message->created_at->toISOString() : '',
            'updated_at' => $message->updated_at ? $message->updated_at->toISOString() : '',
            'attachments' => $message->attachments->map(function (SupportAttachment $attachment) {
                return $this->serializeAttachment($attachment);
            })->values()->all(),
        ];
    }

    private function serializeAttachment(SupportAttachment $attachment): array
    {
        $filePath = $attachment->file_path ?? '';

        return [
            'id' => (string) $attachment->getKey(),
            'ticket_id' => $attachment->ticket_id !== null ? (string) $attachment->ticket_id : '',
            'message_id' => $attachment->message_id !== null ? (string) $attachment->message_id : '',
            'user_id' => $attachment->user_id !== null ? (string) $attachment->user_id : '',
            'name' => $attachment->name ?? '',
            'file_name' => $attachment->file_name ?? '',
            'file_path' => $filePath,
            'file_size' => $attachment->file_size !== null ? (string) $attachment->file_size : '',
            'file_type' => $attachment->file_type ?? '',
            'url' => $filePath !== '' ? Storage::disk('public')->url($filePath) : '',
            'created_at' => $attachment->created_at ? $attachment->created_at->toISOString() : '',
            'updated_at' => $attachment->updated_at ? $attachment->updated_at->toISOString() : '',
        ];
    }

    private function formatPaginator(LengthAwarePaginator $paginator): array
    {
        $array = $paginator->toArray();

        $links = array_map(function (array $link) {
            return [
                'url' => $link['url'] ?? '',
                'label' => isset($link['label']) ? (string) $link['label'] : '',
                'active' => !empty($link['active']) ? '1' : '0',
            ];
        }, $array['links'] ?? []);

        $authedUserId = (string) auth()->id();

        $data = array_map(function (array $item) use ($authedUserId) {
            $itemUserId = isset($item['user_id']) ? (string) $item['user_id'] : null;
            $role = $itemUserId === $authedUserId ? 'sender' : 'receiver';

            $item['role'] = $role;

            return $item;
        }, $array['data'] ?? []);

        return [
            'success' => true,
            'data' => $data,
            'from' => isset($array['from']) && $array['from'] !== null ? (string) $array['from'] : '',
            'last_page' => isset($array['last_page']) ? (string) $array['last_page'] : '',
            'per_page' => isset($array['per_page']) ? (string) $array['per_page'] : '',
            'current_page' => isset($array['current_page']) ? (string) $array['current_page'] : '',
            'last_page' => isset($array['last_page']) ? (string) $array['last_page'] : '',
            'to' => isset($array['to']) && $array['to'] !== null ? (string) $array['to'] : '',
            'total' => isset($array['total']) ? (string) $array['total'] : '',
        ];
    }

    private function replaceNulls($value)
    {
        if (is_array($value)) {
            return array_map(function ($item) {
                return $this->replaceNulls($item);
            }, $value);
        }

        if (is_null($value)) {
            return '';
        }

        return $value;
    }

    private function addUrlsToAttachments(array $payload): array
    {
        if (isset($payload['attachments']) && is_array($payload['attachments'])) {
            $payload['attachments'] = array_map(function ($att) {
                $att['url'] = isset($att['file_path']) && $att['file_path'] !== ''
                    ? Storage::disk('public')->url($att['file_path'])
                    : '';
                return $att;
            }, $payload['attachments']);
        }

        if (isset($payload['messages']) && is_array($payload['messages'])) {
            $payload['messages'] = array_map(function ($msg) {
                if (isset($msg['attachments']) && is_array($msg['attachments'])) {
                    $msg['attachments'] = array_map(function ($att) {
                        $att['url'] = isset($att['file_path']) && $att['file_path'] !== ''
                            ? Storage::disk('public')->url($att['file_path'])
                            : '';
                        return $att;
                    }, $msg['attachments']);
                }
                return $msg;
            }, $payload['messages']);
        }

        return $payload;
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_id' => ['nullable', 'exists:bookings,id'],
            'transection_id' => ['nullable', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:' . implode(',', [
                SupportTicket::CATEGORY_BOOKING,
                SupportTicket::CATEGORY_AFTER_RIDE,
                SupportTicket::CATEGORY_PAYMENT,
                SupportTicket::CATEGORY_DRIVER,
                SupportTicket::CATEGORY_ACCOUNT,
                SupportTicket::CATEGORY_TECHNICAL,
                SupportTicket::CATEGORY_OTHER,
                SupportTicket::CATEGORY_RIDE_ISSUE,
                SupportTicket::CATEGORY_PAYMENT_WALLET,
                SupportTicket::CATEGORY_OFFER,
            ])],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['required', 'file', 'max:10240'],  // 10MB max
        ]);

        try {
            DB::beginTransaction();

            $ticket = SupportTicket::create([
                'user_id' => auth()->id(),
                'booking_id' => $data['booking_id'] ?? null,
                'transection_id' => $data['transection_id'] ?? null,
                'category' => $data['category'],
                'subject' => $data['subject'],
                'priority' => $data['category'] === SupportTicket::CATEGORY_PAYMENT
                    ? SupportTicket::PRIORITY_HIGH
                    : SupportTicket::PRIORITY_MEDIUM,
            ]);

            $message = $ticket->messages()->create([
                'user_id' => auth()->id(),
                'message' => $data['message'],
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
                    ]);
                }
            }

            DB::commit();

            $ticket->load(['messages.attachments', 'activities']);

            $ticketPayload = $this->addUrlsToAttachments(
                $this->replaceNulls($ticket->toArray())
            );

            return response()->json([
                'message' => 'Support ticket created successfully',
                'ticket' => $ticketPayload,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        if ($ticket->user_id !== auth()->id()) {
            throw ValidationException::withMessages([
                'ticket' => ['You do not have access to this ticket.'],
            ]);
        }

        $ticket
            ->messages()
            ->where('user_id', '!=', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $ticket->load([
            'messages' => function ($query) {
                $query->public()->with(['user', 'attachments'])->latest();
            },
            'activities' => function ($query) {
                $query->with('user')->latest();
            },
        ]);

        $ticketArray = $this->addUrlsToAttachments(
            $this->replaceNulls($ticket->toArray())
        );

        return response()->json($ticketArray);
    }

    public function messages(Request $request, SupportTicket $ticket): JsonResponse
    {
        if ((int) $ticket->user_id !== auth()->id()) {
            throw ValidationException::withMessages([
                'ticket' => ['You do not have access to this ticket.'],
            ]);
        }

        $ticket
            ->messages()
            ->where('user_id', '!=', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        $messages = $ticket
            ->messages()
            ->public()
            ->with(['attachments'])
            ->latest()
            ->paginate($perPage);

        $messages->setCollection(
            $messages->getCollection()->map(function (SupportMessage $message) {
                return $this->serializeMessage($message);
            })
        );

        return response()->json($this->formatPaginator($messages));
    }

    public function messagesByBooking(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_id' => ['required', 'integer', 'exists:support_chats,booking_id'],
        ]);

        $messages = SupportChat::where('booking_id', $data['booking_id'])
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        if ($messages->isEmpty()) {
            throw ValidationException::withMessages([
                'booking_id' => ['No support messages found for this booking.'],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $messages,
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        if ($ticket->user_id !== auth()->id()) {
            throw ValidationException::withMessages([
                'ticket' => ['You do not have access to this ticket.'],
            ]);
        }

        if (!$ticket->isOpen()) {
            throw ValidationException::withMessages([
                'ticket' => ['This ticket is closed and cannot be replied to.'],
            ]);
        }

        $data = $request->validate([
            'message' => ['required', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['required', 'file', 'max:10240'],  // 10MB max
        ]);

        try {
            DB::beginTransaction();

            $message = $ticket->messages()->create([
                'user_id' => auth()->id(),
                'message' => $data['message'],
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
                    ]);
                }
            }

            if ($ticket->isResolved()) {
                $ticket->reopen('User replied to resolved ticket');
            }

            DB::commit();

            $message->load(['user', 'attachments']);

            $replyPayload = $this->addUrlsToAttachments(
                $this->replaceNulls($message->toArray())
            );

            return response()->json([
                'message' => 'Reply added successfully',
                'reply' => $replyPayload,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function close(Request $request, SupportTicket $ticket): JsonResponse
    {
        if ($ticket->user_id !== auth()->id()) {
            throw ValidationException::withMessages([
                'ticket' => ['You do not have access to this ticket.'],
            ]);
        }

        if ($ticket->isClosed()) {
            throw ValidationException::withMessages([
                'ticket' => ['This ticket is already closed.'],
            ]);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $ticket->close($data['reason'] ?? null);

        return response()->json([
            'message' => 'Ticket closed successfully',
            'ticket' => $ticket->fresh(['activities']),
        ]);
    }

    public function reopen(Request $request, SupportTicket $ticket): JsonResponse
    {
        if ($ticket->user_id !== auth()->id()) {
            throw ValidationException::withMessages([
                'ticket' => ['You do not have access to this ticket.'],
            ]);
        }

        if (!$ticket->isClosed()) {
            throw ValidationException::withMessages([
                'ticket' => ['This ticket is not closed.'],
            ]);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $ticket->reopen($data['reason']);

        return response()->json([
            'message' => 'Ticket reopened successfully',
            'ticket' => $ticket->fresh(['activities']),
        ]);
    }

    public function downloadAttachment(SupportTicket $ticket, SupportAttachment $attachment): JsonResponse
    {
        if ($ticket->user_id !== auth()->id()) {
            throw ValidationException::withMessages([
                'ticket' => ['You do not have access to this ticket.'],
            ]);
        }

        if ($attachment->ticket_id !== $ticket->id || $attachment->is_internal) {
            throw ValidationException::withMessages([
                'attachment' => ['You do not have access to this attachment.'],
            ]);
        }

        if (!Storage::exists($attachment->file_path)) {
            throw ValidationException::withMessages([
                'attachment' => ['The requested file does not exist.'],
            ]);
        }

        return response()->json([
            'url' => Storage::temporaryUrl(
                $attachment->file_path,
                now()->addMinutes(5),
                [
                    'ResponseContentType' => $attachment->file_type,
                    'ResponseContentDisposition' => 'attachment; filename="' . $attachment->name . '"',
                ]
            ),
        ]);
    }
}
