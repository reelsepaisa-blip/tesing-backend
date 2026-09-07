<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Booking;
use App\Models\SupportChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;

class SupportChatController extends Controller
{
    
    public function handleWebSocketMessage(Request $request): JsonResponse
    {
        try {
            $actor = $request->user();
            if (!$actor) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthenticated'
                ], 401);
            }

            $request->validate([
                'event' => 'required|string',
                'data' => 'required|array',
                'channel' => 'required|string',
            ]);

            $event = $request->input('event');
            $data = $request->input('data');
            $channel = $request->input('channel');

            if ($event !== 'client-send-message') {
                return response()->json([
                    'success' => false,
                    'error' => 'Only client-send-message events are supported'
                ], 400);
            }

            if (!preg_match('/private-support\.booking\.(\d+)/', $channel, $matches)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid channel format. Expected: private-support.booking.{id}'
                ], 400);
            }

            $bookingId = (int) $matches[1];
            $booking = Booking::find($bookingId);

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'error' => 'Booking not found'
                ], 404);
            }

            $canAccessBooking = (int) $booking->user_id === (int) $actor->id
                || (int) $booking->driver_id === (int) $actor->id
                || $this->isAdminUser($actor);

            if (!$canAccessBooking) {
                return response()->json([
                    'success' => false,
                    'error' => 'You are not allowed to send messages for this booking'
                ], 403);
            }

            $user = $booking->user;
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'User not found for this booking'
                ], 404);
            }

            $messageData = [
                'message' => $data['message'] ?? '',
                'message_type' => $data['message_type'] ?? 'text',
                'metadata' => $data['metadata'] ?? [],
                'subject' => $data['subject'] ?? 'Support Request',
                'priority' => $data['priority'] ?? 'medium',
            ];

            if (empty($messageData['message'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Message is required'
                ], 400);
            }


            $supportChat = SupportChat::create([
                'user_id' => $user->id,
                'booking_id' => $bookingId,
                'admin_id' => null, // Will be assigned when admin responds
                'sender_type' => 'user',
                'message' => $messageData['message'],
                'message_type' => $messageData['message_type'],
                'metadata' => $messageData['metadata'],
                'subject' => $messageData['subject'],
                'priority' => $messageData['priority'],
                'is_read' => false,
                'status' => 'open',
            ]);


            $supportChat->load(['user', 'admin']);

            event(new \App\Events\SupportChatMessage($supportChat));

            return response()->json([
                'success' => true,
                'message' => 'Support message saved successfully',
                'data' => [
                    'support_chat_id' => $supportChat->id,
                    'user_id' => $supportChat->user_id,
                    'booking_id' => $supportChat->booking_id,
                    'message' => $supportChat->message,
                    'created_at' => $supportChat->created_at->toISOString(),
                ]
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'error' => 'Failed to save support message: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function getMessages(Request $request, $bookingId): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthenticated'
                ], 401);
            }

            $booking = Booking::find($bookingId);
            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'error' => 'Booking not found'
                ], 404);
            }

            $canAccessBooking = (int) $booking->user_id === (int) $user->id
                || (int) $booking->driver_id === (int) $user->id
                || $this->isAdminUser($user);

            if (!$canAccessBooking) {
                return response()->json([
                    'success' => false,
                    'error' => 'You are not allowed to access these messages'
                ], 403);
            }

            $messages = SupportChat::where('booking_id', $bookingId)
                ->with(['user', 'admin'])
                ->orderBy('created_at', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'booking_id' => $bookingId,
                    'messages' => $messages->map(function ($message) {
                        return [
                            'id' => (string) $message->id,
                            'user_id' => (string) $message->user_id,
                            'admin_id' => $message->admin_id ? (string) $message->admin_id : '',
                            'message' => $message->message,
                            'message_type' => $message->message_type,
                            'metadata' => $message->metadata,
                            'is_read' => $message->is_read,
                            'status' => $message->status,
                            'subject' => $message->subject,
                            'priority' => $message->priority,
                            'sender_type' => $message->sender_type,
                            'created_at' => $message->created_at->toISOString(),
                            'updated_at' => $message->updated_at->toISOString(),
                            'read_at' => $message->read_at ? $message->read_at->toISOString() : '',
                        ];
                    }),
                    'total' => $messages->count()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get messages: ' . $e->getMessage()
            ], 500);
        }
    }

    private function isAdminUser(User $user): bool
    {
        if (method_exists($user, 'hasRole')) {
            try {
                if ($user->hasRole('admin')) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Fall back to legacy role_id check.
            }
        }

        return (int) ($user->role_id ?? 0) === 1;
    }
}
