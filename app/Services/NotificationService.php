<?php

namespace App\Services;

use App\Models\User;
use App\Models\Booking;
use App\Models\Document;
use App\Models\Notification;
use App\Events\BookingStatusChanged;
use App\Events\NewBooking;


class NotificationService
{
    protected $fcmService;

    public function __construct(FCMService $fcmService)
    {
        $this->fcmService = $fcmService;
    }


    public function sendDocumentNotification(User $driver, Document $document, string $status): void
    {
        $notificationRecord = null;
        $fcmSent = false;
        $fcmMessageId = null;

        try {
            $notification = $this->getDocumentNotificationContent($document, $status);
            $data = [
                'type' => 'document_status',
                'document_id' => (string) $document->id,
                'document_type' => $document->type,
                'status' => $status,
                'user_id' => (string) $driver->id,
            ];

            $notificationRecord = Notification::create([
                'user_id' => $driver->id,
                'type' => 'document_status',
                'title' => $notification['title'],
                'body' => $notification['body'],
                'icon' => $notification['icon'] ?? null,
                'sound' => $notification['sound'] ?? null,
                'data' => $data,
                'status' => 'pending',
                'is_sent' => false,
            ]);

            if ($driver->device_token) {
                $fcmResult = $this->fcmService->sendToDevice($driver->device_token, $notification, $data);
                $fcmSent = $fcmResult['success'] ?? false;
                $fcmMessageId = is_array($fcmResult['message_id'] ?? null)
                    ? json_encode($fcmResult['message_id'])
                    : ($fcmResult['message_id'] ?? null);

                if ($fcmSent && $notificationRecord) {
                    $notificationRecord->markAsSent($fcmMessageId ? (string)$fcmMessageId : null);
                } else {
                    $errorMessage = $fcmResult['error'] ?? $fcmResult['message'] ?? 'FCM send failed';
                    if ($notificationRecord) {
                        $notificationRecord->markAsFailed($errorMessage);
                    }
                }
            } else {
                if ($notificationRecord) {
                    $notificationRecord->markAsFailed('Driver device token not found');
                }
            }
        } catch (\Exception $e) {
            if ($notificationRecord) {
                $notificationRecord->markAsFailed($e->getMessage());
            }
        }
    }


    public function sendBookingNotificationToUser(Booking $booking, string $status, string $message, array $data = []): void
    {
        $logPrefix = $status === 'trip_completed' ? 'Noti_Complete_User' : 'Noti_123';

        $notificationRecord = null;
        $fcmSent = false;
        $fcmMessageId = null;

        try {
            $notification = $this->getBookingNotificationContent($booking, $status);

            $payloadData = $this->buildFullPayloadData($booking, [
                'event_type' => 'booking_status_changed',
                'status' => $status,
                'timestamp' => now()->toISOString(),
            ], $data);
            $fullFcmData = [
                'type' => 'booking_status',
                'data' => $payloadData,
            ];

            $minimalFcmData = $this->buildMinimalFCMPayload($booking, 'booking_status', $status);

            $notificationRecord = Notification::create([
                'user_id' => $booking->user_id,
                'type' => 'booking_status',
                'title' => $notification['title'],
                'body' => $notification['body'],
                'icon' => $notification['icon'] ?? null,
                'sound' => $notification['sound'] ?? null,
                'data' => $fullFcmData,
                'status' => 'pending',
                'is_sent' => false,
            ]);

            if ($booking->user && $booking->user->device_token) {
                $fcmResult = $this->fcmService->sendToDevice($booking->user->device_token, $notification, $minimalFcmData);
                $fcmSent = $fcmResult['success'] ?? false;
                $fcmMessageId = is_array($fcmResult['message_id'] ?? null)
                    ? json_encode($fcmResult['message_id'])
                    : ($fcmResult['message_id'] ?? null);

                if ($fcmSent && $notificationRecord) {
                    $notificationRecord->markAsSent($fcmMessageId ? (string)$fcmMessageId : null);
                } else {
                    $errorMessage = $fcmResult['error'] ?? $fcmResult['message'] ?? 'FCM send failed';
                    if ($notificationRecord) {
                        $notificationRecord->markAsFailed($errorMessage);
                    }
                }
            } else {
                if ($notificationRecord) {
                    $notificationRecord->markAsFailed('User device token not found');
                }
            }

            event(new BookingStatusChanged($booking, $status, $message, $data));
        } catch (\Exception $e) {
            if ($notificationRecord) {
                $notificationRecord->markAsFailed($e->getMessage());
            }
        }
    }


