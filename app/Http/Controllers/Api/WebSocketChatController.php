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

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WebSocketChatController extends Controller
{
    protected $fcmService;

    public function __construct(FCMService $fcmService)
    {
        $this->fcmService = $fcmService;
    }


    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'message' => 'required|string|max:50000',
            'message_type' => 'nullable|in:text,image,location,system',
            'metadata' => 'nullable|array',
        ]);

        try {
            $user = Auth::user();
            $booking = Booking::find($request->booking_id);

            if (!$this->canUserAccessBookingChat($user, $booking)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized to access this chat'
                ], 403);
            }

            $receiverId = $this->getReceiverId($user, $booking);
            $senderType = $user->hasRole('driver') ? 'driver' : 'user';

            $chat = Chat::create([
                'booking_id' => $request->booking_id,
                'sender_id' => $user->id,
                'receiver_id' => $receiverId,
                'sender_type' => $senderType,
                'message' => $request->message,
                'message_type' => $request->message_type ?? 'text',
                'metadata' => $request->metadata,
            ]);

            $chat->load(['sender', 'receiver', 'booking']);

            event(new ChatMessage($chat));

            $this->sendChatNotification($chat);

            return response()->json([
                'success' => true,
                'message' => 'Message sent successfully',
                'chat' => $this->formatChatMessage($chat),
                'websocket_event' => 'chat.message',
                'channels' => [
                    'chat.booking.' . $booking->id,
                    'user.' . $receiverId,
                    'user.' . $user->id,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to send message'
            ], 500);
        }
    }


    public function getMessages(Request $request, Booking $booking): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$this->canUserAccessBookingChat($user, $booking)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized to access this chat'
                ], 403);
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
                'websocket_channel' => 'chat.booking.' . $booking->id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get messages'
            ], 500);
        }
    }


    public function markAsRead(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'message_ids' => 'nullable|array',
            'message_ids.*' => 'exists:chats,id',
        ]);

        try {
            $user = Auth::user();
            $booking = Booking::find($request->booking_id);

            if (!$this->canUserAccessBookingChat($user, $booking)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized to access this chat'
                ], 403);
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
                'websocket_event' => 'chat.message.read',
                'channels' => [
                    'chat.booking.' . $booking->id,
                    'user.' . $user->id,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to mark messages as read'
            ], 500);
        }
    }


    public function setTypingStatus(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'is_typing' => 'required|boolean',
        ]);

        try {
            $user = Auth::user();
            $booking = Booking::find($request->booking_id);

            if (!$this->canUserAccessBookingChat($user, $booking)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized to access this chat'
                ], 403);
            }

            event(new UserTyping($booking, $user, $request->is_typing));

            return response()->json([
                'success' => true,
                'message' => 'Typing status updated',
                'websocket_event' => 'user.typing',
                'channels' => [
                    'chat.booking.' . $booking->id,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update typing status'
            ], 500);
        }
    }


    public function getUnreadCount(Booking $booking): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$this->canUserAccessBookingChat($user, $booking)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized to access this chat'
                ], 403);
            }

            $unreadCount = Chat::forBooking($booking->id)
                ->where('receiver_id', $user->id)
                ->unread()
                ->count();

            return response()->json([
                'success' => true,
                'unread_count' => $unreadCount,
                'booking_id' => $booking->id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get unread count'
            ], 500);
        }
    }


    public function sendImage(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB max
            'caption' => 'nullable|string|max:500',
        ]);

        try {
            $user = Auth::user();
            $booking = Booking::find($request->booking_id);

            if (!$this->canUserAccessBookingChat($user, $booking)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized to access this chat'
                ], 403);
            }

            $imagePath = $request->file('image')->store('chat-images', 'public');
            $imageUrl = Storage::url($imagePath);

            $receiverId = $this->getReceiverId($user, $booking);
            $senderType = $user->hasRole('driver') ? 'driver' : 'user';

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

            $chat->load(['sender', 'receiver', 'booking']);

            event(new ChatMessage($chat));

            $this->sendChatNotification($chat);

            return response()->json([
                'success' => true,
                'message' => 'Image sent successfully',
                'chat' => $this->formatChatMessage($chat),
                'websocket_event' => 'chat.message',
                'channels' => [
                    'chat.booking.' . $booking->id,
                    'user.' . $receiverId,
                    'user.' . $user->id,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to send image'
            ], 500);
        }
    }


    public function sendLocation(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
        ]);

        try {
            $user = Auth::user();
            $booking = Booking::find($request->booking_id);

            if (!$this->canUserAccessBookingChat($user, $booking)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized to access this chat'
                ], 403);
            }

            $receiverId = $this->getReceiverId($user, $booking);
            $senderType = $user->hasRole('driver') ? 'driver' : 'user';

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

            $chat->load(['sender', 'receiver', 'booking']);

            event(new ChatMessage($chat));

            $this->sendChatNotification($chat);

            return response()->json([
                'success' => true,
                'message' => 'Location sent successfully',
                'chat' => $this->formatChatMessage($chat),
                'websocket_event' => 'chat.message',
                'channels' => [
                    'chat.booking.' . $booking->id,
                    'user.' . $receiverId,
                    'user.' . $user->id,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to send location'
            ], 500);
        }
    }


    public function getConnectionInfo(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            return response()->json([
                'success' => true,
                'connection_info' => [
                    'user_id' => $user->id,
                    'user_type' => $user->hasRole('driver') ? 'driver' : 'user',
                    'websocket_url' => config('app.websocket_url', 'ws://localhost:6001'),
                    'pusher_config' => [
                        'key' => config('broadcasting.connections.pusher.key'),
                        'cluster' => config('broadcasting.connections.pusher.options.cluster'),
                        'encrypted' => config('broadcasting.connections.pusher.options.encrypted'),
                        'host' => config('broadcasting.connections.pusher.options.host'),
                        'port' => config('broadcasting.connections.pusher.options.port'),
                        'scheme' => config('broadcasting.connections.pusher.options.scheme'),
                    ],
                    'chat_channels' => [
                        'user.' . $user->id,
                        'chat.booking.{booking_id}',
                    ],
                    'events' => [
                        'chat.message',
                        'chat.message.read',
                        'user.typing',
                    ],
                    'timestamp' => now()->timestamp
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get connection info'
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
                'msg_type' => $chat->message_type, // Changed from 'message_type' as it's reserved in FCM
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
        return $user->id === $booking->user_id || $user->id === $booking->driver_id;
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
}
