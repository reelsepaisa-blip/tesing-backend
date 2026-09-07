<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;


class SupportChatWebSocketController extends Controller
{
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

            if (str_starts_with($authHeader, 'Bearer_')) {
                $token = substr($authHeader, 7);
            } elseif (str_starts_with($authHeader, 'Bearer ')) {
                $token = substr($authHeader, 7);
            } else {
                $token = $authHeader;  // fallback if stored raw
            }

            $socketId = $request->input('socket_id');
            $channelName = $request->input('channel_name');

            $user = User::where('bearer_token', $token)->first();
            if (!$user) {
                return response()->json([
                    'error' => 'Invalid token'
                ], 401);
            }


            $channelPatterns = [
                '/^private-support\.user\.(\d+)$/',  // private-support.user.{user_id}
                '/^private-support\.admin\.(\d+)$/',  // private-support.admin.{admin_id}
                '/^private-support\.booking\.(\d+)$/',  // private-support.booking.{booking_id}
                '/^private-support\.admins$/',  // private-support.admins
            ];

            $validChannel = false;
            $channelType = '';
            $channelId = null;

            foreach ($channelPatterns as $pattern) {
                if (preg_match($pattern, $channelName, $matches)) {
                    $validChannel = true;
                    $channelType = $matches[1] ?? 'admins';
                    $channelId = $matches[1] ?? null;
                    break;
                }
            }

            if (!$validChannel) {
                return response()->json([
                    'error' => 'Invalid channel format. Expected: private-support.user.{id}, private-support.admin.{id}, private-support.booking.{id}, or private-support.admins'
                ], 400);
            }