    public function sendBookingNotificationToDriver(Booking $booking, string $status, string $message, array $data = []): void
    {
        $notificationRecord = null;
        $fcmSent = false;
        $fcmMessageId = null;

        try {
            if (!$booking->driver) {

                return;
            }

            // Skip notification if driver is not online
            if (!$booking->driver->is_online || $booking->driver->is_online == 0) {

                return;
            }

            $notification = $this->getDriverBookingNotificationContent($booking, $status);

            $payloadData = $this->buildFullPayloadData($booking, [
                'event_type' => 'booking_status_changed',
                'status' => $status,
                'timestamp' => now()->toISOString(),
            ], $data);
            $fullFcmData = [
                'type' => 'driver_booking_status',
                'data' => $payloadData,
            ];

            $minimalFcmData = $this->buildMinimalFCMPayload($booking, 'driver_booking_status', $status, $booking->driver_id);

            $notificationRecord = Notification::create([
                'user_id' => $booking->driver_id,
                'type' => 'driver_booking_status',
                'title' => $notification['title'],
                'body' => $notification['body'],
                'icon' => $notification['icon'] ?? null,
                'sound' => $notification['sound'] ?? null,
                'data' => $fullFcmData,
                'status' => 'pending',
                'is_sent' => false,
            ]);

            if ($booking->driver->device_token) {
                $fcmResult = $this->fcmService->sendToDevice($booking->driver->device_token, $notification, $minimalFcmData);
                $fcmSent = $fcmResult['success'] ?? false;
                $fcmMessageId = is_array($fcmResult['message_id'] ?? null)
                    ? json_encode($fcmResult['message_id'])
                    : ($fcmResult['message_id'] ?? null);

                if ($fcmSent && $notificationRecord) {
                    $notificationRecord->markAsSent($fcmMessageId ? (string)$fcmMessageId : null);
                } else {
                    $errorMessage = $fcmResult['error'] ?? $fcmResult['message'] ?? 'FCM send failed';
                    if ($notificationRecord) {
                        $notificationRecord->markAsFailed($errorMessage);
                    }
                }
            } else {
                if ($notificationRecord) {
                    $notificationRecord->markAsFailed('Driver device token not found');
                }
            }

            event(new BookingStatusChanged($booking, $status, $message, $data));
        } catch (\Exception $e) {
            if ($notificationRecord) {
                $notificationRecord->markAsFailed($e->getMessage());
            }
        }
    }


    public function sendDriverLocationUpdate(Booking $booking, float $latitude, float $longitude): void
    {
        try {
            $data = [
                'type' => 'driver_location_update',
                'booking_id' => (string) $booking->id,
                'booking_code' => $booking->booking_code,
                'latitude' => (string) $latitude,
                'longitude' => (string) $longitude,
                'timestamp' => now()->toISOString(),
            ];

            event(new BookingStatusChanged($booking, 'location_updated', 'Driver location updated', $data));
        } catch (\Exception $e) {
        }
    }


    protected function getDocumentNotificationContent(Document $document, string $status): array
    {
        return match ($status) {
            'approved' => [
                'title' => 'Document Approved!',
                'body' => "Your {$document->type} document has been approved. You can now accept bookings.",
                'icon' => 'ic_document_approved',
                'sound' => 'document_approved.mp3',
            ],
            'rejected' => [
                'title' => 'Document Rejected',
                'body' => "Your {$document->type} document has been rejected. Please upload a valid document.",
                'icon' => 'ic_document_rejected',
                'sound' => 'document_rejected.mp3',
            ],
            'pending' => [
                'title' => 'Document Under Review',
                'body' => "Your {$document->type} document is under review. We'll notify you once it's processed.",
                'icon' => 'ic_document_pending',
                'sound' => 'document_pending.mp3',
            ],
            default => [
                'title' => 'Document Status Update',
                'body' => "Your {$document->type} document status has been updated.",
                'icon' => 'ic_document',
                'sound' => 'default',
            ],
        };
    }


