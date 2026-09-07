<?php

namespace App\Http\Controllers\Api;

use App\Events\ChatMessage;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Chat;
use App\Models\SupportChat;
use App\Models\User;
use App\Services\FCMService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PusherWebhookController extends Controller
{
    protected $fcmService;

    public function __construct(FCMService $fcmService)
    {
        $this->fcmService = $fcmService;
    }

    public function handleWebhook(Request $request): JsonResponse
    {
        try {
            $signature = $request->header('X-Pusher-Signature');
            $body = $request->getContent();
            $secret = config('broadcasting.connections.pusher.secret');

            if (!$this->verifySignature($signature, $body, $secret)) {

                return response()->json(['error' => 'Invalid signature'], 401);
            }

            $data = $request->all();


            if (isset($data['events'])) {
                foreach ($data['events'] as $event) {
                    $this->handleWebhookEvent($event);
                }
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    private function handleWebhookEvent(array $event): void
    {
        $eventName = $event['name'] ?? '';
        $channel = $event['channel'] ?? '';
        $data = $event['data'] ?? [];
        $socketId = $event['socket_id'] ?? null;
        $clientEventName = $event['event'] ?? ''; // Get the actual client event name



        if ($eventName === 'client_event') {
            if (is_string($data)) {
                $parsedData = json_decode($data, true) ?? [];

                $data = $parsedData;
            }
            $this->handleClientEvent($channel, $data, $socketId, $clientEventName);
        }
    }

    private function handleClientEvent(string $channel, array $data, ?string $socketId = null, ?string $clientEventName = null): void
    {


        // Use the event name from webhook if provided, otherwise try to extract from data
        $event = $clientEventName ?? $data['event'] ?? '';

        // For location update, data is directly the location data, not wrapped
        $eventData = $data;

        // Check if data has nested structure (like message events)
        if (isset($data['message'])) {

            $this->handleSendMessageFromWebhook($channel, $eventData, $socketId);
            return;
        }

        // If data has nested 'data' field, use that
        if (isset($data['data']) && is_array($data['data'])) {
            $eventData = $data['data'];
        }



        switch ($event) {
            case 'client-send-message':
                $this->handleSendMessageFromWebhook($channel, $eventData, $socketId);
                break;

            case 'client-typing':
                $this->handleTypingFromWebhook($channel, $eventData);
                break;

            case 'client-mark-read':
                $this->handleMarkAsReadFromWebhook($channel, $eventData);
                break;

            case 'client-driver-location-update':
                $this->handleDriverLocationUpdateFromWebhook($channel, $eventData, $socketId);
                break;

            default:

                break;
        }
    }

    private function handleSendMessageFromWebhook(string $channel, array $data, ?string $socketId = null): void
    {
        try {
            $booking = null;
            $bookingId = null;
            $targetUserId = null;

            if (preg_match('/private-chat\.booking\.(\d+)/', $channel, $matches)) {
                $bookingId = $matches[1];
                $booking = Booking::find($bookingId);

                if (!$booking) {

                    return;
                }

                $messageData = array_merge($data, [
                    'message' => $data['message'] ?? '',
                    'message_type' => $data['message_type'] ?? 'text',
                    'metadata' => $data['metadata'] ?? [],
                ]);

                if (empty($messageData['message'])) {

                    return;
                }

                // Check for duplicate messages - same content within 10 seconds
                $existingMessage = Chat::where('booking_id', $bookingId)
                    ->where('message', $messageData['message'])
                    ->where('created_at', '>=', now()->subSeconds(10))
                    ->first();

                if ($existingMessage) {

                    return;
                }

                // Also check if there's a very recent message (within 2 seconds) to avoid race conditions
                $veryRecentMessage = Chat::where('booking_id', $bookingId)
                    ->where('created_at', '>=', now()->subSeconds(2))
                    ->orderBy('created_at', 'desc')
                    ->first();

                if ($veryRecentMessage && $veryRecentMessage->message === $messageData['message']) {

                    return;
                }

                $senderId = null;
                $senderType = null;

                // Try to identify sender from socket_id mapping
                if ($socketId) {
                    $cachedUserId = Cache::get("socket_user:{$socketId}");
                    if ($cachedUserId) {
                        $user = User::find($cachedUserId);
                        if ($user) {
                            $senderId = (int) $user->id;
                            $senderType = $user->isDriver() ? 'driver' : 'user';
                        }
                    }
                }

                // Fallback to explicit data if provided (useful for testing)
                if (!$senderId && isset($data['sender_id']) && isset($data['sender_type'])) {
                    $senderId = (int) $data['sender_id'];
                    $senderType = (string) $data['sender_type'];
                }

                if (!$senderId) {


                    // Fallback to heuristic if identification fails
                    $recentMessages = Chat::where('booking_id', $bookingId)
                        ->where('created_at', '>=', now()->subSeconds(60))
                        ->where('message', '!=', $messageData['message'])
                        ->orderBy('created_at', 'desc')
                        ->limit(5)
                        ->get();

                    if ($recentMessages->isNotEmpty()) {
                        $lastMessage = $recentMessages->first();
                        $lastSenderId = (int) $lastMessage->sender_id;

                        // Improved heuristic: If last sender was driver and user hasn't replied recently, assume driver
                        $userHasSentRecently = Chat::where('booking_id', $bookingId)
                            ->where('sender_id', (int) $booking->user_id)
                            ->where('created_at', '>=', now()->subMinutes(5))
                            ->exists();

                        if ($lastSenderId === (int) $booking->driver_id && !$userHasSentRecently) {
                            $senderId = (int) $booking->driver_id;
                            $senderType = 'driver';
                        } else {
                            $senderId = (int) $lastMessage->receiver_id;
                            $senderType = $senderId === (int) $booking->user_id ? 'user' : 'driver';
                        }
                    } else {
                        $senderId = (int) $booking->user_id;
                        $senderType = 'user';
                    }
                }

                // Set receiver as the opposite of sender
                // CRITICAL: Cast to (int) to ensure strict comparison works
                if ($senderId === (int) $booking->user_id) {
                    $receiverId = (int) $booking->driver_id;
                } else {
                    $receiverId = (int) $booking->user_id;
                }

                $lastMessageForLog = Chat::where('booking_id', $bookingId)->orderBy('created_at', 'desc')->first();  // Re-fetch for logging if needed

                $chat = Chat::create([
                    'booking_id' => $bookingId,
                    'sender_id' => $senderId,
                    'receiver_id' => $receiverId,
                    'sender_type' => $senderType,
                    'message' => $messageData['message'],
                    'message_type' => $messageData['message_type'],
                    'metadata' => $messageData['metadata'],
                ]);



                $chat->load(['sender', 'receiver', 'booking']);

                event(new ChatMessage($chat));



                // Send FCM notification
                $this->sendChatNotification($chat);

                return;
            }

            if (preg_match('/private-support\.booking\.(\d+)/', $channel, $matches)) {
                $bookingId = $matches[1];
                $booking = Booking::find($bookingId);

                if (!$booking) {

                    return;
                }

                $targetUserId = $booking->user_id;
            } elseif (preg_match('/private-support\.user\.(\d+)/', $channel, $matches)) {
                $targetUserId = $matches[1];
                $bookingId = null;
            } else {

                return;
            }

            if ($booking) {
                $user = $booking->user;
            } else {
                $user = User::find($targetUserId);
            }

            if (!$user) {

                return;
            }

            $messageData = array_merge($data, [
                'message' => $data['message'] ?? '',
                'message_type' => $data['message_type'] ?? 'text',
                'metadata' => $data['metadata'] ?? [],
                'subject' => $data['subject'] ?? null,
                'priority' => $data['priority'] ?? 'medium',
            ]);

            if (empty($messageData['message'])) {

                return;
            }


            $supportChat = SupportChat::create([
                'user_id' => $targetUserId,
                'booking_id' => $bookingId,
                'admin_id' => $user->role_id === 1 ? $user->id : null,
                'sender_type' => $user->role_id === 1 ? 'admin' : 'user',
                'message' => $messageData['message'],
                'message_type' => $messageData['message_type'],
                'metadata' => $messageData['metadata'],
                'subject' => $messageData['subject'],
                'priority' => $messageData['priority'],
            ]);


            $supportChat->load(['user', 'admin']);


            event(new \App\Events\SupportChatMessage($supportChat));
        } catch (\Exception $e) {
        }
    }

    private function handleTypingFromWebhook(string $channel, array $data): void {}

    private function handleMarkAsReadFromWebhook(string $channel, array $data): void {}

    private function handleDriverLocationUpdateFromWebhook(string $channel, array $data, ?string $socketId = null): void
    {
        try {


            // Extract booking_id from channel name: private-driver-location.booking.{booking_id}
            if (!preg_match('/private-driver-location\.booking\.(\d+)/', $channel, $matches)) {

                return;
            }

            $bookingId = (int) $matches[1];
            $booking = Booking::find($bookingId);

            if (!$booking) {

                return;
            }

            // Parse data - it might be a string JSON or already an array
            $locationData = $data;
            if (is_string($data)) {
                $locationData = json_decode($data, true) ?? [];
            }

            // Validate required fields
            if (!isset($locationData['booking_id']) || !isset($locationData['latitude']) || !isset($locationData['longitude'])) {

                return;
            }

            // Use the controller method to handle the update
            $controller = app(\App\Http\Controllers\Api\DriverLocationByBookingController::class);
            $request = \Illuminate\Http\Request::create('/', 'POST', $locationData);
            $response = $controller->handleLocationUpdate($request);
        } catch (\Exception $e) {
        }
    }

    private function getUserFromWebhook(array $data): ?User
    {
        $socketId = $data['socket_id'] ?? null;

        if ($socketId) {
            $user = User::where('role_id', 3)->first();  // Get first regular user
            if ($user) {
                return $user;
            }
        }

        return User::where('role_id', 3)->first();
    }

    private function verifySignature(string $signature, string $body, string $secret): bool
    {
        $expectedSignature = hash_hmac('sha256', $body, $secret);
        return hash_equals($signature, $expectedSignature);
    }

    /**
     * Send FCM notification for chat message
     */
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
                // Receiver has no FCM token
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
                // Notification sent successfully
            } else {
                // Notification failed
            }
        } catch (\Exception $e) {
            // Error handling
        }
    }

    /**
     * Get message preview for notification
     */
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
}
