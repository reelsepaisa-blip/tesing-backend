<?php

namespace App\Services;

use App\Models\User;
use App\Models\Booking;
use Exception;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Exception\MessagingException;

class FCMService
{
    protected $messaging;
    protected $enabled;

    public function __construct()
    {
        $this->enabled = config('services.fcm.enabled', true);

        if ($this->enabled) {
            try {
                $serviceAccountPath = config('services.fcm.service_account_path');

                if (!$serviceAccountPath || !file_exists($serviceAccountPath)) {
                    $this->enabled = false;
                    return;
                }

                $factory = (new Factory)
                    ->withServiceAccount($serviceAccountPath);

                $this->messaging = $factory->createMessaging();
            } catch (\Throwable $e) {
                $this->enabled = false;
            }
        }
    }


    protected function flattenDataForFCM(array $data): array
    {
        $flattened = [];
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $flattened[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $flattened[$key] = $value ? 'true' : 'false';
            } elseif (is_null($value)) {
                $flattened[$key] = '';
            } else {
                $flattened[$key] = (string) $value;
            }
        }
        return $flattened;
    }


    public function sendToDevice(string $fcmToken, array $notification, array $data = []): array
    {
        if (!$this->enabled || !$this->messaging) {
            return [
                'success' => false,
                'message' => 'FCM service is disabled or not initialized'
            ];
        }

        try {
            $flattenedData = $this->flattenDataForFCM($data);

            $message = CloudMessage::withTarget('token', $fcmToken)
                ->withNotification(
                    Notification::create(
                        $notification['title'],
                        $notification['body']
                    )
                )
                ->withData($flattenedData)
                ->withAndroidConfig(
                    AndroidConfig::fromArray([
                        'notification' => [
                            'icon' => $notification['icon'] ?? 'ic_notification',
                            'sound' => $notification['sound'] ?? 'default',
                            'click_action' => $notification['click_action'] ?? null,
                        ],
                        'priority' => 'high',
                        'ttl' => '3600s',
                    ])
                )
                ->withApnsConfig(
                    ApnsConfig::fromArray([
                        'payload' => [
                            'aps' => [
                                'sound' => $notification['sound'] ?? 'default',
                                'badge' => 1,
                            ],
                        ],
                    ])
                );

            $result = $this->messaging->send($message);


            return [
                'success' => true,
                'message' => 'Notification sent successfully',
                'message_id' => $result
            ];
        } catch (MessagingException $e) {
            return [
                'success' => false,
                'message' => 'Failed to send notification',
                'error' => $e->getMessage()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Notification sending failed',
                'error' => $e->getMessage()
            ];
        }
    }


    public function sendToMultipleDevices(array $fcmTokens, array $notification, array $data = []): array
    {
        if (!$this->enabled || !$this->messaging) {
            return [
                'success' => false,
                'message' => 'FCM service is disabled or not initialized'
            ];
        }

        try {
            $flattenedData = $this->flattenDataForFCM($data);

            $messages = [];
            foreach ($fcmTokens as $token) {
                $message = CloudMessage::withTarget('token', $token)
                    ->withNotification(
                        Notification::create(
                            $notification['title'],
                            $notification['body']
                        )
                    )
                    ->withData($flattenedData)
                    ->withAndroidConfig(
                        AndroidConfig::fromArray([
                            'notification' => [
                                'icon' => $notification['icon'] ?? 'ic_notification',
                                'sound' => $notification['sound'] ?? 'default',
                                'click_action' => $notification['click_action'] ?? null,
                            ],
                            'priority' => 'high',
                            'ttl' => '3600s',
                        ])
                    )
                    ->withApnsConfig(
                        ApnsConfig::fromArray([
                            'payload' => [
                                'aps' => [
                                    'sound' => $notification['sound'] ?? 'default',
                                    'badge' => 1,
                                ],
                            ],
                        ])
                    );

                $messages[] = $message;
            }

            $result = $this->messaging->sendAll($messages);


            return [
                'success' => true,
                'message' => 'Batch notification sent successfully',
                'success_count' => $result->successes()->count(),
                'failure_count' => $result->failures()->count(),
                'result' => $result
            ];
        } catch (MessagingException $e) {
            return [
                'success' => false,
                'message' => 'Failed to send batch notification',
                'error' => $e->getMessage()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Batch notification sending failed',
                'error' => $e->getMessage()
            ];
        }
    }


    public function sendToTopic(string $topic, array $notification, array $data = []): array
    {
        if (!$this->enabled || !$this->messaging) {
            return [
                'success' => false,
                'message' => 'FCM service is disabled or not initialized'
            ];
        }

        try {
            $flattenedData = $this->flattenDataForFCM($data);

            $message = CloudMessage::withTarget('topic', $topic)
                ->withNotification(
                    Notification::create(
                        $notification['title'],
                        $notification['body']
                    )
                )
                ->withData($flattenedData)
                ->withAndroidConfig(
                    AndroidConfig::fromArray([
                        'notification' => [
                            'icon' => $notification['icon'] ?? 'ic_notification',
                            'sound' => $notification['sound'] ?? 'default',
                        ],
                        'priority' => 'high',
                        'ttl' => '3600s',
                    ])
                )
                ->withApnsConfig(
                    ApnsConfig::fromArray([
                        'payload' => [
                            'aps' => [
                                'sound' => $notification['sound'] ?? 'default',
                                'badge' => 1,
                            ],
                        ],
                    ])
                );

            $result = $this->messaging->send($message);



            return [
                'success' => true,
                'message' => 'Topic notification sent successfully',
                'message_id' => $result
            ];
        } catch (MessagingException $e) {
            return [
                'success' => false,
                'message' => 'Failed to send topic notification',
                'error' => $e->getMessage()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Topic notification sending failed',
                'error' => $e->getMessage()
            ];
        }
    }


    public function sendBookingNotification(User $user, Booking $booking, string $type): array
    {
        if (!$user->fcm_token) {
            return [
                'success' => false,
                'message' => 'User FCM token not found'
            ];
        }

        $notification = $this->getBookingNotificationContent($booking, $type);
        $data = [
            'type' => 'booking',
            'booking_id' => (string) $booking->id,
            'booking_code' => $booking->booking_code,
            'notification_type' => $type,
        ];

        return $this->sendToDevice($user->fcm_token, $notification, $data);
    }


    public function sendTripNotification(User $user, Booking $booking, string $type): array
    {
        if (!$user->fcm_token) {
            return [
                'success' => false,
                'message' => 'User FCM token not found'
            ];
        }

        $notification = $this->getTripNotificationContent($booking, $type);
        $data = [
            'type' => 'trip',
            'booking_id' => (string) $booking->id,
            'booking_code' => $booking->booking_code,
            'notification_type' => $type,
        ];

        return $this->sendToDevice($user->fcm_token, $notification, $data);
    }


    public function sendDriverNotification(User $driver, Booking $booking, string $type): array
    {
        if (!$driver->fcm_token) {
            return [
                'success' => false,
                'message' => 'Driver FCM token not found'
            ];
        }

        $notification = $this->getDriverNotificationContent($booking, $type);
        $data = [
            'type' => 'driver',
            'booking_id' => (string) $booking->id,
            'booking_code' => $booking->booking_code,
            'notification_type' => $type,
            'pickup_address' => $booking->pickup_address,
            'dropoff_address' => $booking->dropoff_address,
            'estimated_fare' => (string) $booking->estimated_fare,
        ];

        return $this->sendToDevice($driver->fcm_token, $notification, $data);
    }


    public function sendPromoNotification(User $user, array $promoData): array
    {
        if (!$user->fcm_token) {
            return [
                'success' => false,
                'message' => 'User FCM token not found'
            ];
        }

        $notification = [
            'title' => $promoData['title'],
            'body' => $promoData['message'],
            'icon' => 'ic_promo',
            'sound' => 'default',
        ];

        $data = [
            'type' => 'promo',
            'promo_code' => $promoData['promo_code'] ?? '',
            'discount_amount' => $promoData['discount_amount'] ?? '',
        ];

        return $this->sendToDevice($user->fcm_token, $notification, $data);
    }


    protected function getBookingNotificationContent(Booking $booking, string $type): array
    {
        return match ($type) {
            'booking_confirmed' => [
                'title' => 'Booking Confirmed!',
                'body' => "Your ride #{$booking->booking_code} is Created. Looking for nearby drivers",
                'icon' => 'ic_booking_confirmed',
                'sound' => 'booking_confirmed.mp3',
            ],
            'driver_assigned' => [
                'title' => 'Driver Found!',
                'body' => "Driver {$booking->driver->name} is coming to pick you up.",
                'icon' => 'ic_driver_assigned',
                'sound' => 'driver_assigned.mp3',
            ],
            'driver_arrived' => [
                'title' => 'Driver Arrived',
                'body' => "Your driver has arrived at the pickup location.",
                'icon' => 'ic_driver_arrived',
                'sound' => 'driver_arrived.mp3',
            ],
            'trip_started' => [
                'title' => 'Trip Started',
                'body' => "Your trip has started. Enjoy your ride!",
                'icon' => 'ic_trip_started',
                'sound' => 'trip_started.mp3',
            ],
            'trip_completed' => [
                'title' => 'Trip Completed',
                'body' => "You have reached your destination. Please rate your ride.",
                'icon' => 'ic_trip_completed',
                'sound' => 'trip_completed.mp3',
            ],
            'booking_cancelled' => [
                'title' => 'Booking Cancelled',
                'body' => "Your ride #{$booking->booking_code} has been cancelled.",
                'icon' => 'ic_booking_cancelled',
                'sound' => 'booking_cancelled.mp3',
            ],
            default => [
                'title' => 'eTaxi Update',
                'body' => "Your booking #{$booking->booking_code} has been updated.",
                'icon' => 'ic_notification',
                'sound' => 'default',
            ],
        };
    }


    protected function getTripNotificationContent(Booking $booking, string $type): array
    {
        return match ($type) {
            'payment_successful' => [
                'title' => 'Payment Successful',
                'body' => "Payment of ₹{$booking->total_amount} has been processed successfully.",
                'icon' => 'ic_payment_success',
                'sound' => 'payment_success.mp3',
            ],
            'payment_failed' => [
                'title' => 'Payment Failed',
                'body' => "Payment failed. Please try again or contact support.",
                'icon' => 'ic_payment_failed',
                'sound' => 'payment_failed.mp3',
            ],
            'refund_processed' => [
                'title' => 'Refund Processed',
                'body' => "Your refund has been processed and will reflect in 3-5 business days.",
                'icon' => 'ic_refund',
                'sound' => 'refund_processed.mp3',
            ],
            default => [
                'title' => 'Trip Update',
                'body' => "Your trip #{$booking->booking_code} has been updated.",
                'icon' => 'ic_notification',
                'sound' => 'default',
            ],
        };
    }


    protected function getDriverNotificationContent(Booking $booking, string $type): array
    {
        return match ($type) {
            'new_booking' => [
                'title' => 'New Ride Request',
                'body' => "New ride request nearby. Fare: ₹{$booking->estimated_fare}",
                'icon' => 'ic_new_booking',
                'sound' => 'new_booking.mp3',
            ],
            'booking_accepted' => [
                'title' => 'Ride Accepted',
                'body' => "You have accepted the ride request. Navigate to pickup location.",
                'icon' => 'ic_booking_accepted',
                'sound' => 'booking_accepted.mp3',
            ],
            'payment_received' => [
                'title' => 'Payment Received',
                'body' => "Payment of ₹{$booking->driver_amount} has been added to your wallet.",
                'icon' => 'ic_payment_received',
                'sound' => 'payment_received.mp3',
            ],
            default => [
                'title' => 'eTaxi Driver',
                'body' => "You have a new update for booking #{$booking->booking_code}.",
                'icon' => 'ic_notification',
                'sound' => 'default',
            ],
        };
    }


    public function subscribeToTopic(string $fcmToken, string $topic): array
    {
        if (!$this->enabled || !$this->messaging) {
            return [
                'success' => false,
                'message' => 'FCM service is disabled or not initialized'
            ];
        }

        try {

            return [
                'success' => true,
                'message' => 'Topic subscription should be handled on client side'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Topic subscription failed',
                'error' => $e->getMessage()
            ];
        }
    }


    public function unsubscribeFromTopic(string $fcmToken, string $topic): array
    {
        if (!$this->enabled || !$this->messaging) {
            return [
                'success' => false,
                'message' => 'FCM service is disabled or not initialized'
            ];
        }

        try {

            return [
                'success' => true,
                'message' => 'Topic unsubscription should be handled on client side'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Topic unsubscription failed',
                'error' => $e->getMessage()
            ];
        }
    }
}