    protected function getBookingNotificationContent(Booking $booking, string $status): array
    {
        return match ($status) {
            'booking_confirmed' => [
                'title' => 'Booking Confirmed!',
                'body' => "Your ride #{$booking->booking_code} has been confirmed. Looking for nearby drivers...",
                'icon' => 'ic_booking_confirmed',
                'sound' => 'booking_confirmed.mp3',
            ],
            'driver_assigned' => [
                'title' => 'Driver Found!',
                'body' => "Driver {$booking->driver->name} is coming to pick you up.",
                'icon' => 'ic_driver_assigned',
                'sound' => 'driver_assigned.mp3',
            ],
            'driver_accepted' => [
                'title' => 'Driver Accepted',
                'body' => "Your driver is on the way to pickup location.",
                'icon' => 'ic_driver_accepted',
                'sound' => 'driver_accepted.mp3',
            ],
            'driver_arrived' => [
                'title' => 'Driver Arrived',
                'body' => "Your driver has arrived. Please provide otp {$booking->otp}",
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
                'body' => "You have reached your destination. Please rate your ridesss.",
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
                'title' => 'Booking Update',
                'body' => "Your booking #{$booking->booking_code} has been updated.",
                'icon' => 'ic_notification',
                'sound' => 'default',
            ],
        };
    }


    protected function getDriverBookingNotificationContent(Booking $booking, string $status): array
    {
        return match ($status) {
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
            'user_cancelled' => [
                'title' => 'Ride Cancelled',
                'body' => "The user has cancelled the ride request.",
                'icon' => 'ic_booking_cancelled',
                'sound' => 'booking_cancelled.mp3',
            ],
            'payment_received' => [
                'title' => 'Payment Received',
                'body' => "Payment of ₹{$booking->driver_amount} has been added to your wallet.",
                'icon' => 'ic_payment_received',
                'sound' => 'payment_received.mp3',
            ],
            default => [
                'title' => 'Booking Update',
                'body' => "You have a new update for booking #{$booking->booking_code}.",
                'icon' => 'ic_notification',
                'sound' => 'default',
            ],
        };
    }


    public function driverAssigned(Booking $booking): void
    {
        try {
            $this->sendBookingNotificationToUser($booking, 'driver_assigned', "Your driver {$booking->driver->name} is on the way. Trip code: {$booking->trip_code}");

            $this->sendBookingNotificationToDriver($booking, 'booking_accepted', "You have a new booking to {$booking->dropoff_address}");
        } catch (\Exception $e) {
        }
    }


    public function driverArrived(Booking $booking): void
    {
        try {
            $this->sendBookingNotificationToUser($booking, 'driver_arrived', "Your driver has arrived. Please provide otp {$booking->otp}");
        } catch (\Exception $e) {
        }
    }


    public function tripStarted(Booking $booking): void
    {
        try {
            $this->sendBookingNotificationToUser($booking, 'trip_started', "Your trip has started. Enjoy your ride!");
        } catch (\Exception $e) {
        }
    }


    public function tripCompleted(Booking $booking): void
    {
        try {
            $booking->refresh();

            $this->sendBookingNotificationToUser(
                $booking,
                'trip_completed',
                "You have reached your destination. Please rate your rideaaa."
            );

            if ($booking->driver) {
                // Skip notification if driver is not online
                if (!$booking->driver->is_online || $booking->driver->is_online == 0) {
                } else {
                    $driverAmount = $booking->driver_amount ?? '0';
                    $notification = [
                        'title' => 'Ride Completed',
                        'body' => "The Ride Is Completed Please Wait for Customer Payment",
                        'icon' => 'ic_payment_received',
                        'sound' => 'payment_received.mp3',
                    ];

                    $fcmData = [
                        'type' => 'trip_completed',
                        'booking_id' => (string) $booking->id,
                        'booking_code' => $booking->booking_code ?? '',
                        'driver_amount' => (string) $driverAmount,
                        'status' => 'completed',
                    ];

                    $notificationRecord = Notification::create([
                        'user_id' => $booking->driver_id,
                        'type' => 'trip_completed',
                        'title' => $notification['title'],
                        'body' => $notification['body'],
                        'icon' => $notification['icon'],
                        'sound' => $notification['sound'],
                        'data' => $fcmData,
                        'status' => 'pending',
                        'is_sent' => false,
                    ]);

                    if ($booking->driver->device_token) {
                        $fcmResult = $this->fcmService->sendToDevice($booking->driver->device_token, $notification, $fcmData);
                        $fcmSent = $fcmResult['success'] ?? false;
                        $fcmMessageId = is_array($fcmResult['message_id'] ?? null)
                            ? json_encode($fcmResult['message_id'])
                            : ($fcmResult['message_id'] ?? null);

                        if ($fcmSent && $notificationRecord) {
                            $notificationRecord->markAsSent($fcmMessageId ? (string)$fcmMessageId : null);
                        } else {
                            $errorMessage = $fcmResult['error'] ?? $fcmResult['message'] ?? 'FCM send failed';
                            if ($notificationRecord) {
                                $notificationRecord->markAsFailed($errorMessage);
                            }
                        }
                    } else {
                        if ($notificationRecord) {
                            $notificationRecord->markAsFailed('Driver device token not found');
                        }
                    }
                }
            }
        } catch (\Exception $e) {
        }
    }


