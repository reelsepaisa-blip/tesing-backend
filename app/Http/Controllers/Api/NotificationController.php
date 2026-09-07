<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


class NotificationController extends Controller
{
    
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user() ?? "";
            $perPage = $request->input('per_page', 10);
            $type = $request->input('type') ?? "";
            $status = $request->input('status') ?? "";
            $isRead = $request->input('is_read') ?? "";

            $query = $user->notifications()->latest();

            if ($type) {
                $query->where('type', $type);
            }

            if ($status) {
                $query->where('status', $status);
            }

            if ($isRead !== null) {
                $query->where('is_read', (bool) $isRead);
            }

            $notifications = $query->paginate($perPage);

            $formattedNotifications = $notifications->getCollection()->map(function ($notification) {
                return $this->formatNotification($notification);
            });

            return response()->json([
                'success' => true,
                'message' => 'Notifications retrieved successfully',
                'data' => [
                    'notifications' => $formattedNotifications->values()->all(),
                    'pagination' => [
                        'current_page' => $notifications->currentPage(),
                        'last_page' => $notifications->lastPage(),
                        'per_page' => $notifications->perPage(),
                        'total' => $notifications->total(),
                        'has_more' => $notifications->hasMorePages()
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve notifications',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function unreadCount(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $count = $user->notifications()->unread()->count();

            return response()->json([
                'success' => true,
                'message' => 'Unread count retrieved successfully',
                'data' => [
                    'unread_count' => $count
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get unread count',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        try {
            $user = $request->user();

            if ($notification->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to notification'
                ], 403);
            }

            $notification->markAsRead();

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read',
                'data' => [
                    'notification_id' => $notification->id,
                    'is_read' => $notification->is_read,
                    'read_at' => $notification->read_at ? $notification->read_at->format('Y-m-d\TH:i:s.000000\Z') : ""
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark notification as read',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $updated = $user->notifications()->unread()->update([
                'is_read' => true,
                'read_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'All notifications marked as read',
                'data' => [
                    'updated_count' => $updated
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark all notifications as read',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function show(Request $request, Notification $notification): JsonResponse
    {
        try {
            $user = $request->user();

            if ($notification->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to notification'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'message' => 'Notification retrieved successfully',
                'data' => [
                    'notification' => $this->formatNotification($notification)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        try {
            $user = $request->user();

            if ($notification->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to notification'
                ], 403);
            }

            $notification->delete();

            return response()->json([
                'success' => true,
                'message' => 'Notification deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    private function formatNotification(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'user_id' => $notification->user_id,
            'type' => $notification->type ?? '',
            'title' => $notification->title ?? '',
            'body' => $notification->body ?? '',
            'data' => $notification->data ?? [],
            'is_read' => $notification->is_read ?? false,
            'read_at' => $notification->read_at ? $notification->read_at->format('Y-m-d H:i:s') : '',
            'is_sent' => $notification->is_sent ?? false,
            'sent_at' => $notification->sent_at ? $notification->sent_at->format('Y-m-d H:i:s') : '',
            'fcm_message_id' => $notification->fcm_message_id ?? '',
            'status' => $notification->status ?? '',
            'error_message' => $notification->error_message ?? '',
            'created_at' => $notification->created_at ? $notification->created_at->format('Y-m-d\TH:i:s.000000\Z') : '',
            'updated_at' => $notification->updated_at ? $notification->updated_at->format('Y-m-d\TH:i:s.000000\Z') : '',
        ];
    }

    
    public function stats(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $stats = [
                'total' => $user->notifications()->count(),
                'unread' => $user->notifications()->unread()->count(),
                'read' => $user->notifications()->read()->count(),
                'sent' => $user->notifications()->sent()->count(),
                'failed' => $user->notifications()->failed()->count(),
                'by_type' => $user->notifications()
                    ->selectRaw('type, COUNT(*) as count')
                    ->groupBy('type')
                    ->pluck('count', 'type')
                    ->toArray(),
                'recent' => $user->notifications()->recent(7)->count()
            ];

            return response()->json([
                'success' => true,
                'message' => 'Notification statistics retrieved successfully',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get notification statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
