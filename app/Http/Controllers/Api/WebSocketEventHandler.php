<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Booking;
use App\Models\SupportChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;

class WebSocketEventHandler extends Controller
{
    
    public function handleClientEvent(Request $request): JsonResponse
    {
        try {
            $event = $request->input('event');
            $data = $request->input('data');
            $channel = $request->input('channel');
            $socketId = $request->input('socket_id');


            switch ($event) {
                case 'client-send-message':
                    return $this->handleSendMessage($request, $data, $channel);

                case 'client-typing':
                    return $this->handleTypingStatus($request, $data, $channel);

                case 'client-mark-read':
                    return $this->handleMarkAsRead($request, $data, $channel);

                default:
                    return response()->json([
                        'success' => false,
                        'error' => 'Unknown event type'
                    ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to process WebSocket event'
            ], 500);
        }
    }

    
    private function handleSendMessage(Request $request, array $data, string $channel): JsonResponse
    {
        $user = $this->getUserFromRequest($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'User not authenticated'
            ], 401);
        }

        if (preg_match('/private-support\.booking\.(\d+)/', $channel, $matches)) {
            $bookingId = $matches[1];
            $booking = Booking::find($bookingId);

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'error' => 'Booking not found'
                ], 404);
            }

            $targetUserId = $booking->user_id;
        } elseif (preg_match('/private-support\.user\.(\d+)/', $channel, $matches)) {
            $targetUserId = $matches[1];
            $bookingId = null;
        } else {
            return response()->json([
                'success' => false,
                'error' => 'Invalid channel format'
            ], 400);
        }

        if ((int) $user->role_id !== 1 && (int) $targetUserId !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized access to this channel'
            ], 403);
        }

        $messageData = array_merge($data, [
            'message' => $data['message'] ?? '',
            'message_type' => $data['message_type'] ?? 'text',
            'metadata' => $data['metadata'] ?? [],
            'subject' => $data['subject'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
        ]);

        if (empty($messageData['message'])) {
            return response()->json([
                'success' => false,
                'error' => 'Message is required'
            ], 400);
        }


        try {
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

            return response()->json([
                'success' => true,
                'event' => 'server-message-sent',
                'data' => [
                    'support_chat' => $this->formatSupportChatMessage($supportChat),
                    'channels' => [
                        'private-support.user.' . $targetUserId,
                        'private-support.admin.' . $user->id,
                        'private-support.admins',
                        $bookingId ? 'private-support.booking.' . $bookingId : null,
                    ]
                ]
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'error' => 'Failed to save message: ' . $e->getMessage()
            ], 500);
        }
    }

    
    private function handleTypingStatus(Request $request, array $data, string $channel): JsonResponse
    {
        $user = $this->getUserFromRequest($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'User not authenticated'
            ], 401);
        }

        $isTyping = $data['is_typing'] ?? false;

        event(new \App\Events\SupportTyping(
            $user,
            $channel,
            $isTyping
        ));

        return response()->json([
            'success' => true,
            'event' => 'server-typing-status',
            'data' => [
                'user_id' => $user->id,
                'is_typing' => $isTyping,
                'channel' => $channel
            ]
        ]);
    }

    
    private function handleMarkAsRead(Request $request, array $data, string $channel): JsonResponse
    {
        $user = $this->getUserFromRequest($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'User not authenticated'
            ], 401);
        }

        $messageIds = $data['message_ids'] ?? [];

        if (preg_match('/private-support\.user\.(\d+)/', $channel, $matches)) {
            $targetUserId = $matches[1];
        } elseif (preg_match('/private-support\.booking\.(\d+)/', $channel, $matches)) {
            $booking = Booking::find($matches[1]);
            $targetUserId = $booking ? $booking->user_id : null;
        } else {
            return response()->json([
                'success' => false,
                'error' => 'Invalid channel format'
            ], 400);
        }

        if (!$targetUserId) {
            return response()->json([
                'success' => false,
                'error' => 'Target user not found'
            ], 404);
        }

        if ((int) $user->role_id !== 1 && (int) $targetUserId !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized access to this channel'
            ], 403);
        }

        $query = SupportChat::where('user_id', $targetUserId)
            ->where('is_read', false);

        if (!empty($messageIds)) {
            $query->whereIn('id', $messageIds);
        }

        $updatedCount = $query->update(['is_read' => true, 'read_at' => now()]);

        event(new \App\Events\SupportChatRead(
            $user,
            $targetUserId,
            $messageIds
        ));

        return response()->json([
            'success' => true,
            'event' => 'server-messages-read',
            'data' => [
                'updated_count' => $updatedCount,
                'message_ids' => $messageIds,
                'channels' => [
                    'private-support.user.' . $targetUserId,
                    'private-support.admin.' . $user->id,
                    'private-support.admins',
                ]
            ]
        ]);
    }

    
    private function getUserFromRequest(Request $request): ?User
    {
        $authHeader = $request->header('Authorization');
        if ($authHeader) {
            if (str_starts_with($authHeader, 'Bearer_')) {
                $token = substr($authHeader, 7);
            } elseif (str_starts_with($authHeader, 'Bearer ')) {
                $token = substr($authHeader, 7);
            } else {
                $token = $authHeader;
            }

            $user = User::where('bearer_token', $token)->first();
            if ($user) {
                return $user;
            }
        }

        $socketId = $request->input('socket_id');
        if ($socketId) {
            return null;
        }

        return null;
    }

    
    private function formatSupportChatMessage(SupportChat $supportChat): array
    {
        return [
            'id' => $supportChat->id,
            'user_id' => $supportChat->user_id,
            'booking_id' => $supportChat->booking_id,
            'admin_id' => $supportChat->admin_id,
            'message' => $supportChat->message,
            'message_type' => $supportChat->message_type,
            'metadata' => $supportChat->metadata,
            'is_read' => $supportChat->is_read,
            'status' => $supportChat->status,
            'subject' => $supportChat->subject,
            'priority' => $supportChat->priority,
            'sender' => [
                'id' => $supportChat->user->id,
                'name' => $supportChat->user->name,
                'phone' => $supportChat->user->phone,
                'profile_photo' => $supportChat->user->profile_photo,
                'sender_type' => $supportChat->sender_type,
            ],
            'created_at' => $supportChat->created_at,
            'updated_at' => $supportChat->updated_at,
            'read_at' => $supportChat->read_at,
        ];
    }
}