            if (str_contains($channelName, 'private-support.user.')) {
                $userId = (int) $channelId;
                if ($user->id !== $userId) {
                    return response()->json([
                        'error' => 'Unauthorized to access this user channel'
                    ], 403);
                }
            } elseif (str_contains($channelName, 'private-support.admin.')) {
                if ($user->role_id !== 1) {
                    return response()->json([
                        'error' => 'Only admins can access admin channels'
                    ], 403);
                }
                $adminId = (int) $channelId;
                if ($user->id !== $adminId) {
                    return response()->json([
                        'error' => 'Unauthorized to access this admin channel'
                    ], 403);
                }
            } elseif (str_contains($channelName, 'private-support.booking.')) {
                $bookingId = (int) $channelId;
                $booking = \App\Models\Booking::find($bookingId);

                if (!$booking) {
                    return response()->json([
                        'error' => 'Booking not found'
                    ], 404);
                }
                

                $bookingUserId = (int) $booking->user_id;

                if ($user->id !== $bookingUserId && $user->role_id !== 1) {
                    return response()->json([
                        'error' => 'Unauthorized to access this booking channel'
                    ], 403);
                }
            } elseif ($channelName === 'private-support.admins') {
                if ($user->role_id !== 1) {
                    return response()->json([
                        'error' => 'Only admins can access the admins channel'
                    ], 403);
                }
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
                    'user_type' => $user->role_id === 1 ? 'admin' : 'user',
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Authentication failed'
            ], 500);
        }
    }

    public function handleDirectMessage(Request $request): JsonResponse
    {
        $request->validate([
            'event' => 'required|string',
            'data' => 'required|array',
            'channel' => 'required|string',
            'user_id' => 'required|integer',
        ]);

        try {
            $actor = $request->user();
            if (!$actor) {
                $actor = $this->resolveTokenUser($request);
            }

            if (!$actor) {
                return response()->json([
                    'success' => false,
                    'error' => 'User not authenticated'
                ], 401);
            }

            $event = $request->input('event');
            $data = $request->input('data');
            $channel = $request->input('channel');
            $requestedUserId = (int) $request->input('user_id');
            $isAdmin = (int) ($actor->role_id ?? 0) === 1;

            if (!$isAdmin && (int) $actor->id !== $requestedUserId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized user context'
                ], 403);
            }

            if (preg_match('/private-support\.booking\.(\d+)/', $channel, $matches)) {
                $bookingId = (int) $matches[1];
                $booking = \App\Models\Booking::find($bookingId);
                if (!$booking) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Booking not found'
                    ], 404);
                }

                if (!$isAdmin && (int) $booking->user_id !== (int) $actor->id && (int) $booking->driver_id !== (int) $actor->id) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Unauthorized access to this booking channel'
                    ], 403);
                }
                $targetUserId = null;  // We'll get user_id from booking
            } elseif (preg_match('/private-support\.user\.(\d+)/', $channel, $matches)) {
                $targetUserId = (int) $matches[1];
                if (!$isAdmin && (int) $actor->id !== $targetUserId) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Unauthorized access to this user channel'
                    ], 403);
                }
                $bookingId = null;
            } else {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid channel format for support chat. Use private-support.booking.{id} or private-support.user.{id}'
                ], 400);
            }

            switch ($event) {
                case 'client-send-message':
                    return $this->handleSendMessage($actor, $targetUserId, $data, $bookingId);

                case 'client-typing':
                    return $this->handleTypingStatus($actor, $targetUserId, $data, $bookingId);

                case 'client-mark-read':
                    return $this->handleMarkAsRead($actor, $targetUserId, $data, $bookingId);

                case 'client-get-messages':
                    return $this->handleGetMessages($actor, $targetUserId, $data, $bookingId);

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

    private function resolveTokenUser(Request $request): ?User
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader) {
            return null;
        }

        if (str_starts_with($authHeader, 'Bearer_')) {
            $token = substr($authHeader, 7);
        } elseif (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        } else {
            $token = $authHeader;
        }

        return User::where('bearer_token', $token)->first();
    }

    private function handleSendMessage(User $user, ?int $userId, array $data, ?int $bookingId = null): JsonResponse
    {
        $data = array_merge($data, [
            'message' => $data['message'] ?? '',
            'message_type' => $data['message_type'] ?? 'text',
            'metadata' => $data['metadata'] ?? [],
            'subject' => $data['subject'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
        ]);

        if (empty($data['message'])) {
            return response()->json([
                'success' => false,
                'error' => 'Message is required'
            ], 400);
        }

        if ($bookingId) {
            $booking = \App\Models\Booking::find($bookingId);
            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'error' => 'Booking not found'
                ], 404);
            }
            $targetUserId = $booking->user_id;
        } else {
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'error' => 'User ID or Booking ID is required'
                ], 400);
            }
            $targetUserId = $userId;
        }


        try {
            $supportChat = \App\Models\SupportChat::create([
                'user_id' => $targetUserId,
                'booking_id' => $bookingId,
                'admin_id' => $user->role_id === 1 ? $user->id : null,
                'sender_type' => $user->role_id === 1 ? 'admin' : 'user',
                'message' => $data['message'],
                'message_type' => $data['message_type'],
                'metadata' => $data['metadata'],
                'subject' => $data['subject'],
                'priority' => $data['priority'],
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

    private function handleTypingStatus(User $user, ?int $userId, array $data, ?int $bookingId = null): JsonResponse
    {
        $isTyping = $data['is_typing'] ?? false;

        event(new \App\Events\SupportTyping(
            User::find($userId),
            $user->role_id === 1 ? $user : null,
            $isTyping
        ));

        return response()->json([
            'success' => true,
            'event' => 'server-typing-status',
            'data' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'is_typing' => $isTyping,
                'channels' => [
                    'private-support.user.' . $userId,
                    'private-support.admin.' . $user->id,
                    'private-support.admins',
                ]
            ]
        ]);
    }

    private function handleMarkAsRead(User $user, ?int $userId, array $data, ?int $bookingId = null): JsonResponse
    {
        $messageIds = $data['message_ids'] ?? [];

        if ($bookingId) {
            $booking = \App\Models\Booking::find($bookingId);
            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'error' => 'Booking not found'
                ], 404);
            }
            $targetUserId = $booking->user_id;
        } else {
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'error' => 'User ID or Booking ID is required'
                ], 400);
            }
            $targetUserId = $userId;
        }

        $query = \App\Models\SupportChat::where('user_id', $targetUserId)
            ->where('is_read', false);
        if ($bookingId) {
            $query->where('booking_id', $bookingId);
        }

        if (!empty($messageIds)) {
            $query->whereIn('id', $messageIds);
        }

        $messageIdsToUpdate = $query->pluck('id')->toArray();

        $updatedCount = $query->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        if ($updatedCount > 0) {
            event(new \App\Events\SupportChatRead(
                User::find($userId),
                $user->role_id === 1 ? $user : null,
                $messageIdsToUpdate
            ));
        }

        return response()->json([
            'success' => true,
            'event' => 'server-messages-read',
            'data' => [
                'updated_count' => $updatedCount,
                'message_ids' => $messageIdsToUpdate,
                'channels' => [
                    'private-support.user.' . $targetUserId,
                    'private-support.admin.' . $user->id,
                    'private-support.admins',
                    $bookingId ? 'private-support.booking.' . $bookingId : null,
                ]
            ]
        ]);
    }

    private function handleGetMessages(User $user, ?int $userId, array $data, ?int $bookingId = null): JsonResponse
    {
        $perPage = $data['per_page'] ?? 50;
        $page = $data['page'] ?? 1;

        if ($bookingId) {
            $booking = \App\Models\Booking::find($bookingId);
            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'error' => 'Booking not found'
                ], 404);
            }
            $targetUserId = $booking->user_id;
        } else {
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'error' => 'User ID or Booking ID is required'
                ], 400);
            }
            $targetUserId = $userId;
        }

        $query = \App\Models\SupportChat::where('user_id', $targetUserId);
        if ($bookingId) {
            $query->where('booking_id', $bookingId);
        }

        $messages = $query
            ->with(['user', 'admin'])
            ->orderBy('created_at', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'event' => 'server-messages-list',
            'data' => [
                'messages' => $messages->map(function ($supportChat) {
                    return $this->formatSupportChatMessage($supportChat);
                }),
                'pagination' => [
                    'current_page' => $messages->currentPage(),
                    'last_page' => $messages->lastPage(),
                    'per_page' => $messages->perPage(),
                    'total' => $messages->total(),
                ],
                'user_id' => $userId,
            ]
        ]);
    }

    private function formatSupportChatMessage($supportChat): array
    {
        return [
            'id' => (string) $supportChat->id,
            'user_id' => (string) $supportChat->user_id,
            'admin_id' => $supportChat->admin_id ? (string) $supportChat->admin_id : null,
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
    }
}