    public function sendPaymentCompletionNotificationToDriver(Booking $booking): void
    {
        $notificationRecord = null;
        $fcmSent = false;
        $fcmMessageId = null;

        try {
            if (!$booking->driver) {

                return;
            }

            // Skip notification if driver is not online
            if (!$booking->driver->is_online || $booking->driver->is_online == 0) {

                return;
            }

            $refundRequiredHours = \App\Models\SystemConfiguration::getValue('refund_required_hours', 48);
            $refundRequiredHours = (int) $refundRequiredHours;

            $paymentMethod = $booking->payment_method ?? 'cash';
            $paymentMethodText = match ($paymentMethod) {
                'cash' => 'Cash',
                'wallet' => 'Wallet',
                'card', 'upi', 'netbanking', 'paypal', 'razorpay' => 'Online',
                default => 'Online'
            };
            $amount = $booking->total_amount ?? $booking->final_fare ?? 0;

            $notification = [
                'title' => 'Payment Received',
                'body' => "Payment of ₹{$amount} received via {$paymentMethodText}. Refund window: {$refundRequiredHours} hours.",
                'icon' => 'ic_payment_received',
                'sound' => 'payment_received.mp3',
            ];

            $fcmData = [
                'type' => 'payment_completed',
                'booking_id' => (string) $booking->id,
                'booking_code' => $booking->booking_code ?? '',
                'payment_method' => $paymentMethod,
                'amount' => (string) $amount,
                'refund_required_hours' => (string) $refundRequiredHours,
                'timestamp' => now()->toISOString(),
            ];

            $notificationRecord = Notification::create([
                'user_id' => $booking->driver_id,
                'type' => 'payment_completed',
                'title' => $notification['title'],
                'body' => $notification['body'],
                'icon' => $notification['icon'],
                'sound' => $notification['sound'],
                'data' => [
                    'type' => 'payment_completed',
                    'data' => $fcmData,
                ],
                'status' => 'pending',
                'is_sent' => false,
            ]);

            if ($booking->driver->device_token) {
                $fcmResult = $this->fcmService->sendToDevice($booking->driver->device_token, $notification, $fcmData);
                $fcmSent = $fcmResult['success'] ?? false;
                $fcmMessageId = is_array($fcmResult['message_id'] ?? null)
                    ? json_encode($fcmResult['message_id'])
                    : ($fcmResult['message_id'] ?? null);

                if ($fcmSent && $notificationRecord) {
                    $notificationRecord->markAsSent($fcmMessageId ? (string)$fcmMessageId : null);
                } else {
                    $errorMessage = $fcmResult['error'] ?? $fcmResult['message'] ?? 'FCM send failed';
                    if ($notificationRecord) {
                        $notificationRecord->markAsFailed($errorMessage);
                    }
                }
            } else {
                if ($notificationRecord) {
                    $notificationRecord->markAsFailed('Driver device token not found');
                }
            }
        } catch (\Exception $e) {
        }
    }


