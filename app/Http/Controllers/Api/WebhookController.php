<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentGatewayService;
use App\Services\RazorpayService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use Exception;

class WebhookController extends Controller
{
    protected $paymentGatewayService;

    public function __construct(PaymentGatewayService $paymentGatewayService)
    {
        $this->paymentGatewayService = $paymentGatewayService;
    }

    
    public function razorpayWebhook(Request $request): JsonResponse
    {
        try {

            if (!$this->verifyRazorpaySignature($request)) {

                return response()->json(['error' => 'Invalid signature'], 400);
            }

            $webhookData = $request->all();
            $result = $this->paymentGatewayService->handleWebhook('razorpay', $webhookData);

            if ($result['success']) {

                return response()->json(['status' => 'success']);
            } else {

                return response()->json(['error' => 'Webhook processing failed'], 500);
            }
        } catch (Exception $e) {
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    
    public function stripeWebhook(Request $request): JsonResponse
    {
        try {

            if (!$this->verifyStripeSignature($request)) {

                return response()->json(['error' => 'Invalid signature'], 400);
            }

            $webhookData = $request->all();
            $result = $this->paymentGatewayService->handleWebhook('stripe', $webhookData);

            if ($result['success']) {

                return response()->json(['status' => 'success']);
            } else {

                return response()->json(['error' => 'Webhook processing failed'], 500);
            }
        } catch (Exception $e) {
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    
    private function verifyRazorpaySignature(Request $request): bool
    {
        try {
            $webhookSecret = config('services.razorpay.webhook_secret');
            $signature = $request->header('X-Razorpay-Signature');
            $payload = $request->getContent();

            if (!$webhookSecret || !$signature) {
                return false;
            }

            $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

            return hash_equals($expectedSignature, $signature);
        } catch (Exception $e) {
            return false;
        }
    }

    
    private function verifyStripeSignature(Request $request): bool
    {
        try {
            $webhookSecret = config('services.stripe.webhook_secret');
            $signature = $request->header('Stripe-Signature');
            $payload = $request->getContent();
            $timestamp = $request->header('Stripe-Timestamp', time());

            if (!$webhookSecret || !$signature) {
                return false;
            }

            $elements = explode(',', $signature);
            $signatureHash = null;

            foreach ($elements as $element) {
                $item = explode('=', $element, 2);
                if ($item[0] === 'v1') {
                    $signatureHash = $item[1];
                    break;
                }
            }

            if (!$signatureHash) {
                return false;
            }

            $signedPayload = $timestamp . '.' . $payload;
            $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

            return hash_equals($expectedSignature, $signatureHash);
        } catch (Exception $e) {
            return false;
        }
    }

    
    public function paymentSuccess(Request $request): JsonResponse
    {
        try {
            $gateway = strtolower(trim((string) $request->input('gateway', '')));

            if ($gateway === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Gateway is required'
                ], 400);
            }

            if (!$this->verifyGatewaySignature($request, $gateway)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid webhook signature'
                ], 401);
            }

            $transactionId = $request->input('transaction_id');
            $amount = $request->input('amount');
            $currency = $request->input('currency', 'INR');

            

            $result = $this->paymentGatewayService->handleWebhook($gateway, $request->all());

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'] ?? 'Payment webhook processed'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed'
            ], 500);
        }
    }

    
    public function paymentFailure(Request $request): JsonResponse
    {
        try {
            $gateway = strtolower(trim((string) $request->input('gateway', '')));

            if ($gateway === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Gateway is required'
                ], 400);
            }

            if (!$this->verifyGatewaySignature($request, $gateway)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid webhook signature'
                ], 401);
            }

            $transactionId = $request->input('transaction_id');
            $errorCode = $request->input('error_code');
            $errorMessage = $request->input('error_message');

            

            $result = $this->paymentGatewayService->handleWebhook($gateway, $request->all());

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'] ?? 'Payment failure webhook processed'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed'
            ], 500);
        }
    }

    
    public function refundWebhook(Request $request): JsonResponse
    {
        try {
            $gateway = $request->input('gateway', 'unknown');

            if (!$this->verifyGatewaySignature($request, $gateway)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid webhook signature'
                ], 401);
            }

            $refundId = $request->input('refund_id');
            $transactionId = $request->input('transaction_id');
            $amount = $request->input('amount');
            $status = $request->input('status');

            

            $result = $this->paymentGatewayService->handleWebhook($gateway, $request->all());

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'] ?? 'Refund webhook processed'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed'
            ], 500);
        }
    }

    
    public function testWebhook(Request $request): JsonResponse
    {
        if (!app()->environment(['local', 'testing'])) {
            return response()->json(['error' => 'Not allowed in production'], 403);
        }


        return response()->json([
            'success' => true,
            'message' => 'Test webhook received successfully',
            'data' => [
                'method' => $request->method(),
                'timestamp' => now()->toISOString(),
                'payload_size' => strlen($request->getContent()),
                'content_type' => $request->header('Content-Type'),
            ]
        ]);
    }

    
    public function getWebhookLogs(Request $request): JsonResponse
    {
        if (!app()->environment(['local', 'testing'])) {
            return response()->json(['error' => 'Not allowed in production'], 403);
        }

        try {
            $gateway = $request->input('gateway');
            $limit = $request->input('limit', 50);

            $logPath = storage_path('logs/laravel.log');

            if (!file_exists($logPath)) {
                return response()->json([
                    'success' => true,
                    'logs' => [],
                    'message' => 'No logs found'
                ]);
            }

            $logs = [];
            $handle = fopen($logPath, 'r');

            if ($handle) {
                $lines = [];
                while (($line = fgets($handle)) !== false) {
                    if (strpos($line, 'webhook') !== false) {
                        if (!$gateway || strpos($line, $gateway) !== false) {
                            $lines[] = trim($line);
                        }
                    }
                }
                fclose($handle);

                $logs = array_slice(array_reverse($lines), 0, $limit);
            }

            return response()->json([
                'success' => true,
                'logs' => $logs,
                'count' => count($logs)
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve logs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function verifyGatewaySignature(Request $request, string $gateway): bool
    {
        $gateway = strtolower(trim($gateway));

        if ($gateway === 'razorpay') {
            return $this->verifyRazorpaySignature($request);
        }

        if ($gateway === 'stripe') {
            return $this->verifyStripeSignature($request);
        }

        $sharedSecret = config('services.webhook.secret');
        $signature = $request->header('X-Webhook-Signature');

        if (!$sharedSecret || !$signature) {
            return app()->environment(['local', 'testing']);
        }

        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $sharedSecret);

        return hash_equals($expectedSignature, $signature);
    }

}
