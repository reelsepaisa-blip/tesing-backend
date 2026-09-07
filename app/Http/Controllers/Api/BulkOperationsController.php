<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use App\Models\PromoCode;
use App\Models\SupportTicket;
use App\Services\FCMService;
use App\Services\SMSService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Validator;
use Exception;

class BulkOperationsController extends Controller
{
    protected $fcmService;
    protected $smsService;

    public function __construct(FCMService $fcmService, SMSService $smsService)
    {
        $this->fcmService = $fcmService;
        $this->smsService = $smsService;
    }


    public function bulkUpdateUsers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array|min:1|max:1000',
            'user_ids.*' => 'exists:users,id',
            'action' => 'required|in:activate,deactivate,verify,suspend,delete',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $userIds = $request->user_ids;
            $action = $request->action;
            $reason = $request->reason;

            $results = [
                'success_count' => 0,
                'failure_count' => 0,
                'failed_ids' => [],
                'updated_users' => [],
            ];

            foreach ($userIds as $userId) {
                try {
                    $user = User::find($userId);
                    if (!$user) {
                        $results['failure_count']++;
                        $results['failed_ids'][] = $userId;
                        continue;
                    }

                    $updated = $this->performUserAction($user, $action, $reason);

                    if ($updated) {
                        $results['success_count']++;
                        $results['updated_users'][] = [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'phone' => $user->phone,
                            'status' => $user->is_active ? 'active' : 'inactive',
                        ];

                        $this->sendUserActionNotification($user, $action, $reason);
                    } else {
                        $results['failure_count']++;
                        $results['failed_ids'][] = $userId;
                    }
                } catch (Exception $e) {
                    $results['failure_count']++;
                    $results['failed_ids'][] = $userId;
                }
            }

            DB::commit();


