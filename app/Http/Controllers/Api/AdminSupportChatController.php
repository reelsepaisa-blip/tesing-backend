<?php

namespace App\Http\Controllers\Api;

use App\Events\SupportChatMessage;
use App\Events\SupportChatRead;
use App\Events\SupportTyping;
use App\Http\Controllers\Controller;
use App\Models\RefundRequest;
use App\Models\SupportChat;
use App\Models\User;
use App\Services\FCMService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminSupportChatController extends Controller
{
    protected $fcmService;

    public function __construct(FCMService $fcmService)
    {
        $this->fcmService = $fcmService;
    }

    
    public function getConversations(Request $request): JsonResponse
    {
        $admin = Auth::user();

        $conversations = SupportChat::where(function ($query) use ($admin) {
            $query->where('admin_id', $admin->id)
                ->orWhereNull('admin_id');
        })
            ->with(['user', 'admin'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('user_id');

        $conversationList = [];
        foreach ($conversations as $userId => $messages) {
            $user = $messages->first()->user;
            $unreadCount = $messages->where('sender_type', 'user')->where('is_read', false)->count();
            $lastMessage = $messages->sortByDesc('created_at')->first();

            $conversationList[] = [
                'user' => [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'profile_photo' => $user->profile_photo ?? '',
                ],
                'unread_count' => $unreadCount,
                'last_message' => $lastMessage ? [
                    'id' => (string) $lastMessage->id,
                    'message' => $lastMessage->message,
                    'message_type' => $lastMessage->message_type,
                    'sender_type' => $lastMessage->sender_type,
                    'created_at' => $lastMessage->created_at->toISOString(),
                ] : null,
                'status' => $lastMessage ? $lastMessage->status : 'open',
                'priority' => $lastMessage ? $lastMessage->priority : 'medium',
            ];
        }

        return response()->json([
            'success' => true,
            'conversations' => $conversationList,
        ]);
    }

    
    public function getMessages(Request $request, User $user): JsonResponse
    {
        $admin = Auth::user();

        SupportChat::forUser($user->id)
            ->whereNull('admin_id')
            ->update(['admin_id' => $admin->id]);

        $messages = SupportChat::forUser($user->id)
            ->with(['user', 'admin', 'booking'])
            ->orderBy('created_at', 'asc')
            ->paginate($request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'messages' => $messages->map(function ($supportChat) {
                return $this->formatSupportChatMessage($supportChat);
            }),
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    
    public function sendReply(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:50000',
            'message_type' => 'nullable|in:text,image,file,system',
            'metadata' => 'nullable|array',
        ]);

        $admin = Auth::user();

        $supportChat = SupportChat::create([
            'user_id' => $user->id,
            'admin_id' => $admin->id,
            'sender_type' => 'admin',
            'message' => $request->message,
            'message_type' => $request->message_type ?? 'text',
            'metadata' => $request->metadata,
        ]);

        event(new SupportChatMessage($supportChat));

        $this->sendSupportNotification($supportChat);

        return response()->json([
            'success' => true,
            'message' => 'Reply sent successfully',
            'support_chat' => $this->formatSupportChatMessage($supportChat->load(['user', 'admin'])),
        ]);
    }

    
    public function markAsRead(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'message_ids' => 'nullable|array',
            'message_ids.*' => 'exists:support_chats,id',
        ]);

        $admin = Auth::user();

        $query = SupportChat::forUser($user->id)
            ->where('sender_type', 'user')
            ->where('is_read', false);

        if ($request->message_ids) {
            $query->whereIn('id', $request->message_ids);
        }

        $messageIds = $query->pluck('id')->toArray();

        $updatedCount = $query->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        if ($updatedCount > 0) {
            event(new SupportChatRead($user, $admin, $messageIds));
        }

        return response()->json([
            'success' => true,
            'message' => 'Messages marked as read',
            'updated_count' => $updatedCount,
        ]);
    }

    
    public function setTypingStatus(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'is_typing' => 'required|boolean',
        ]);

        $admin = Auth::user();

        event(new SupportTyping($user, $admin, $request->is_typing));

        return response()->json([
            'success' => true,
            'message' => 'Typing status updated',
        ]);
    }

    
    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:open,closed,pending',
            'priority' => 'nullable|in:low,medium,high,urgent',
        ]);

        $admin = Auth::user();

        $updateData = ['status' => $request->status];
        if ($request->priority) {
            $updateData['priority'] = $request->priority;
        }

        $updatedCount = SupportChat::forUser($user->id)
            ->where('admin_id', $admin->id)
            ->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Conversation status updated',
            'updated_count' => $updatedCount,
        ]);
    }

    
    public function getStats(): JsonResponse
    {
        $admin = Auth::user();

        $totalConversations = SupportChat::where('admin_id', $admin->id)
            ->distinct('user_id')
            ->count('user_id');

        $openConversations = SupportChat::where('admin_id', $admin->id)
            ->where('status', 'open')
            ->distinct('user_id')
            ->count('user_id');

        $unreadMessages = SupportChat::where('admin_id', $admin->id)
            ->where('sender_type', 'user')
            ->unread()
            ->count();

        $todayMessages = SupportChat::where('admin_id', $admin->id)
            ->whereDate('created_at', today())
            ->count();

        return response()->json([
            'success' => true,
            'stats' => [
                'total_conversations' => $totalConversations,
                'open_conversations' => $openConversations,
                'unread_messages' => $unreadMessages,
                'today_messages' => $todayMessages,
            ],
        ]);
    }

    
    public function sendImageReply(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB max
            'caption' => 'nullable|string|max:500',
        ]);

        $admin = Auth::user();

        $imagePath = $request->file('image')->store('support-images', 'public');
        $imageUrl = Storage::url($imagePath);

        $supportChat = SupportChat::create([
            'user_id' => $user->id,
            'admin_id' => $admin->id,
            'sender_type' => 'admin',
            'message' => $request->caption ?? 'Image',
            'message_type' => 'image',
            'metadata' => [
                'image_path' => $imagePath,
                'image_url' => $imageUrl,
                'file_size' => $request->file('image')->getSize(),
                'mime_type' => $request->file('image')->getMimeType(),
            ],
        ]);

        event(new SupportChatMessage($supportChat));

        $this->sendSupportNotification($supportChat);

        return response()->json([
            'success' => true,
            'message' => 'Image sent successfully',
            'support_chat' => $this->formatSupportChatMessage($supportChat->load(['user', 'admin'])),
        ]);
    }

    
    private function sendSupportNotification(SupportChat $supportChat): void
    {
        try {
            $user = $supportChat->user;

            if (!$user->fcm_token) {
                return;
            }

            $senderName = $supportChat->admin->name;
            $messagePreview = $this->getMessagePreview($supportChat);

            $notification = [
                'title' => "Support reply from {$senderName}",
                'body' => $messagePreview,
                'icon' => 'ic_support',
                'sound' => 'support_reply.mp3',
            ];

            $data = [
                'type' => 'support_chat',
                'support_chat_id' => (string) $supportChat->id,
                'admin_id' => (string) $supportChat->admin_id,
                'message_type' => $supportChat->message_type,
            ];

            $this->fcmService->sendToDevice($user->fcm_token, $notification, $data);
        } catch (\Exception $e) {
            // Continue on error
        }
    }

    
    private function getMessagePreview(SupportChat $supportChat): string
    {
        return match ($supportChat->message_type) {
            'image' => '📷 Image',
            'file' => '📎 File',
            default => strlen($supportChat->message) > 50
                ? substr($supportChat->message, 0, 50) . '...'
                : $supportChat->message,
        };
    }

    
    private function formatSupportChatMessage($supportChat): array
    {
        $formatted = [
            'id' => (string) $supportChat->id,
            'user_id' => (string) $supportChat->user_id,
            'admin_id' => $supportChat->admin_id ? (string) $supportChat->admin_id : null,
            'booking_id' => $supportChat->booking_id ? (string) $supportChat->booking_id : null,
            'message' => $supportChat->message,
            'message_type' => $supportChat->message_type,
            'metadata' => $supportChat->metadata,
            'is_read' => $supportChat->is_read,
            'status' => $supportChat->status,
            'subject' => $supportChat->subject,
            'priority' => $supportChat->priority,
            'sender' => [
                'id' => (string) $supportChat->sender->id,
                'name' => $supportChat->sender->name,
                'phone' => $supportChat->sender->phone,
                'profile_photo' => $supportChat->sender->profile_photo ?? '',
                'sender_type' => $supportChat->sender_type,
            ],
            'created_at' => $supportChat->created_at->toISOString(),
            'updated_at' => $supportChat->updated_at->toISOString(),
            'read_at' => $supportChat->read_at ? $supportChat->read_at->toISOString() : null,
        ];

        if ($supportChat->metadata && isset($supportChat->metadata['refund_request_id'])) {
            $refundRequest = RefundRequest::with(['processedBy'])->find($supportChat->metadata['refund_request_id']);
            if ($refundRequest) {
                $formatted['refund_request'] = [
                    'id' => $refundRequest->id,
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
                ];
            }
        }

        if ($supportChat->booking) {
            $formatted['booking'] = [
                'id' => (string) $supportChat->booking->id,
                'booking_code' => $supportChat->booking->booking_code,
                'total_amount' => $supportChat->booking->total_amount,
                'status' => $supportChat->booking->status,
            ];
        }

        return $formatted;
    }
}
