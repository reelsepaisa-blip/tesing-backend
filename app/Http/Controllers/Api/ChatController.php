<?php

namespace App\Http\Controllers\Api;

use App\Events\ChatMessage;
use App\Events\ChatMessageRead;
use App\Events\UserTyping;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Chat;
use App\Services\FCMService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{

    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'message' => 'required|string|max:50000',
            'message_type' => 'nullable|in:text,image,location,system',
            'metadata' => 'nullable|array',
        ]);

        $user = Auth::user();
        $booking = Booking::find($request->booking_id);
        if (!$this->canUserAccessBookingChat($user, $booking)) {
            throw ValidationException::withMessages([
                'booking' => ['You are not authorized to access this chat.'],
            ]);
        }

        $receiverId = $this->getReceiverId($user, $booking);
        $senderType = $user->role == 'driver' ? 'driver' : 'user';

        $chat = Chat::create([
            'booking_id' => $request->booking_id,
            'sender_id' => $user->id,
            'receiver_id' => $receiverId,
            'sender_type' => $senderType,
            'message' => $request->message,
            'message_type' => $request->message_type ?? 'text',
            'metadata' => $request->metadata,
        ]);

        event(new ChatMessage($chat));

        $this->sendChatNotification($chat);

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully',
            'chat' => $this->formatChatMessage($chat->load(['sender', 'receiver', 'booking'])),
        ]);
    }


    public function getMessages(Request $request, Booking $booking): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canUserAccessBookingChat($user, $booking)) {
            throw ValidationException::withMessages([
                'booking' => ['You are not authorized to access this chat.'],
            ]);
        }

        $messages = Chat::forBooking($booking->id)
            ->with(['sender', 'receiver'])
            ->orderBy('created_at', 'asc')
            ->paginate($request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'messages' => $messages->map(function ($chat) {
                return $this->formatChatMessage($chat);
            }),
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }


    public function markAsRead(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'message_ids' => 'nullable|array',
            'message_ids.*' => 'exists:chats,id',
        ]);

        $user = Auth::user();
        $booking = Booking::find($request->booking_id);

        if (!$this->canUserAccessBookingChat($user, $booking)) {
            throw ValidationException::withMessages([
                'booking' => ['You are not authorized to access this chat.'],
            ]);
        }

        $query = Chat::forBooking($request->booking_id)
            ->where('receiver_id', $user->id)
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
            event(new ChatMessageRead($booking, $user, $messageIds));
        }

        return response()->json([
            'success' => true,
            'message' => 'Messages marked as read',
            'updated_count' => $updatedCount,
        ]);
    }


    public function getUnreadCount(Booking $booking): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canUserAccessBookingChat($user, $booking)) {
            throw ValidationException::withMessages([
                'booking' => ['You are not authorized to access this chat.'],
            ]);
        }

        $unreadCount = Chat::forBooking($booking->id)
            ->where('receiver_id', $user->id)
            ->unread()
            ->count();

        return response()->json([
            'success' => true,
            'unread_count' => $unreadCount,
        ]);
    }


    public function sendQuickMessage(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'message_key' => 'required|string|in:coming,waiting,quick,arrived,started',
        ]);

        $quickMessages = [
            'coming' => 'Are you coming?',
            'waiting' => 'Waiting at the pickup location',
            'quick' => 'Please come quickly',
            'arrived' => 'I have arrived at the pickup location',
            'started' => 'Trip has started',
        ];

        $message = $quickMessages[$request->message_key] ?? 'Quick message';

        $request->merge(['message' => $message, 'message_type' => 'text']);

        return $this->sendMessage($request);
    }


    public function sendImage(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB max
            'caption' => 'nullable|string|max:500',
        ]);

        $user = Auth::user();
        $booking = Booking::find($request->booking_id);

        if (!$this->canUserAccessBookingChat($user, $booking)) {
            throw ValidationException::withMessages([
                'booking' => ['You are not authorized to access this chat.'],
            ]);
        }

        $imagePath = $request->file('image')->store('chat-images', 'public');
        $imageUrl = Storage::url($imagePath);

        $receiverId = $this->getReceiverId($user, $booking);
        $senderType = $user->role == 'driver' ? 'driver' : 'user';

        $chat = Chat::create([
            'booking_id' => $request->booking_id,
            'sender_id' => $user->id,
            'receiver_id' => $receiverId,
            'sender_type' => $senderType,
            'message' => $request->caption ?? 'Image',
            'message_type' => 'image',
            'metadata' => [
                'image_path' => $imagePath,
                'image_url' => $imageUrl,
                'file_size' => $request->file('image')->getSize(),
                'mime_type' => $request->file('image')->getMimeType(),
            ],
        ]);

        event(new ChatMessage($chat));

        $this->sendChatNotification($chat);

        return response()->json([
            'success' => true,
            'message' => 'Image sent successfully',
            'chat' => $this->formatChatMessage($chat->load(['sender', 'receiver', 'booking'])),
        ]);
    }


    public function sendLocation(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
        ]);

        $user = Auth::user();
        $booking = Booking::find($request->booking_id);

        if (!$this->canUserAccessBookingChat($user, $booking)) {
            throw ValidationException::withMessages([
                'booking' => ['You are not authorized to access this chat.'],
            ]);
        }

        $receiverId = $this->getReceiverId($user, $booking);
        $senderType = $user->role == 'driver' ? 'driver' : 'user';

        $chat = Chat::create([
            'booking_id' => $request->booking_id,
            'sender_id' => $user->id,
            'receiver_id' => $receiverId,
            'sender_type' => $senderType,
            'message' => $request->address ?? 'Location shared',
            'message_type' => 'location',
            'metadata' => [
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'address' => $request->address,
            ],
        ]);

        event(new ChatMessage($chat));

        $this->sendChatNotification($chat);

        return response()->json([
            'success' => true,
            'message' => 'Location sent successfully',
            'chat' => $this->formatChatMessage($chat->load(['sender', 'receiver', 'booking'])),
        ]);
    }


    public function setTypingStatus(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'is_typing' => 'required|boolean',
        ]);

        $user = Auth::user();
        $booking = Booking::find($request->booking_id);

        if (!$this->canUserAccessBookingChat($user, $booking)) {
            throw ValidationException::withMessages([
                'booking' => ['You are not authorized to access this chat.'],
            ]);
        }

        event(new UserTyping($booking, $user, $request->is_typing));

        return response()->json([
            'success' => true,
            'message' => 'Typing status updated',
        ]);
    }


    public function getChatList(Request $request): JsonResponse
    {
        $user = Auth::user();
        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $bookingId = $request->input('booking_id');

        if ($bookingId) {
            $booking = Booking::find($bookingId);
            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found',
                ], 404);
            }

            if (!$this->canUserAccessBookingChat($user, $booking)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to access this chat',
                ], 403);
            }
        }

        $chatsQuery = Chat::where(function ($query) use ($user) {
            $query->where('sender_id', $user->id)
                ->orWhere('receiver_id', $user->id);
        });

        if ($bookingId) {
            $chatsQuery->where('booking_id', $bookingId);
        }

        $chatsQuery->with(['sender', 'receiver', 'booking'])
            ->orderBy('created_at', 'desc');

        $chats = $chatsQuery->paginate($perPage, ['*'], 'page', $page);

        $chatList = $chats->map(function ($chat) use ($user) {
            $otherParticipant = $chat->sender_id === $user->id ? $chat->receiver : $chat->sender;

            return [
                'id' => (string) $chat->id,
                'booking_id' => (string) $chat->booking_id,
                'booking_status' => $chat->booking->status,
                'message' => $chat->message,
                'message_type' => $chat->message_type,
                'metadata' => $chat->metadata,
                'is_read' => $chat->is_read,
                'is_from_me' => $chat->sender_id === $user->id,
                'sender' => [
                    'id' => (string) $chat->sender->id,
                    'name' => $chat->sender->name,
                    'phone' => $chat->sender->phone,
                    'profile_photo' => $chat->sender->profile_photo ? asset('storage/' . $chat->sender->profile_photo) : '',
                    'sender_type' => $chat->sender_type,
                ],
                'receiver' => [
                    'id' => (string) $chat->receiver->id,
                    'name' => $chat->receiver->name,
                    'phone' => $chat->receiver->phone,
                    'profile_photo' => $chat->receiver->profile_photo ? asset('storage/' . $chat->receiver->profile_photo) : '',
                ],
                'other_participant' => [
                    'id' => (string) $otherParticipant->id,
                    'name' => $otherParticipant->name,
                    'phone' => $otherParticipant->phone,
                    'profile_photo' => $otherParticipant->profile_photo ? asset('storage/' . $otherParticipant->profile_photo) : '',
                    'role' => $otherParticipant->role,
                ],
                'created_at' => $chat->created_at->toISOString(),
                'updated_at' => $chat->updated_at->toISOString(),
                'read_at' => $chat->read_at ? $chat->read_at->toISOString() : '',
            ];
        });

        return response()->json([
            'success' => true,
            'chats' => $chatList,
            'pagination' => [
                'current_page' => $chats->currentPage(),
                'last_page' => $chats->lastPage(),
                'per_page' => $chats->perPage(),
                'total' => $chats->total(),
                'from' => $chats->firstItem() ?? "",
                'to' => $chats->lastItem() ?? "",
            ],
        ]);
    }


    public function getChatStats(Request $request, Booking $booking): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canUserAccessBookingChat($user, $booking)) {
            throw ValidationException::withMessages([
                'booking' => ['You are not authorized to access this chat.'],
            ]);
        }

        $totalMessages = Chat::forBooking($booking->id)->count();
        $unreadMessages = Chat::forBooking($booking->id)
            ->where('receiver_id', $user->id)
            ->unread()
            ->count();

        $userMessages = Chat::forBooking($booking->id)
            ->where('sender_type', 'user')
            ->count();

        $driverMessages = Chat::forBooking($booking->id)
            ->where('sender_type', 'driver')
            ->count();

        $lastMessage = Chat::forBooking($booking->id)
            ->with(['sender'])
            ->latest()
            ->first();

        return response()->json([
            'success' => true,
            'stats' => [
                'total_messages' => $totalMessages,
                'unread_messages' => $unreadMessages,
                'user_messages' => $userMessages,
                'driver_messages' => $driverMessages,
                'last_message' => $lastMessage ? [
                    'id' => (string) $lastMessage->id,
                    'message' => $lastMessage->message,
                    'message_type' => $lastMessage->message_type,
                    'sender_type' => $lastMessage->sender_type,
                    'sender_name' => $lastMessage->sender->name,
                    'created_at' => $lastMessage->created_at->toISOString(),
                ] : null,
            ],
        ]);
    }


    public function deleteMessage(Request $request, Chat $chat): JsonResponse
    {
        $user = Auth::user();

        if ($chat->sender_id !== $user->id) {
            throw ValidationException::withMessages([
                'message' => ['You can only delete your own messages.'],
            ]);
        }

        if ($chat->created_at->diffInMinutes(now()) > 5) {
            throw ValidationException::withMessages([
                'message' => ['Messages can only be deleted within 5 minutes of sending.'],
            ]);
        }

        $chat->update([
            'message' => 'This message was deleted',
            'message_type' => 'deleted',
            'metadata' => array_merge($chat->metadata ?? [], [
                'deleted_at' => now()->format('Y-m-d H:i:s'),
                'original_message' => $chat->getOriginal('message'),
                'original_type' => $chat->getOriginal('message_type'),
            ]),
        ]);

        event(new ChatMessage($chat->fresh()->load(['sender', 'receiver', 'booking'])));

        return response()->json([
            'success' => true,
            'message' => 'Message deleted successfully',
        ]);
    }


    private function sendChatNotification(Chat $chat): void
    {
        $startTime = microtime(true);


        try {
            $fcmService = app(FCMService::class);

            // Ensure relationships are loaded
            $receiverLoaded = $chat->relationLoaded('receiver');
            $senderLoaded = $chat->relationLoaded('sender');

            if (!$receiverLoaded) {
                $chat->load('receiver');
            }
            if (!$senderLoaded) {
                $chat->load('sender');
            }

            $receiver = $chat->receiver;
            $sender = $chat->sender;

            // Validate receiver and sender exist
            if (!$receiver || !$sender) {
                return;
            }

            // Check if receiver has FCM token
            $hasFcmToken = !empty($receiver->fcm_token);
            $fcmTokenLength = $hasFcmToken ? strlen($receiver->fcm_token) : 0;
            $fcmTokenPreview = $hasFcmToken ? substr($receiver->fcm_token, 0, 20) . '...' : 'N/A';

            if (!$receiver->fcm_token) {
                return;
            }

            $senderName = $sender->name;
            $messagePreview = $this->getMessagePreview($chat);


            $notification = [
                'title' => "New Message from {$senderName}",
                'body' => $messagePreview,
                'icon' => 'ic_chat',
                'sound' => 'chat_message.mp3',
            ];

            $data = [
                'type' => 'chat',
                'booking_id' => (string) $chat->booking_id,
                'chat_id' => (string) $chat->id,
                'sender_id' => (string) $chat->sender_id,
                'sender_type' => $chat->sender_type,
                'sender_name' => $senderName,
                'receiver_id' => (string) $chat->receiver_id,
                'msg_type' => $chat->message_type, // Changed from 'message_type' as it's reserved in FCM
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ];

            $result = $fcmService->sendToDevice($receiver->fcm_token, $notification, $data);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($result['success']) {
                // Notification sent successfully
            } else {
                // Notification failed
            }
        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);


        }
    }


    private function getMessagePreview(Chat $chat): string
    {
        return match ($chat->message_type) {
            'image' => '📷 Image',
            'location' => '📍 Location',
            'deleted' => 'This message was deleted',
            default => strlen($chat->message) > 50
                ? substr($chat->message, 0, 50) . '...'
                : $chat->message,
        };
    }


    private function canUserAccessBookingChat($user, $booking): bool
    {
        return (int)$user->id === (int)$booking->user_id || (int)$user->id === (int)$booking->driver_id;
    }


    private function getReceiverId($user, $booking): int
    {
        if ($user->id === $booking->user_id) {
            return $booking->driver_id;
        }

        return $booking->user_id;
    }


    private function formatChatMessage($chat): array
    {
        return [
            'id' => (string) $chat->id,
            'booking_id' => (string) $chat->booking_id,
            'message' => $chat->message,
            'message_type' => $chat->message_type,
            'metadata' => $chat->metadata,
            'is_read' => $chat->is_read,
            'sender' => [
                'id' => (string) $chat->sender->id,
                'name' => $chat->sender->name,
                'phone' => $chat->sender->phone,
                'profile_photo' => $chat->sender->profile_photo ? asset('storage/' . $chat->sender->profile_photo) : '',
                'sender_type' => $chat->sender_type,
            ],
            'receiver' => [
                'id' => (string) $chat->receiver->id,
                'name' => $chat->receiver->name,
                'phone' => $chat->receiver->phone,
                'profile_photo' => $chat->receiver->profile_photo ? asset('storage/' . $chat->receiver->profile_photo) : '',
            ],
            'created_at' => $chat->created_at->toISOString(),
            'updated_at' => $chat->updated_at->toISOString(),
            'read_at' => $chat->read_at ? $chat->read_at->toISOString() : '',
        ];
    }
}
