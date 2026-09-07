<?php

namespace App\Services;

use App\Models\User;
use App\Models\Booking;
use Illuminate\Support\Facades\Http;

use Exception;

class SMSService
{
    protected $twilioSid;
    protected $twilioToken;
    protected $twilioFrom;
    protected $msg91ApiKey;
    protected $msg91SenderId;
    protected $provider;

    public function __construct()
    {
        $this->provider = config('services.sms.provider', 'twilio');

        $this->twilioSid = config('services.twilio.sid');
        $this->twilioToken = config('services.twilio.token');
        $this->twilioFrom = config('services.twilio.from');

        $this->msg91ApiKey = config('services.msg91.api_key');
        $this->msg91SenderId = config('services.msg91.sender_id');
    }

    
    public function sendSMS(string $phoneNumber, string $message): array
    {
        try {
            $result = match ($this->provider) {
                'twilio' => $this->sendTwilioSMS($phoneNumber, $message),
                'msg91' => $this->sendMSG91SMS($phoneNumber, $message),
                default => throw new Exception("Unsupported SMS provider: {$this->provider}")
            };

            if ($result['success']) {
                
            } else {
                
            }

            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'SMS sending failed',
                'error' => $e->getMessage()
            ];
        }
    }

    
    protected function sendTwilioSMS(string $phoneNumber, string $message): array
    {
        try {
            $response = Http::withBasicAuth($this->twilioSid, $this->twilioToken)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->twilioSid}/Messages.json", [
                    'From' => $this->twilioFrom,
                    'To' => $phoneNumber,
                    'Body' => $message,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'message_id' => $data['sid'],
                    'status' => $data['status'],
                    'provider' => 'twilio',
                    'data' => $data
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Twilio SMS failed',
                    'error' => $response->json(),
                    'provider' => 'twilio'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Twilio SMS exception',
                'error' => $e->getMessage(),
                'provider' => 'twilio'
            ];
        }
    }

    
    protected function sendMSG91SMS(string $phoneNumber, string $message): array
    {
        try {
            $response = Http::post('https://api.msg91.com/api/sendhttp.php', [
                'authkey' => $this->msg91ApiKey,
                'mobiles' => $phoneNumber,
                'message' => $message,
                'sender' => $this->msg91SenderId,
                'route' => 4, // Transactional route
                'country' => 91, // India country code
            ]);

            if ($response->successful()) {
                $responseText = $response->body();

                if (str_contains($responseText, 'success')) {
                    return [
                        'success' => true,
                        'message_id' => $responseText,
                        'provider' => 'msg91',
                        'data' => $responseText
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'MSG91 SMS failed',
                        'error' => $responseText,
                        'provider' => 'msg91'
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'message' => 'MSG91 SMS failed',
                    'error' => $response->body(),
                    'provider' => 'msg91'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'MSG91 SMS exception',
                'error' => $e->getMessage(),
                'provider' => 'msg91'
            ];
        }
    }

    
    public function sendOTP(string $phoneNumber, string $otp): array
    {
        $message = "Your eTaxi verification code is: {$otp}. This code will expire in 10 minutes. Do not share this code with anyone.";

        return $this->sendSMS($phoneNumber, $message);
    }

    
    public function sendBookingConfirmation(User $user, Booking $booking): array
    {
        if (!$user->phone) {
            return [
                'success' => false,
                'message' => 'User phone number not found'
            ];
        }

        $message = "Hi {$user->name}, your eTaxi booking #{$booking->booking_code} is confirmed. " .
            "Pickup: {$booking->pickup_address}. " .
            "We're finding a driver for you. Track your ride in the app.";

        return $this->sendSMS($user->phone, $message);
    }

    
    public function sendDriverAssigned(User $user, Booking $booking): array
    {
        if (!$user->phone || !$booking->driver) {
            return [
                'success' => false,
                'message' => 'Required information not found'
            ];
        }

        $driver = $booking->driver;
        $vehicle = $driver->vehicles->first();

        $message = "Great news! Driver {$driver->name} is coming to pick you up. " .
            ($vehicle ? "Vehicle: {$vehicle->model} ({$vehicle->registration_number}). " : "") .
            "Track your driver in the eTaxi app. Trip code: {$booking->trip_code}";

        return $this->sendSMS($user->phone, $message);
    }

    
    public function sendDriverArrived(User $user, Booking $booking): array
    {
        if (!$user->phone) {
            return [
                'success' => false,
                'message' => 'User phone number not found'
            ];
        }

        $message = "Your driver has arrived at the pickup location. " .
            "Please provide trip code {$booking->trip_code} to start your ride. " .
            "Booking: #{$booking->booking_code}";

        return $this->sendSMS($user->phone, $message);
    }

    
    public function sendTripStarted(User $user, Booking $booking): array
    {
        if (!$user->phone) {
            return [
                'success' => false,
                'message' => 'User phone number not found'
            ];
        }

        $message = "Your eTaxi trip has started! " .
            "Destination: {$booking->dropoff_address}. " .
            "Estimated fare: ₹{$booking->estimated_fare}. " .
            "Have a safe journey!";

        return $this->sendSMS($user->phone, $message);
    }

    
    public function sendTripCompleted(User $user, Booking $booking): array
    {
        if (!$user->phone) {
            return [
                'success' => false,
                'message' => 'User phone number not found'
            ];
        }

        $message = "Trip completed! Total fare: ₹{$booking->total_amount}. " .
            "Thank you for choosing eTaxi. " .
            "Please rate your ride experience in the app. " .
            "Booking: #{$booking->booking_code}";

        return $this->sendSMS($user->phone, $message);
    }

    
    public function sendBookingCancelled(User $user, Booking $booking, string $reason): array
    {
        if (!$user->phone) {
            return [
                'success' => false,
                'message' => 'User phone number not found'
            ];
        }

        $message = "Your eTaxi booking #{$booking->booking_code} has been cancelled. ";

        if ($booking->cancellation_charge > 0) {
            $message .= "Cancellation charge: ₹{$booking->cancellation_charge}. ";
        }

        $message .= "You can book a new ride anytime in the eTaxi app.";

        return $this->sendSMS($user->phone, $message);
    }

    
    public function sendNewBookingToDriver(User $driver, Booking $booking): array
    {
        if (!$driver->phone) {
            return [
                'success' => false,
                'message' => 'Driver phone number not found'
            ];
        }

        $message = "New ride request! " .
            "Pickup: {$booking->pickup_address}. " .
            "Destination: {$booking->dropoff_address}. " .
            "Estimated fare: ₹{$booking->estimated_fare}. " .
            "Accept now in the eTaxi Driver app.";

        return $this->sendSMS($driver->phone, $message);
    }

    
    public function sendPaymentReminder(User $user, Booking $booking): array
    {
        if (!$user->phone) {
            return [
                'success' => false,
                'message' => 'User phone number not found'
            ];
        }

        $message = "Payment pending for your eTaxi ride #{$booking->booking_code}. " .
            "Amount: ₹{$booking->total_amount}. " .
            "Please complete payment in the app to avoid service disruption.";

        return $this->sendSMS($user->phone, $message);
    }

    
    public function sendPromoSMS(User $user, array $promoData): array
    {
        if (!$user->phone) {
            return [
                'success' => false,
                'message' => 'User phone number not found'
            ];
        }

        $message = $promoData['message'];

        if (isset($promoData['promo_code'])) {
            $message .= " Use code: {$promoData['promo_code']}";
        }

        $message .= " Download eTaxi app now!";

        return $this->sendSMS($user->phone, $message);
    }

    
    public function sendBulkSMS(array $phoneNumbers, string $message): array
    {
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($phoneNumbers as $phoneNumber) {
            $result = $this->sendSMS($phoneNumber, $message);
            $results[] = [
                'phone' => $phoneNumber,
                'success' => $result['success'],
                'message_id' => $result['message_id'] ?? null,
                'error' => $result['error'] ?? null
            ];

            if ($result['success']) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }


        return [
            'success' => $successCount > 0,
            'total_sent' => count($phoneNumbers),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'results' => $results
        ];
    }

    
    public function getDeliveryStatus(string $messageId): array
    {
        try {
            $result = match ($this->provider) {
                'twilio' => $this->getTwilioDeliveryStatus($messageId),
                'msg91' => $this->getMSG91DeliveryStatus($messageId),
                default => throw new Exception("Delivery status not supported for provider: {$this->provider}")
            };

            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to get delivery status',
                'error' => $e->getMessage()
            ];
        }
    }

    
    protected function getTwilioDeliveryStatus(string $messageId): array
    {
        try {
            $response = Http::withBasicAuth($this->twilioSid, $this->twilioToken)
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$this->twilioSid}/Messages/{$messageId}.json");

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'status' => $data['status'],
                    'error_code' => $data['error_code'],
                    'error_message' => $data['error_message'],
                    'data' => $data
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to get Twilio delivery status',
                    'error' => $response->json()
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Twilio delivery status exception',
                'error' => $e->getMessage()
            ];
        }
    }

    
    protected function getMSG91DeliveryStatus(string $messageId): array
    {
        try {
            $response = Http::get('https://api.msg91.com/api/status.php', [
                'authkey' => $this->msg91ApiKey,
                'msgid' => $messageId,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'status' => $data[0]['status'] ?? 'unknown',
                    'data' => $data
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to get MSG91 delivery status',
                    'error' => $response->body()
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'MSG91 delivery status exception',
                'error' => $e->getMessage()
            ];
        }
    }
}
