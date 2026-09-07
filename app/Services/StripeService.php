<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

use Exception;

class StripeService
{
    protected $publishableKey;
    protected $secretKey;
    protected $webhookSecret;
    protected $baseUrl;

    public function __construct()
    {
        $publishableKeyConfig = \App\Models\SystemConfiguration::where('key', 'stripe_publishable_key')
            ->where('is_active', true)
            ->first();
        $this->publishableKey = $publishableKeyConfig ? $publishableKeyConfig->value : null;

        $secretKeyConfig = \App\Models\SystemConfiguration::where('key', 'stripe_secret_key')
            ->where('is_active', true)
            ->first();
        $this->secretKey = $secretKeyConfig ? $secretKeyConfig->value : null;

        $webhookSecretConfig = \App\Models\SystemConfiguration::where('key', 'stripe_webhook_secret')
            ->where('is_active', true)
            ->first();
        $this->webhookSecret = $webhookSecretConfig ? $webhookSecretConfig->value : null;

        $this->baseUrl = 'https://api.stripe.com/v1';
    }


    public function createPaymentIntent(array $paymentData): array
    {
        try {
            // Validate credentials
            if (empty($this->secretKey)) {
                return [
                    'success' => false,
                    'message' => 'Stripe credentials are not configured. Please check your Stripe Secret Key in system configurations.',
                    'error' => 'credentials_missing',
                    'error_code' => 'credentials_missing'
                ];
            }

            $payload = [
                'amount' => $paymentData['amount'] * 100, // Convert to cents
                'currency' => $paymentData['currency'] ?? 'inr',
                'metadata' => $paymentData['metadata'] ?? [],
                'description' => $paymentData['description'] ?? 'Taxi Booking Payment',
                'automatic_payment_methods[enabled]' => 'true'
            ];

            $response = Http::withBasicAuth($this->secretKey, '')
                ->asForm()
                ->post($this->baseUrl . '/payment_intents', $payload);

            if ($response->successful()) {
                $data = $response->json();

                

                return [
                    'success' => true,
                    'payment_intent_id' => $data['id'],
                    'client_secret' => $data['client_secret'],
                    'amount' => $data['amount'],
                    'currency' => $data['currency'],
                    'status' => $data['status'],
                    'data' => $data
                ];
            } else {
                $errorResponse = $response->json();
                $statusCode = $response->status();
                
                

                // Handle authentication errors specifically
                if ($statusCode === 401) {
                    $errorMessage = 'Stripe authentication failed. Please verify that your Stripe Secret Key is correct and matches your Stripe account.';
                    if (isset($errorResponse['error']['message'])) {
                        $errorMessage .= ' Error: ' . $errorResponse['error']['message'];
                    }
                    return [
                        'success' => false,
                        'message' => $errorMessage,
                        'error' => $errorResponse['error']['message'] ?? 'Authentication failed',
                        'error_code' => 'authentication_failed',
                        'stripe_error' => $errorResponse
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'Failed to create Stripe Payment Intent',
                    'error' => $errorResponse['error']['message'] ?? 'Unknown error',
                    'error_code' => $errorResponse['error']['code'] ?? null,
                    'stripe_error' => $errorResponse
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Stripe Payment Intent creation failed',
                'error' => $e->getMessage()
            ];
        }
    }


    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->get($this->baseUrl . '/payment_intents/' . $paymentIntentId);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'payment_intent' => $data
                ];
            } else {
                $errorResponse = $response->json();
                

                return [
                    'success' => false,
                    'message' => 'Failed to retrieve Payment Intent',
                    'error' => $errorResponse['error']['message'] ?? 'Unknown error'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to retrieve Payment Intent',
                'error' => $e->getMessage()
            ];
        }
    }


    public function confirmPaymentIntent(string $paymentIntentId): array
    {
        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->asForm()
                ->post($this->baseUrl . '/payment_intents/' . $paymentIntentId . '/confirm');

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'payment_intent' => $data,
                    'status' => $data['status']
                ];
            } else {
                $errorResponse = $response->json();
                

                return [
                    'success' => false,
                    'message' => 'Failed to confirm Payment Intent',
                    'error' => $errorResponse['error']['message'] ?? 'Unknown error'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to confirm Payment Intent',
                'error' => $e->getMessage()
            ];
        }
    }


    public function verifyWebhookSignature(string $payload, string $signature): array
    {
        try {
            $expectedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);

            if (hash_equals($expectedSignature, $signature)) {
                return [
                    'success' => true,
                    'message' => 'Webhook signature verified'
                ];
            } else {
                

                return [
                    'success' => false,
                    'message' => 'Webhook signature verification failed'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Webhook signature verification failed',
                'error' => $e->getMessage()
            ];
        }
    }


    public function createPaymentLink(array $paymentData): array
    {
        try {
            // Validate credentials
            if (empty($this->secretKey)) {
                return [
                    'success' => false,
                    'message' => 'Stripe credentials are not configured. Please check your Stripe Secret Key in system configurations.',
                    'error' => 'credentials_missing',
                    'error_code' => 'credentials_missing'
                ];
            }

            $amount = (float) ($paymentData['amount'] ?? 0);
            $currency = strtolower($paymentData['currency'] ?? 'inr');

            // Stripe minimum amount validation
            // Stripe requires minimum $0.50 USD equivalent
            // Note: Setting to ₹1 will allow attempts but Stripe will reject amounts below ~₹42
            // For USD: minimum $0.50
            $minimumAmounts = [
                'inr' => 1.0,  // ₹1 minimum (WARNING: Stripe will reject amounts below ~₹42)
                'usd' => 0.50,  // $0.50 minimum
            ];

            $minimumAmount = $minimumAmounts[$currency] ?? 0.50;

            if ($amount < $minimumAmount) {
                $errorMessage = "Payment amount ({$currency} " . number_format($amount, 2) . ") is below Stripe's minimum requirement ({$currency} " . number_format($minimumAmount, 2) . "). Stripe requires a minimum equivalent of $0.50 USD.";

                

                return [
                    'success' => false,
                    'message' => $errorMessage,
                    'error' => 'amount_too_small',
                    'minimum_amount' => $minimumAmount,
                    'currency' => $currency
                ];
            }

            $pricePayload = [
                'unit_amount' => $amount * 100, // Convert to cents
                'currency' => $currency,
                'product_data' => [
                    'name' => $paymentData['description'] ?? 'Taxi Booking Payment',
                ],
                'metadata' => $paymentData['metadata'] ?? []
            ];

            $priceResponse = Http::withBasicAuth($this->secretKey, '')
                ->asForm()
                ->post($this->baseUrl . '/prices', $pricePayload);

            if (!$priceResponse->successful()) {
                $errorResponse = $priceResponse->json();
                return [
                    'success' => false,
                    'message' => 'Failed to create Stripe price',
                    'error' => $errorResponse['error']['message'] ?? 'Unknown error'
                ];
            }

            $priceData = $priceResponse->json();

            $payload = [
                'line_items' => [
                    [
                        'price' => $priceData['id'],
                        'quantity' => 1,
                    ]
                ],
                'after_completion' => [
                    'type' => 'redirect',
                    'redirect' => [
                        'url' => $paymentData['success_url'] ?? url('/api/payments/stripe/success')
                    ]
                ],
                'metadata' => $paymentData['metadata'] ?? [],
                'phone_number_collection[enabled]' => 'true',
                'billing_address_collection' => 'auto'
            ];

            $payload = array_filter($payload, function ($value) {
                return $value !== null;
            });


            $response = Http::withBasicAuth($this->secretKey, '')
                ->asForm()
                ->post($this->baseUrl . '/payment_links', $payload);

            if ($response->successful()) {
                $data = $response->json();

                

                return [
                    'success' => true,
                    'payment_link_id' => $data['id'],
                    'url' => $data['url'],
                    'amount' => $data['amount_total'] ?? $paymentData['amount'] * 100,
                    'currency' => $data['currency'] ?? strtolower($paymentData['currency'] ?? 'inr'),
                    'status' => $data['status'] ?? 'active',
                    'created_at' => $data['created'] ?? time(),
                    'data' => $data
                ];
            } else {
                $errorResponse = $response->json();
                $statusCode = $response->status();
                $errorCode = $errorResponse['error']['code'] ?? null;
                $errorMessage = $errorResponse['error']['message'] ?? 'Unknown error';

                

                // Handle authentication errors specifically
                if ($statusCode === 401) {
                    $authErrorMessage = 'Stripe authentication failed. Please verify that your Stripe Secret Key is correct and matches your Stripe account.';
                    if (isset($errorResponse['error']['message'])) {
                        $authErrorMessage .= ' Error: ' . $errorResponse['error']['message'];
                    }
                    return [
                        'success' => false,
                        'message' => $authErrorMessage,
                        'error' => $errorMessage,
                        'error_code' => 'authentication_failed',
                        'stripe_error' => $errorResponse
                    ];
                }

                // Detect amount_too_small error
                $isAmountTooSmall = $errorCode === 'amount_too_small' ||
                    stripos($errorMessage, 'amount') !== false &&
                    stripos($errorMessage, '50 cents') !== false;

                return [
                    'success' => false,
                    'message' => $isAmountTooSmall
                        ? "Payment amount is too small for Stripe. {$errorMessage}"
                        : 'Failed to create Stripe Payment Link',
                    'error' => $isAmountTooSmall ? 'amount_too_small' : $errorMessage,
                    'error_code' => $errorCode,
                    'stripe_error' => $errorResponse
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Stripe Payment Link creation failed',
                'error' => $e->getMessage()
            ];
        }
    }


    public function getPaymentLinkStatus(string $paymentLinkId): array
    {
        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->get($this->baseUrl . '/payment_links/' . $paymentLinkId);

            if ($response->successful()) {
                $paymentLinkData = $response->json();

                // Extract payment intent ID from payment_intent if available
                $paymentIntentId = null;
                if (isset($paymentLinkData['payment_intent'])) {
                    if (is_string($paymentLinkData['payment_intent'])) {
                        $paymentIntentId = $paymentLinkData['payment_intent'];
                    } elseif (is_array($paymentLinkData['payment_intent']) && isset($paymentLinkData['payment_intent']['id'])) {
                        $paymentIntentId = $paymentLinkData['payment_intent']['id'];
                    }
                }

                // If payment_intent is not directly available, check payment sessions
                if (!$paymentIntentId && isset($paymentLinkData['id'])) {
                    try {
                        // List payment sessions for this payment link
                        $sessionsResponse = Http::withBasicAuth($this->secretKey, '')
                            ->get($this->baseUrl . '/checkout/sessions', [
                                'payment_link' => $paymentLinkId,
                                'limit' => 1
                            ]);

                        if ($sessionsResponse->successful()) {
                            $sessionsData = $sessionsResponse->json();
                            if (isset($sessionsData['data']) && is_array($sessionsData['data']) && count($sessionsData['data']) > 0) {
                                $session = $sessionsData['data'][0];
                                // Extract payment intent from session
                                if (isset($session['payment_intent'])) {
                                    if (is_string($session['payment_intent'])) {
                                        $paymentIntentId = $session['payment_intent'];
                                    } elseif (is_array($session['payment_intent']) && isset($session['payment_intent']['id'])) {
                                        $paymentIntentId = $session['payment_intent']['id'];
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        // Log but don't fail - this is a fallback check
                    }
                }

                // Get payment intent status if available
                $paymentStatus = null;
                if ($paymentIntentId) {
                    $paymentIntentResult = $this->retrievePaymentIntent($paymentIntentId);
                    if ($paymentIntentResult['success'] && isset($paymentIntentResult['payment_intent']['status'])) {
                        $paymentStatus = $paymentIntentResult['payment_intent']['status'];
                    }
                }

                // Determine overall status
                // Stripe payment links can be 'active', 'completed', 'expired', etc.
                // If payment_intent exists and is 'succeeded', the payment is complete
                $paymentLinkStatus = $paymentLinkData['status'] ?? 'unknown';
                $status = $paymentLinkStatus;
                
                // If payment link status is 'completed', mark as completed
                if (strtolower($paymentLinkStatus) === 'completed') {
                    $status = 'completed';
                } elseif ($paymentStatus === 'succeeded') {
                    $status = 'completed';
                } elseif ($paymentStatus === 'processing') {
                    $status = 'processing';
                } elseif ($paymentStatus === 'requires_payment_method' || $paymentStatus === 'canceled') {
                    $status = 'failed';
                }

                

                return [
                    'success' => true,
                    'status' => $status,
                    'payment_intent_id' => $paymentIntentId,
                    'payment_intent_status' => $paymentStatus,
                    'data' => $paymentLinkData
                ];
            } else {
                $errorResponse = $response->json();
                

                return [
                    'success' => false,
                    'message' => 'Failed to retrieve payment link status',
                    'error' => $errorResponse['error']['message'] ?? 'Unknown error'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to retrieve payment link status',
                'error' => $e->getMessage()
            ];
        }
    }


    public function createRefund(string $paymentIntentId, int $amount): array
    {
        try {
            $payload = [
                'payment_intent' => $paymentIntentId,
                'amount' => $amount,
                'reason' => 'requested_by_customer'
            ];

            $response = Http::withBasicAuth($this->secretKey, '')
                ->asForm()
                ->post($this->baseUrl . '/refunds', $payload);

            if ($response->successful()) {
                $refundData = $response->json();

                

                return [
                    'success' => true,
                    'refund_id' => $refundData['id'],
                    'payment_intent_id' => $paymentIntentId,
                    'amount' => $refundData['amount'],
                    'status' => $refundData['status'],
                    'message' => 'Refund created successfully'
                ];
            } else {
                $errorResponse = $response->json();
                

                return [
                    'success' => false,
                    'message' => 'Refund creation failed',
                    'error' => $errorResponse['error']['message'] ?? 'Unknown error'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Refund creation failed',
                'error' => $e->getMessage()
            ];
        }
    }
}