    public function sendLocationConfirmationNotificationToDriver(Booking $booking): void
    {
        $notificationRecord = null;
        $fcmSent = false;
        $fcmMessageId = null;

        try {

            $notification = [
                'title' => 'New Ride Request',
                'body' => "New ride request confirmed. Pickup: {$booking->pickup_address}",
                'icon' => 'ic_new_booking',
                'sound' => 'new_booking.mp3',
            ];

            $fcmData = [
                'type' => 'location_confirmed',
                'booking_id' => (string) $booking->id,
                'booking_code' => $booking->booking_code ?? '',
                'pickup_address' => $booking->pickup_address ?? '',
                'dropoff_address' => $booking->dropoff_address ?? '',
                'estimated_fare' => (string) ($booking->estimated_fare ?? '0'),
                'status' => 'searching',
            ];
        } catch (\Exception $e) {
            // Log error silently
        }
    }


    public function bookingCancelled(Booking $booking, string $reason = ''): void
    {
        try {
            $message = "Your ride #{$booking->booking_code} has been cancelled.";
            if ($reason) {
                $message .= " Reason: {$reason}";
            }

            $this->sendBookingNotificationToUser($booking, 'booking_cancelled', $message);

            if ($booking->driver) {
                $this->sendBookingNotificationToDriver($booking, 'user_cancelled', "The user has cancelled the ride request.");
            }
        } catch (\Exception $e) {
        }
    }


    public function driverLocationUpdated(Booking $booking, float $latitude, float $longitude): void
    {
        try {
            $this->sendDriverLocationUpdate($booking, $latitude, $longitude);
        } catch (\Exception $e) {
        }
    }


    protected function buildFullPayloadData(Booking $booking, array $overrides = [], array $extraData = []): array
    {
        $booking->loadMissing([
            'user',
            'bookingContact',
            'rideType',
            'driver',
            'driver.driverProfile',
            'driver.vehicles',
            'driver.currentLocation',
            'promoUsage.promoCode',
        ]);

        $user = $booking->user;

        if (!$user) {
            return array_merge($overrides, $extraData);
        }

        $driver = $booking->driver;

        $newBookingEvent = new NewBooking($booking, $user, $driver);
        $payload = $newBookingEvent->getFCMPayloadData();

        $payload = array_replace_recursive($payload, $overrides);

        if (!empty($extraData)) {
            $payload['extra'] = $extraData;
        }

        return $payload;
    }


