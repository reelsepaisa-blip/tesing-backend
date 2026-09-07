<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

use Exception;

class RazorpayService
{
    protected $keyId;
    protected $keySecret;
    protected $baseUrl;

    public function __construct()
    {
        $keyIdConfig = \App\Models\SystemConfiguration::where('key', 'razorpay_key_id')
            ->where('is_active', true)
            ->first();
        $this->keyId = $keyIdConfig ? $keyIdConfig->value : null;

        // Check both possible key names for backward compatibility
        $keySecretConfig = \App\Models\SystemConfiguration::whereIn('key', ['razorpay_secret_key', 'razorpay_key_secret'])
            ->where('is_active', true)
            ->first();
        $this->keySecret = $keySecretConfig ? $keySecretConfig->value : null;

        $this->baseUrl = 'https://api.razorpay.com/v1';
    }

    
    public function createOrder(array $orderData): array
    {
        try {
            $payload = [
                'amount' => (int) round($orderData['amount'] * 100), // Convert to paise and ensure whole number
                'currency' => $orderData['currency'] ?? 'INR',
                'receipt' => $orderData['order_id'] ?? 'order_' . time(),
                'notes' => $orderData['notes'] ?? [],
            ];


            $response = Http::withBasicAuth($this->keyId, $this->keySecret)
                ->post($this->baseUrl . '/orders', $payload);

            if ($response->successful()) {
                $data = $response->json();

                

                return [
                    'success' => true,
                    'order_id' => $data['id'],
                    'amount' => $data['amount'],
                    'currency' => $data['currency'],
                    'status' => $data['status'],
                    'data' => $data
                ];
            } else {
                $errorResponse = $response->json();

                return [
                    'success' => false,
                    'message' => 'Failed to create Razorpay order',
                    'error' => $errorResponse['error']['description'] ?? 'Unknown error',
                    'razorpay_error' => $errorResponse
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Razorpay order creation failed',
                'error' => $e->getMessage()
            ];
        }
    }

    
    public function createPaymentLink(array $paymentData): array
    {
        try {
            // Validate credentials
            if (empty($this->keyId) || empty($this->keySecret)) {
                return [
                    'success' => false,
                    'message' => 'Razorpay credentials are not configured. Please check your Razorpay Key ID and Secret Key in system configurations.',
                    'error' => 'credentials_missing',
                    'error_code' => 'credentials_missing'
                ];
            }

            $amountInPaise = round($paymentData['amount'] * 100);
            $amountInPaise = (int) $amountInPaise;


            $payload = [
                'amount' => $amountInPaise, // Send as integer
                'currency' => $paymentData['currency'] ?? 'INR',
                'description' => $paymentData['description'] ?? 'Taxi Booking Payment',
                'customer' => [
                    'name' => $paymentData['customer_name'] ?? 'Customer',
                    'email' => $paymentData['customer_email'] ?? '',
                    'contact' => $paymentData['customer_phone'] ?? ''
                ],
                'notify' => [
                    'sms' => true,
                    'email' => true
                ],
                'reminder_enable' => true,
                'callback_url' => $paymentData['callback_url'] ?? '',
                'callback_method' => 'get',
                'notes' => $paymentData['notes'] ?? []
            ];


            $response = Http::withBasicAuth($this->keyId, $this->keySecret)
                ->post($this->baseUrl . '/payment_links', $payload);

            if ($response->successful()) {
                $data = $response->json();

                

                return [
                    'success' => true,
                    'payment_link_id' => $data['id'],
                    'short_url' => $data['short_url'],
                    'amount' => $data['amount'],
                    'currency' => $data['currency'],
                    'status' => $data['status'],
                    'created_at' => $data['created_at'],
                    'data' => $data
                ];
            } else {
                $errorResponse = $response->json();
                $statusCode = $response->status();
                
                

                // Handle authentication errors specifically
                if ($statusCode === 401) {
                    $errorMessage = 'Razorpay authentication failed. Please verify that your Razorpay Key ID and Secret Key are correct and match your Razorpay account.';
                    if (isset($errorResponse['error']['description'])) {
                        $errorMessage .= ' Error: ' . $errorResponse['error']['description'];
                    }
                    return [
                        'success' => false,
                        'message' => $errorMessage,
                        'error' => $errorResponse['error']['description'] ?? 'Authentication failed',
                        'error_code' => 'authentication_failed',
                        'razorpay_error' => $errorResponse
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'Failed to create Razorpay Payment Link',
                    'error' => $errorResponse['error']['description'] ?? 'Unknown error',
                    'error_code' => $errorResponse['error']['code'] ?? null,
                    'razorpay_error' => $errorResponse
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Razorpay Payment Link creation failed',
                'error' => $e->getMessage()
            ];
        }
    }

    
    public function processPayment(array $paymentData): array
    {
        try {
            $orderResult = $this->createOrder([
                'amount' => $paymentData['amount'],
                'currency' => $paymentData['currency'] ?? 'INR',
                'order_id' => $paymentData['order_id'],
                'notes' => [
                    'customer_id' => $paymentData['customer_id'],
                    'description' => $paymentData['description']
                ]
            ]);

            if (!$orderResult['success']) {
                return $orderResult;
            }

            if (isset($paymentData['payment_token'])) {
                return $this->capturePayment($orderResult['order_id'], $paymentData['payment_token']);
            }

            return [
                'success' => true,
                'requires_action' => true,
                'order_id' => $orderResult['order_id'],
                'amount' => $orderResult['amount'],
                'currency' => $orderResult['currency'],
                'key_id' => $this->keyId,
                'message' => 'Order created, complete payment on frontend'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Payment processing failed',
                'error' => $e->getMessage()
            ];
        }
    }

    
    public function capturePayment(string $orderId, string $paymentToken): array
    {
        try {
            $verificationResult = $this->verifyPaymentSignature([
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentToken,
                'razorpay_signature' => request('razorpay_signature')
            ]);

            if (!$verificationResult['success']) {
                return $verificationResult;
            }

            $response = Http::withBasicAuth($this->keyId, $this->keySecret)
                ->get($this->baseUrl . '/payments/' . $paymentToken);

            if ($response->successful()) {
                $paymentData = $response->json();

                if ($paymentData['status'] === 'captured') {
                    

                    return [
                        'success' => true,
                        'transaction_id' => $paymentToken,
                        'order_id' => $orderId,
                        'amount' => $paymentData['amount'] / 100, // Convert from paise
                        'currency' => $paymentData['currency'],
                        'status' => 'completed',
                        'gateway_response' => $paymentData
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Payment not captured',
                        'status' => $paymentData['status']
                    ];
                }
            } else {

                return [
                    'success' => false,
                    'message' => 'Failed to verify payment',
                    'error' => $response->json()
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Payment capture failed',
                'error' => $e->getMessage()
            ];
        }
    }

    
    public function verifyPaymentSignature(array $data): array
    {
        try {
            $expectedSignature = hash_hmac(
                'sha256',
                $data['razorpay_order_id'] . '|' . $data['razorpay_payment_id'],
                $this->keySecret
            );

            if (hash_equals($expectedSignature, $data['razorpay_signature'])) {
                return [
                    'success' => true,
                    'message' => 'Payment signature verified'
                ];
            } else {
                

                return [
                    'success' => false,
                    'message' => 'Payment signature verification failed'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Signature verification failed',
                'error' => $e->getMessage()
            ];
        }
    }

    
    public function handleWebhook(array $webhookData): array
    {
        try {
            $event = $webhookData['event'];
            $payload = $webhookData['payload'];

            

            switch ($event) {
                case 'payment.captured':
                    return $this->handlePaymentCaptured($payload);
                case 'payment.failed':
                    return $this->handlePaymentFailed($payload);
                case 'order.paid':
                    return $this->handleOrderPaid($payload);
                default:

                    return [
                        'success' => true,
                        'message' => 'Webhook received but not processed'
                    ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Webhook processing failed',
                'error' => $e->getMessage()
            ];
        }
    }

    
    protected function handlePaymentCaptured(array $payload): array
    {
        $payment = $payload['payment']['entity'];

        return [
            'success' => true,
            'transaction_id' => $payment['id'],
            'order_id' => $payment['order_id'],
            'payment_link_id' => $payment['payment_link_id'] ?? null,
            'status' => 'completed',
            'amount' => $payment['amount'] / 100,
            'currency' => $payment['currency'],
            'message' => 'Payment captured successfully'
        ];
    }

    
    protected function handlePaymentFailed(array $payload): array
    {
        $payment = $payload['payment']['entity'];

        return [
            'success' => false,
            'transaction_id' => $payment['id'],
            'order_id' => $payment['order_id'] ?? null,
            'payment_link_id' => $payment['payment_link_id'] ?? null,
            'status' => 'failed',
            'message' => 'Payment failed',
            'error_description' => $payment['error_description'] ?? 'Unknown error'
        ];
    }

    
    protected function handleOrderPaid(array $payload): array
    {
        $order = $payload['order']['entity'];

        return [
            'success' => true,
            'order_id' => $order['id'],
            'status' => 'completed',
            'amount' => $order['amount'] / 100,
            'currency' => $order['currency'],
            'message' => 'Order paid successfully'
        ];
    }

    
    public function refundPayment(string $paymentId, float $amount): array
    {
        try {
            $response = Http::withBasicAuth($this->keyId, $this->keySecret)
                ->post($this->baseUrl . '/payments/' . $paymentId . '/refund', [
                    'amount' => (int) round($amount * 100), // Convert to paise and ensure whole number
                    'speed' => 'normal',
                    'notes' => [
                        'reason' => 'Booking cancellation'
                    ]
                ]);

            if ($response->successful()) {
                $refundData = $response->json();

                

                return [
                    'success' => true,
                    'refund_id' => $refundData['id'],
                    'payment_id' => $paymentId,
                    'amount' => $refundData['amount'] / 100,
                    'status' => $refundData['status'],
                    'message' => 'Refund processed successfully'
                ];
            } else {

                return [
                    'success' => false,
                    'message' => 'Refund processing failed',
                    'error' => $response->json()
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Refund processing failed',
                'error' => $e->getMessage()
            ];
        }
    }

    
    public function getPaymentDetails(string $paymentId): array
    {
        try {
            $response = Http::withBasicAuth($this->keyId, $this->keySecret)
                ->get($this->baseUrl . '/payments/' . $paymentId);

            if ($response->successful()) {
                $paymentData = $response->json();

                return [
                    'success' => true,
                    'payment' => $paymentData
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to get payment details',
                    'error' => $response->json()
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to get payment details',
                'error' => $e->getMessage()
            ];
        }
    }

    
    public function getPaymentLinkStatus(string $paymentLinkId): array
    {
        try {
            $response = Http::withBasicAuth($this->keyId, $this->keySecret)
                ->get($this->baseUrl . '/payment_links/' . $paymentLinkId);

            if ($response->successful()) {
                $paymentLinkData = $response->json();

                // Extract payment_id from payments array if available
                $paymentId = null;
                if (isset($paymentLinkData['payments']) && is_array($paymentLinkData['payments']) && count($paymentLinkData['payments']) > 0) {
                    $paymentId = $paymentLinkData['payments'][0]['payment_id'] ?? $paymentLinkData['payments'][0]['id'] ?? null;
                }

                

                return [
                    'success' => true,
                    'status' => $paymentLinkData['status'] ?? 'unknown',
                    'payment_id' => $paymentId,
                    'data' => $paymentLinkData
                ];
            } else {

                return [
                    'success' => false,
                    'message' => 'Failed to get payment link status',
                    'error' => $response->json()
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to get payment link status',
                'error' => $e->getMessage()
            ];
        }
    }
}
