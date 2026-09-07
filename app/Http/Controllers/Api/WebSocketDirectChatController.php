<?php

namespace App\Http\Controllers\Api;

use App\Events\ChatMessage;
use App\Events\ChatMessageRead;
use App\Events\UserTyping;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Chat;
use App\Models\User;
use App\Services\FCMService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class WebSocketDirectChatController extends Controller
{
    protected $fcmService;

    public function __construct(FCMService $fcmService)
    {
        $this->fcmService = $fcmService;
    }

    public function handleDirectMessage(Request $request): JsonResponse
    {
        $request->validate([
            'event' => 'required|string',
            'data' => 'required|array',
            'channel' => 'required|string',
        ]);

        try {
            $user = Auth::user();
            if (!$user) {
                $user = $this->resolveTokenUser($request);
            }
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'User not authenticated'
                ], 401);
            }
            $event = $request->input('event');
            $data = $request->input('data');
            $channel = $request->input('channel');

            if (!preg_match('/private-chat\.booking\.(\d+)/', $channel, $matches)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid channel format'
                ], 400);
            }

            $bookingId = $matches[1];
            $booking = Booking::find($bookingId);

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'error' => 'Booking not found'
                ], 404);
            }

            if (!$this->canUserAccessBookingChat($user, $booking)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized to access this chat'
                ], 403);
            }

            switch ($event) {
                case 'client-send-message':
                    return $this->handleSendMessage($user, $booking, $data);

                case 'client-typing':
                    return $this->handleTypingStatus($user, $booking, $data);

                case 'client-mark-read':
                    return $this->handleMarkAsRead($user, $booking, $data);

                case 'client-get-messages':
                    return $this->handleGetMessages($user, $booking, $data);

                case 'pusher:unsubscribe':
                    return $this->handleUnsubscribe($user, $booking, $data);

                default:
                    return response()->json([
                        'success' => false,
                        'error' => 'Unknown event type'
                    ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to process message'
            ], 500);
        }
    }

    private function handleSendMessage(User $user, Booking $booking, array $data): JsonResponse
    {
        $data = array_merge($data, [
            'message' => $data['message'] ?? '',
            'message_type' => $data['message_type'] ?? 'text',
            'metadata' => $data['metadata'] ?? [],
        ]);

        if (empty($data['message'])) {
            return response()->json([
                'success' => false,
                'error' => 'Message is required'
            ], 400);
        }

        $receiverId = $this->getReceiverId($user, $booking);
        $senderType = $user->hasRole('driver') ? 'driver' : 'user';

        $chat = Chat::create([
            'booking_id' => $booking->id,
            'sender_id' => $user->id,
            'receiver_id' => $receiverId,
            'sender_type' => $senderType,
            'message' => $data['message'],
            'message_type' => $data['message_type'],
            'metadata' => $data['metadata'],
        ]);

        $chat->load(['sender', 'receiver', 'booking']);

        event(new ChatMessage($chat));

        $this->sendChatNotification($chat);

        return response()->json([
            'success' => true,
            'event' => 'server-message-sent',
            'data' => [
                'chat' => $this->formatChatMessage($chat),
                'channels' => [
                    'private-chat.booking.' . $booking->id,
                    'user.' . $receiverId,
                    'user.' . $user->id,
                ]
            ]
        ]);
    }

    private function handleUnsubscribe(User $user, Booking $booking, array $data): JsonResponse
    {
        $channel = $data['channel'] ?? null;

        if (!$channel) {
            return response()->json([
                'success' => false,
                'error' => 'Channel name is required'
            ], 400);
        }



        broadcast(new \App\Events\UserTyping($booking, $user, false))->toOthers();

        return response()->json([
            'success' => true,
            'event' => 'server-unsubscribed',
            'data' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'message' => 'User unsubscribed successfully',
                'channel' => $channel,
            ]
        ]);
    }

    private function handleTypingStatus(User $user, Booking $booking, array $data): JsonResponse
    {
        $isTyping = $data['is_typing'] ?? false;

        event(new UserTyping($booking, $user, $isTyping));

        return response()->json([
            'success' => true,
            'event' => 'server-typing-status',
            'data' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'is_typing' => $isTyping,
                'channels' => [
                    'private-chat.booking.' . $booking->id,
                ]
            ]
        ]);
    }

    private function handleMarkAsRead(User $user, Booking $booking, array $data): JsonResponse
    {
        $messageIds = $data['message_ids'] ?? [];

        $query = Chat::forBooking($booking->id)
            ->where('receiver_id', $user->id)
            ->where('is_read', false);

        if (!empty($messageIds)) {
            $query->whereIn('id', $messageIds);
        }

        $messageIdsToUpdate = $query->pluck('id')->toArray();

        $updatedCount = $query->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        if ($updatedCount > 0) {
            event(new ChatMessageRead($booking, $user, $messageIdsToUpdate));
        }

        return response()->json([
            'success' => true,
            'event' => 'server-messages-read',
            'data' => [
                'updated_count' => $updatedCount,
                'message_ids' => $messageIdsToUpdate,
                'channels' => [
                    'private-chat.booking.' . $booking->id,
                    'user.' . $user->id,
                ]
            ]
        ]);
    }

    private function handleGetMessages(User $user, Booking $booking, array $data): JsonResponse
    {
        $perPage = $data['per_page'] ?? 50;
        $page = $data['page'] ?? 1;

        $messages = Chat::forBooking($booking->id)
            ->with(['sender', 'receiver'])
            ->orderBy('created_at', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'event' => 'server-messages-list',
            'data' => [
                'messages' => $messages->map(function ($chat) {
                    return $this->formatChatMessage($chat);
                }),
                'pagination' => [
                    'current_page' => $messages->currentPage(),
                    'last_page' => $messages->lastPage(),
                    'per_page' => $messages->perPage(),
                    'total' => $messages->total(),
                ],
                'booking_id' => $booking->id,
            ]
        ]);
    }

    public function authenticateWebSocket(Request $request): JsonResponse
    {
        $request->validate([
            'socket_id' => 'required|string',
            'channel_name' => 'required|string',
        ]);

        try {
            $authHeader = $request->header('Authorization');

            if (!$authHeader) {
                return response()->json([
                    'error' => 'Authorization header required'
                ], 401);
            }

            $token = $this->extractTokenFromHeader($authHeader);
            $socketId = $request->input('socket_id');
            $channelName = $request->input('channel_name');

            $user = $this->resolveUserByToken($token);
            if (!$user) {
                return response()->json([
                    'error' => 'Invalid token'
                ], 401);
            }


            if (!preg_match('/private-chat\.booking\.(\d+)/', $channelName, $matches)) {
                return response()->json([
                    'error' => 'Invalid channel format'
                ], 400);
            }

            $bookingId = $matches[1];
            $booking = Booking::find($bookingId);

            if (!$booking) {
                return response()->json([
                    'error' => 'Booking not found'
                ], 404);
            }

            if (!$this->canUserAccessBookingChat($user, $booking)) {
                return response()->json([
                    'error' => 'Unauthorized to access this chat'
                ], 403);
            }

            $pusherAppSecret = config('broadcasting.connections.pusher.secret');
            $stringToSign = $socketId . ':' . $channelName;
            $authSignature = hash_hmac('sha256', $stringToSign, $pusherAppSecret);

            // Store socket_id to user_id mapping for webhook identification
            Cache::put("socket_user:{$socketId}", $user->id, now()->addHours(24));


            return response()->json([
                'auth' => config('broadcasting.connections.pusher.key') . ':' . $authSignature,
                'user_data' => [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'user_type' => $user->isDriver() ? 'driver' : 'user',
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Authentication failed'
            ], 500);
        }
    }

    private function sendChatNotification(Chat $chat): void
    {
        $startTime = microtime(true);


        try {
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
                'msg_type' => $chat->message_type,  // Changed from 'message_type' as it's reserved in FCM
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ];


            

            $result = $this->fcmService->sendToDevice($receiver->fcm_token, $notification, $data);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($result['success']) {
                
            } else {
                
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
        $userId = (string) $user->id;
        $bookingUserId = (string) $booking->user_id;
        $bookingDriverId = (string) $booking->driver_id;

        return $userId === $bookingUserId || $userId === $bookingDriverId;
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
                'profile_photo' => $chat->sender->profile_photo ?? '',
                'sender_type' => $chat->sender_type,
            ],
            'receiver' => [
                'id' => (string) $chat->receiver->id,
                'name' => $chat->receiver->name,
                'phone' => $chat->receiver->phone,
                'profile_photo' => $chat->receiver->profile_photo ?? '',
            ],
            'created_at' => $chat->created_at->toISOString(),
            'updated_at' => $chat->updated_at->toISOString(),
            'read_at' => $chat->read_at ? $chat->read_at->toISOString() : null,
        ];
    }

    private function resolveTokenUser(Request $request): ?User
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader) {
            return null;
        }

        return $this->resolveUserByToken($this->extractTokenFromHeader($authHeader));
    }

    private function extractTokenFromHeader(string $authHeader): string
    {
        if (str_starts_with($authHeader, 'Bearer_')) {
            return substr($authHeader, 7);
        }

        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return $authHeader;
    }

    private function resolveUserByToken(string $token): ?User
    {
        $normalizedToken = str_replace('Bearer_', '', trim($token));
        if ($normalizedToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $normalizedToken);

        $personalToken = PersonalAccessToken::query()
            ->where('token', $tokenHash)
            ->where('tokenable_type', User::class)
            ->first();

        if ($personalToken && $personalToken->tokenable instanceof User) {
            return $personalToken->tokenable;
        }

        return User::where(function ($query) use ($normalizedToken, $tokenHash) {
            $query->where('bearer_token', $tokenHash)
                ->orWhere('bearer_token', $normalizedToken)
                ->orWhere('bearer_token', 'Bearer_' . $normalizedToken);
        })->first();
    }
}