    protected function buildMinimalFCMPayload(Booking $booking, string $type, string $status, ?int $driverId = null): array
    {
        $booking->loadMissing([
            'user',
            'rideType',
            'driver',
            'driver.driverProfile',
            'driver.vehicles',
            'driver.currentLocation',
        ]);

        $user = $booking->user;
        $driver = $booking->driver;
        $vehicle = $driver ? $driver->vehicles->first() : null;
        $driverLocation = $driver ? $driver->currentLocation : null;

        $distanceToCustomer = 0;
        if ($driverLocation && $booking->pickup_latitude && $booking->pickup_longitude) {
            $distanceToCustomer = $this->calculateDistanceForPayload(
                (float) $driverLocation->latitude,
                (float) $driverLocation->longitude,
                (float) $booking->pickup_latitude,
                (float) $booking->pickup_longitude
            );
        }
        $etaMinutes = $distanceToCustomer > 0 ? ceil($distanceToCustomer * 2.5) : 0;

        $importantData = [
            'booking_id' => (string) $booking->id,
            'booking_code' => $booking->booking_code,
            'status' => $status,
            'event_type' => 'booking_status_changed',
            'acceptance_timer' => 30,
            'cancel_charge' => $this->getCancellationChargeForBooking($booking),
            'booking' => [
                'id' => (string) $booking->id,
                'booking_code' => $booking->booking_code,
                'user_id' => (string) $booking->user_id,
                'driver_id' => (string) ($driverId ?? $booking->driver_id ?? ''),
                'city_id' => (string) $booking->city_id,
                'ride_type_id' => (string) $booking->ride_type_id,
                'pickup_latitude' => (string) $booking->pickup_latitude,
                'pickup_longitude' => (string) $booking->pickup_longitude,
                'dropoff_latitude' => (string) $booking->dropoff_latitude,
                'dropoff_longitude' => (string) $booking->dropoff_longitude,
                'pickup_address' => $booking->pickup_address,
                'dropoff_address' => $booking->dropoff_address,
                'status' => $booking->status,
                'payment_method' => $booking->payment_method,
                'payment_status' => $booking->payment_status ?? 'pending',
                'otp' => (string) $booking->otp,
                'trip_code' => (string) $booking->trip_code,
                'scheduled_at' => $booking->scheduled_at ? $booking->scheduled_at->toISOString() : null,
                'created_at' => $booking->created_at ? $booking->created_at->toISOString() : null,
                'cancel_charge' => $this->getCancellationChargeForBooking($booking),
            ],
            'customer' => [
                'id' => (string) ($user->id ?? ''),
                'name' => $user->name ?? '',
                'phone' => $user->phone ?? '',
                'country_code' => $user->country_code ?? '+91',
                'photo' => $user->profile_photo ?? '',
                'rating' => (float) ($user->rating ?? 0),
                'distance_to_customer' => round($distanceToCustomer, 2),
                'eta_minutes' => $etaMinutes,
            ],
            'driver' => $driver ? [
                'id' => (string) $driver->id,
                'name' => $driver->name,
                'phone' => $driver->phone,
                'photo' => $driver->profile_photo ?? '',
                'rating' => (string) ($driver->driverProfile->rating ?? ""),
                'total_trips' => (string) $driver->bookingsAsDriver()->count(),
                'is_online' => (string) ($driver->is_online ?? '1'),
                'current_latitude' => (string) ($driverLocation->latitude ?? ''),
                'current_longitude' => (string) ($driverLocation->longitude ?? ''),
                'vehicle' => $vehicle ? [
                    'model' => $vehicle->model ?? '',
                    'make' => $vehicle->make ?? '',
                    'color' => $vehicle->color ?? '',
                    'number_plate' => $vehicle->number_plate ?? '',
                    'year' => (string) ($vehicle->year ?? ''),
                ] : null,
            ] : null,
            'trip_details' => [
                'distance' => (string) $booking->distance,
                'duration' => (string) $booking->duration,
                'fare' => (string) ($booking->final_fare ?? $booking->estimated_fare),
                'estimated_fare' => (string) $booking->estimated_fare,
                'final_fare' => (string) $booking->final_fare,
                'base_fare' => (string) ($booking->base_fare ?? ''),
                'distance_fare' => (string) ($booking->distance_fare ?? ''),
                'time_fare' => (string) ($booking->time_fare ?? ''),
                'surge_multiplier' => (string) ($booking->surge_multiplier ?? '1.00'),
                'surge_amount' => (string) ($booking->surge_amount ?? '0'),
            ],
            'pickup' => [
                'address' => $booking->pickup_address,
                'latitude' => (string) $booking->pickup_latitude,
                'longitude' => (string) $booking->pickup_longitude,
            ],
            'dropoff' => [
                'address' => $booking->dropoff_address,
                'latitude' => (string) $booking->dropoff_latitude,
                'longitude' => (string) $booking->dropoff_longitude,
            ],
            'ride_type' => [
                'id' => (string) ($booking->rideType->id ?? ''),
                'name' => $booking->rideType->name ?? '',
                'code' => $booking->rideType->code ?? '',
                'icon' => $booking->rideType->icon ?? '',
                'capacity' => (string) ($booking->rideType->capacity ?? ''),
            ],
            'cancel_charge' => $this->getCancellationChargeForBooking($booking),
            'timestamp' => now()->toISOString(),
        ];

        return [
            'type' => $type,
            'booking_id' => (string) $booking->id,
            'status' => $status,
            'event_type' => 'booking_status_changed',
            'data' => json_encode($importantData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }


    protected function calculateDistanceForPayload(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // Earth's radius in kilometers
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    protected function getCancellationChargeForBooking(Booking $booking): string
    {
        try {
            $city = \App\Models\City::find($booking->city_id);
            if ($city && $booking->ride_type_id) {
                $cityService = app(\App\Services\CityService::class);
                $policy = $cityService->getCancellationPolicy($city, $booking->ride_type_id);

                if ($policy) {
                    // Use cancellation policy fee (for new bookings, trip amount is 0, so just return fixed fee)
                    return (string) ($policy->cancellation_fee ?? '0');
                }
            }
        } catch (\Exception $e) {
        }

        // Fallback to ride type's cancellation charge if policy not found
        return (string) ($booking->rideType->cancellation_charge ?? '0');
    }
}