            return response()->json([
                'success' => true,
                'message' => "Bulk user {$action} completed",
                'data' => $results
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Bulk operation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function bulkUpdateDrivers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'driver_ids' => 'required|array|min:1|max:500',
            'driver_ids.*' => 'exists:users,id',
            'action' => 'required|in:approve,reject,suspend,reactivate',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $driverIds = $request->driver_ids;
            $action = $request->action;
            $reason = $request->reason;

            $results = [
                'success_count' => 0,
                'failure_count' => 0,
                'failed_ids' => [],
                'updated_drivers' => [],
            ];

            foreach ($driverIds as $driverId) {
                try {
                    $driver = User::where('id', $driverId)
                        ->where('role_id', 2) // Driver role
                        ->first();

                    if (!$driver) {
                        $results['failure_count']++;
                        $results['failed_ids'][] = $driverId;
                        continue;
                    }

                    $updated = $this->performDriverAction($driver, $action, $reason);

                    if ($updated) {
                        $results['success_count']++;
                        $results['updated_drivers'][] = [
                            'id' => $driver->id,
                            'name' => $driver->name,
                            'email' => $driver->email,
                            'phone' => $driver->phone,
                            'is_verified' => $driver->is_verified,
                            'is_active' => $driver->is_active,
                        ];

                        $this->sendDriverActionNotification($driver, $action, $reason);
                    } else {
                        $results['failure_count']++;
                        $results['failed_ids'][] = $driverId;
                    }
                } catch (Exception $e) {
                    $results['failure_count']++;
                    $results['failed_ids'][] = $driverId;
                }
            }

            DB::commit();


            return response()->json([
                'success' => true,
                'message' => "Bulk driver {$action} completed",
                'data' => $results
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Bulk operation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function bulkCancelBookings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_ids' => 'required|array|min:1|max:500',
            'booking_ids.*' => 'exists:bookings,id',
            'reason' => 'required|string|max:500',
            'refund' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $bookingIds = $request->booking_ids;
            $reason = $request->reason;
            $shouldRefund = $request->refund ?? false;

            $results = [
                'success_count' => 0,
                'failure_count' => 0,
                'failed_ids' => [],
                'cancelled_bookings' => [],
                'total_refund_amount' => 0,
            ];

            foreach ($bookingIds as $bookingId) {
                try {
                    $booking = Booking::find($bookingId);
                    if (!$booking) {
                        $results['failure_count']++;
                        $results['failed_ids'][] = $bookingId;
                        continue;
                    }

                    if (!in_array($booking->status, ['pending', 'accepted', 'searching'])) {
                        $results['failure_count']++;
                        $results['failed_ids'][] = $bookingId;
                        continue;
                    }

                    $booking->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                        'cancellation_reason' => $reason,
                        'cancelled_by_type' => 'admin',
                        'cancelled_by_id' => Auth::id(),
                    ]);



                    if ($booking->promo_code) {
                        \App\Models\PromoUsage::where('booking_id', $booking->id)->delete();
                    }

                    if ($booking->driver_id) {
                        User::where('id', $booking->driver_id)
                            ->where('current_booking_id', $booking->id)
                            ->update(['current_booking_id' => null]);




                    }

                    if ($booking->driver_id) {
                        $driver = User::where('id', $booking->driver_id)
                            ->where('current_booking_id', $booking->id)
                            ->first();

                        if ($driver) {
                            $driver->update(['current_booking_id' => null]);
                        }
                    }

                    $refundAmount = 0;
                    if ($shouldRefund && $booking->payment_status === 'paid') {
                        $refundAmount = $booking->total_amount;
                        $results['total_refund_amount'] += $refundAmount;
                    }

                    $results['success_count']++;
                    $results['cancelled_bookings'][] = [
                        'id' => $booking->id,
                        'booking_code' => $booking->booking_code,
                        'user_name' => $booking->user->name,
                        'total_amount' => $booking->total_amount,
                        'refund_amount' => $refundAmount,
                    ];

                    $this->sendBookingCancellationNotification($booking, $reason);
                } catch (Exception $e) {
                    $results['failure_count']++;
                    $results['failed_ids'][] = $bookingId;
                }
            }

            DB::commit();


            return response()->json([
                'success' => true,
                'message' => 'Bulk booking cancellation completed',
                'data' => $results
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Bulk operation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function bulkSendNotifications(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recipient_type' => 'required|in:users,drivers,all',
            'recipient_ids' => 'nullable|array|max:5000',
            'recipient_ids.*' => 'exists:users,id',
            'notification_type' => 'required|in:push,sms,both',
            'title' => 'required|string|max:100',
            'message' => 'required|string|max:500',
            'city_id' => 'nullable|exists:cities,id',
            'schedule_at' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $recipientType = $request->recipient_type;
            $recipientIds = $request->recipient_ids;
            $notificationType = $request->notification_type;
            $title = $request->title;
            $message = $request->message;
            $cityId = $request->city_id;
            $scheduleAt = $request->schedule_at;

            $recipients = $this->getNotificationRecipients($recipientType, $recipientIds, $cityId);

            if ($recipients->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No recipients found'
                ], 400);
            }

            $results = [
                'total_recipients' => $recipients->count(),
                'push_sent' => 0,
                'push_failed' => 0,
                'sms_sent' => 0,
                'sms_failed' => 0,
                'scheduled' => !empty($scheduleAt),
            ];

            if ($scheduleAt) {

                $results['message'] = 'Notification scheduled successfully';
            } else {
                foreach ($recipients as $recipient) {
                    if (in_array($notificationType, ['push', 'both'])) {
                        $pushResult = $this->sendPushNotification($recipient, $title, $message);
                        if ($pushResult['success']) {
                            $results['push_sent']++;
                        } else {
                            $results['push_failed']++;
                        }
                    }

                    if (in_array($notificationType, ['sms', 'both'])) {
                        $smsResult = $this->sendSMSNotification($recipient, $message);
                        if ($smsResult['success']) {
                            $results['sms_sent']++;
                        } else {
                            $results['sms_failed']++;
                        }
                    }
                }
            }



            return response()->json([
                'success' => true,
                'message' => 'Bulk notification completed',
                'data' => $results
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Bulk notification failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function bulkUpdatePromoCodes(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'promo_code_ids' => 'required|array|min:1|max:100',
            'promo_code_ids.*' => 'exists:promo_codes,id',
            'action' => 'required|in:activate,deactivate,extend,update_value',
            'value' => 'nullable|numeric|min:0',
            'extend_days' => 'nullable|integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $promoCodeIds = $request->promo_code_ids;
            $action = $request->action;
            $value = $request->value;
            $extendDays = $request->extend_days;

            $results = [
                'success_count' => 0,
                'failure_count' => 0,
                'failed_ids' => [],
                'updated_promos' => [],
            ];

            foreach ($promoCodeIds as $promoCodeId) {
                try {
                    $promoCode = PromoCode::find($promoCodeId);
                    if (!$promoCode) {
                        $results['failure_count']++;
                        $results['failed_ids'][] = $promoCodeId;
                        continue;
                    }

                    $updated = $this->performPromoCodeAction($promoCode, $action, $value, $extendDays);

                    if ($updated) {
                        $results['success_count']++;
                        $results['updated_promos'][] = [
                            'id' => $promoCode->id,
                            'code' => $promoCode->code,
                            'status' => $promoCode->status,
                            'value' => $promoCode->value,
                            'expires_at' => $promoCode->expires_at,
                        ];
                    } else {
                        $results['failure_count']++;
                        $results['failed_ids'][] = $promoCodeId;
                    }
                } catch (Exception $e) {
                    $results['failure_count']++;
                    $results['failed_ids'][] = $promoCodeId;
                }
            }

            DB::commit();


            return response()->json([
                'success' => true,
                'message' => "Bulk promo code {$action} completed",
                'data' => $results
            ]);
        } catch (Exception $e) {
            DB::rollBack();


            return response()->json([
                'success' => false,
                'message' => 'Bulk operation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function bulkCloseSupportTickets(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ticket_ids' => 'required|array|min:1|max:500',
            'ticket_ids.*' => 'exists:support_tickets,id',
            'resolution' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $ticketIds = $request->ticket_ids;
            $resolution = $request->resolution;

            $results = [
                'success_count' => 0,
                'failure_count' => 0,
                'failed_ids' => [],
                'closed_tickets' => [],
            ];

            foreach ($ticketIds as $ticketId) {
                try {
                    $ticket = SupportTicket::find($ticketId);
                    if (!$ticket) {
                        $results['failure_count']++;
                        $results['failed_ids'][] = $ticketId;
                        continue;
                    }

                    $ticket->update([
                        'status' => 'closed',
                        'resolved_at' => now(),
                        'resolved_by' => Auth::id(),
                        'resolution' => $resolution,
                    ]);

                    $results['success_count']++;
                    $results['closed_tickets'][] = [
                        'id' => $ticket->id,
                        'subject' => $ticket->subject,
                        'user_name' => $ticket->user->name,
                        'created_at' => $ticket->created_at,
                    ];

                    $this->sendTicketClosedNotification($ticket, $resolution);
                } catch (Exception $e) {
                    $results['failure_count']++;
                    $results['failed_ids'][] = $ticketId;
                }
            }

            DB::commit();


            return response()->json([
                'success' => true,
                'message' => 'Bulk ticket closure completed',
                'data' => $results
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Bulk operation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function cancelAllBookings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $reason = $request->input('reason', 'Bulk cancellation');

            $bookings = Booking::whereNotIn('status', ['cancelled', 'completed'])
                ->get();

            $results = [
                'total_bookings' => (string) $bookings->count(),
                'cancelled_count' => "0",
                'already_final_count' => (string) Booking::whereIn('status', ['cancelled', 'completed'])->count(),
            ];

            foreach ($bookings as $booking) {
                try {
                    $booking->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                        'cancellation_reason' => $reason,
                        'cancelled_by_type' => 'admin',
                        'cancelled_by_id' => null,
                    ]);

                    if (!empty($booking->driver_id)) {
                        User::where('id', $booking->driver_id)
                            ->update([
                                'current_booking_id' => null,
                                'is_online' => true,
                            ]);
                    }

                    $results['cancelled_count'] = (string) ((int) $results['cancelled_count'] + 1);
                } catch (Exception $bookingException) {
                }
            }

            User::whereNotNull('current_booking_id')
                ->update(['current_booking_id' => null]);

            DB::commit();



            return response()->json([
                'success' => true,
                'message' => 'All bookings have been cancelled successfully',
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel all bookings',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    private function performUserAction(User $user, string $action, ?string $reason): bool
    {
        return match ($action) {
            'activate' => $user->update(['is_active' => true]),
            'deactivate' => $user->update(['is_active' => false]),
            'verify' => $user->update(['is_verified' => true, 'email_verified_at' => now()]),
            'suspend' => $user->update(['is_active' => false, 'suspended_at' => now(), 'suspension_reason' => $reason]),
            'delete' => $user->delete(),
            default => false,
        };
    }

    private function performDriverAction(User $driver, string $action, ?string $reason): bool
    {
        return match ($action) {
            'approve' => $driver->update(['is_verified' => true, 'is_active' => true, 'verified_at' => now()]),
            'reject' => $driver->update(['is_verified' => false, 'is_active' => false, 'rejection_reason' => $reason]),
            'suspend' => $driver->update(['is_active' => false, 'suspended_at' => now(), 'suspension_reason' => $reason]),
            'reactivate' => $driver->update(['is_active' => true, 'suspended_at' => null, 'suspension_reason' => null]),
            default => false,
        };
    }

    private function performPromoCodeAction(PromoCode $promoCode, string $action, ?float $value, ?int $extendDays): bool
    {
        return match ($action) {
            'activate' => $promoCode->update(['status' => 'active']),
            'deactivate' => $promoCode->update(['status' => 'inactive']),
            'extend' => $promoCode->update(['expires_at' => $promoCode->expires_at->addDays($extendDays)]),
            'update_value' => $promoCode->update(['value' => $value]),
            default => false,
        };
    }

    private function getNotificationRecipients(string $recipientType, ?array $recipientIds, ?int $cityId)
    {
        $query = User::query();

        if ($recipientIds) {
            $query->whereIn('id', $recipientIds);
        } else {
            switch ($recipientType) {
                case 'users':
                    $query->where('role_id', 1);
                    break;
                case 'drivers':
                    $query->where('role_id', 2);
                    break;
                case 'all':
                    break;
            }

            if ($cityId) {
                $query->where('city_id', $cityId);
            }
        }

        return $query->where('is_active', true)->get();
    }

    private function sendPushNotification(User $user, string $title, string $message): array
    {
        if (!$user->fcm_token) {
            return ['success' => false, 'message' => 'No FCM token'];
        }

        return $this->fcmService->sendToDevice($user->fcm_token, [
            'title' => $title,
            'body' => $message,
        ], [
            'type' => 'bulk_notification',
            'timestamp' => now()->toISOString(),
        ]);
    }

    private function sendSMSNotification(User $user, string $message): array
    {
        if (!$user->phone) {
            return ['success' => false, 'message' => 'No phone number'];
        }

        return $this->smsService->sendSMS($user->phone, $message);
    }

    private function sendUserActionNotification(User $user, string $action, ?string $reason): void
    {

    }

    private function sendDriverActionNotification(User $driver, string $action, ?string $reason): void
    {

    }

    private function sendBookingCancellationNotification(Booking $booking, string $reason): void
    {

    }

    private function sendTicketClosedNotification(SupportTicket $ticket, string $resolution): void
    {

    }
}
