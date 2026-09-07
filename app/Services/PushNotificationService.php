<?php

namespace App\Services;

use App\Models\User;
use App\Models\Booking;
use Illuminate\Support\Facades\Http;

use Illuminate\Support\Facades\Cache;

class PushNotificationService
{
    protected $fcmUrl = 'https://fcm.googleapis.com/fcm/send';
    protected $serverKey;

    public function __construct()
    {
        $this->serverKey = config('services.fcm.server_key');
    }

    
    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        if (!$user->fcm_token) {

            return false;
        }

        return $this->sendToTokens([$user->fcm_token], $title, $body, $data);
    }

    
    public function sendToUsers(array $users, string $title, string $body, array $data = []): bool
    {
        $tokens = collect($users)
            ->pluck('fcm_token')
            ->filter()
            ->toArray();

        if (empty($tokens)) {

            return false;
        }

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    
    public function sendTripNotification(User $user, Booking $booking, string $type, array $additionalData = []): bool
    {
        $notifications = [
            'driver_assigned' => [
                'title' => 'Driver Assigned! 🚗',
                'body' => "Your driver " . $booking->driver->name . " is on the way. ETA: " . ($additionalData['eta'] ?? 'Calculating...') . " minutes"
            ],
            'driver_arrived' => [
                'title' => 'Driver Arrived! 🎯',
                'body' => "Your driver has arrived at pickup location. Please provide the trip code: " . $booking->trip_otp
            ],
            'trip_started' => [
                'title' => 'Trip Started! 🚀',
                'body' => "Your trip to " . $booking->drop_address . " has begun. Enjoy your ride!"
            ],
            'trip_completed' => [
                'title' => 'Trip Completed! ✅',
                'body' => "Your trip has ended. Total fare: ₹" . $booking->total_amount . ". Please rate your experience."
            ],
            'trip_cancelled' => [
                'title' => 'Trip Cancelled ❌',
                'body' => "Your trip has been cancelled. " . ($additionalData['reason'] ?? 'Please try booking again.')
            ],
            'payment_success' => [
                'title' => 'Payment Successful! 💳',
                'body' => "Payment of ₹" . $booking->total_amount . " has been processed successfully."
            ],
            'payment_failed' => [
                'title' => 'Payment Failed ❌',
                'body' => "Payment failed. Please try again or contact support."
            ]
        ];

        if (!isset($notifications[$type])) {

            return false;
        }

        $notification = $notifications[$type];

        $data = array_merge([
            'type' => 'trip_update',
            'booking_id' => $booking->id,
            'booking_code' => $booking->booking_code,
            'notification_type' => $type
        ], $additionalData);

        return $this->sendToUser($user, $notification['title'], $notification['body'], $data);
    }

    
    public function sendDriverNotification(User $driver, string $type, array $additionalData = []): bool
    {
        $notifications = [
            'new_booking' => [
                'title' => 'New Ride Request! 🚕',
                'body' => "You have a new ride request. Tap to view details."
            ],
            'booking_cancelled' => [
                'title' => 'Ride Cancelled ❌',
                'body' => "A ride has been cancelled. You can go back online."
            ],
            'payment_received' => [
                'title' => 'Payment Received! 💰',
                'body' => "You've received ₹" . ($additionalData['amount'] ?? 0) . " for your trip."
            ],
            'weekly_payout' => [
                'title' => 'Weekly Payout! 💳',
                'body' => "Your weekly payout of ₹" . ($additionalData['amount'] ?? 0) . " has been processed."
            ],
            'account_approved' => [
                'title' => 'Account Approved! ✅',
                'body' => "Your driver account has been approved. You can now go online!"
            ],
            'account_rejected' => [
                'title' => 'Account Update Required ❌',
                'body' => "Your driver account needs attention. Please check the app for details."
            ]
        ];

        if (!isset($notifications[$type])) {

            return false;
        }

        $notification = $notifications[$type];

        $data = array_merge([
            'type' => 'driver_update',
            'notification_type' => $type
        ], $additionalData);

        return $this->sendToUser($driver, $notification['title'], $notification['body'], $data);
    }

    
    public function sendNewDocumentNotification(User $driver, \App\Models\DocumentList $documentList, int $deadlineHours): bool
    {
        $title = 'New Document Required 📄';
        $body = "Please Upload New Document ({$documentList->name}) in {$deadlineHours} hours. If you do not upload it, your account will be blocked.";

        $data = [
            'type' => 'new_document_required',
            'document_list_id' => (string) $documentList->id,
            'document_name' => $documentList->name,
            'deadline_hours' => (string) $deadlineHours,
            'driver_id' => (string) $driver->id,
        ];

        try {
            \App\Models\Notification::create([
                'user_id' => $driver->id,
                'type' => 'new_document_required',
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'status' => 'pending',
                'is_sent' => false,
            ]);
        } catch (\Exception $e) {
            // Continue on error
        }

        if (!$driver->device_token) {

            return false;
        }

        try {
            $fcmService = app(FCMService::class);
            $result = $fcmService->sendToDevice($driver->device_token, [
                'title' => $title,
                'body' => $body,
                'icon' => 'ic_document',
                'sound' => 'default',
            ], $data);

            return $result['success'] ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }

    
    public function sendPromoNotification(User $user, string $title, string $body, array $data = []): bool
    {
        $data = array_merge([
            'type' => 'promo',
            'timestamp' => now()->timestamp
        ], $data);

        return $this->sendToUser($user, $title, $body, $data);
    }

    
    public function sendCityWideNotification(int $cityId, string $title, string $body, array $data = []): bool
    {
        $users = User::where('city_id', $cityId)
            ->whereNotNull('fcm_token')
            ->get();

        if ($users->isEmpty()) {

            return true;
        }

        $data = array_merge([
            'type' => 'city_wide',
            'city_id' => $cityId,
            'timestamp' => now()->timestamp
        ], $data);

        return $this->sendToUsers($users->toArray(), $title, $body, $data);
    }

    
    public function sendDriverCityNotification(int $cityId, string $title, string $body, array $data = []): bool
    {
        $drivers = User::whereHas('roles', function ($query) {
            $query->where('name', 'driver');
        })
            ->where('city_id', $cityId)
            ->where('is_online', true)
            ->whereNotNull('fcm_token')
            ->get();

        if ($drivers->isEmpty()) {

            return true;
        }

        $data = array_merge([
            'type' => 'driver_city_wide',
            'city_id' => $cityId,
            'timestamp' => now()->timestamp
        ], $data);

        return $this->sendToUsers($drivers->toArray(), $title, $body, $data);
    }

    
    protected function sendToTokens(array $tokens, string $title, string $body, array $data = []): bool
    {
        if (empty($tokens)) {
            return false;
        }

        $tokenChunks = array_chunk($tokens, 1000);
        $success = true;

        foreach ($tokenChunks as $chunk) {
            $payload = [
                'registration_ids' => $chunk,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'sound' => 'default',
                    'badge' => 1,
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
                ],
                'data' => $data,
                'priority' => 'high',
                'content_available' => true
            ];

            try {
                $response = Http::withHeaders([
                    'Authorization' => 'key=' . $this->serverKey,
                    'Content-Type' => 'application/json'
                ])->post($this->fcmUrl, $payload);

                if ($response->successful()) {
                    $result = $response->json();

                    if (isset($result['failure']) && $result['failure'] > 0) {
                        $this->handleFailedTokens($chunk, $result);
                    }

                    
                } else {
                    $success = false;
                }
            } catch (\Exception $e) {
                $success = false;
            }
        }

        return $success;
    }

    
    protected function handleFailedTokens(array $tokens, array $fcmResponse): void
    {
        if (!isset($fcmResponse['results'])) {
            return;
        }

        foreach ($fcmResponse['results'] as $index => $result) {
            if (isset($result['error'])) {
                $token = $tokens[$index];

                switch ($result['error']) {
                    case 'NotRegistered':
                    case 'InvalidRegistration':
                        $this->removeInvalidToken($token);
                        break;

                    case 'Unavailable':
                    case 'InternalServerError':
                        break;
                }
            }
        }
    }

    
    protected function removeInvalidToken(string $token): void
    {
        $user = User::where('fcm_token', $token)->first();

        if ($user) {
            $user->update(['fcm_token' => null]);

        }
    }

    
    public function updateUserToken(User $user, string $token): bool
    {
        try {
            $user->update(['fcm_token' => $token]);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    
    public function getNotificationStats(): array
    {
        $stats = Cache::remember('notification_stats', 3600, function () {
            return [
                'total_users_with_tokens' => User::whereNotNull('fcm_token')->count(),
                'total_drivers_with_tokens' => User::whereHas('roles', function ($query) {
                    $query->where('name', 'driver');
                })->whereNotNull('fcm_token')->count(),
                'last_24h_notifications' => 0, // This would be tracked in a separate table
                'success_rate' => 0.95 // This would be calculated from actual data
            ];
        });

        return $stats;
    }
}
