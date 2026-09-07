<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

use Exception;

class PaytmService
{
    protected $merchantId;
    protected $merchantKey;
    protected $websiteName;
    protected $industryType;
    protected $channelId;
    protected $baseUrl;
    protected $callbackUrl;

    public function __construct()
    {
        $this->merchantId = \Illuminate\Support\Facades\DB::table('system_configurations')
            ->where('key', 'paytm_merchant_id')
            ->where('is_active', true)
            ->value('value');
        
        $this->merchantKey = \Illuminate\Support\Facades\DB::table('system_configurations')
            ->where('key', 'paytm_merchant_key')
            ->where('is_active', true)
            ->value('value');
            
        $this->websiteName = \Illuminate\Support\Facades\DB::table('system_configurations')
            ->where('key', 'paytm_website_name')
            ->where('is_active', true)
            ->value('value');
            
        $this->industryType = \Illuminate\Support\Facades\DB::table('system_configurations')
            ->where('key', 'paytm_industry_type')
            ->where('is_active', true)
            ->value('value') ?? 'Retail';
            
        $this->channelId = \Illuminate\Support\Facades\DB::table('system_configurations')
            ->where('key', 'paytm_channel_id')
            ->where('is_active', true)
            ->value('value') ?? 'WEB';
            
        $this->baseUrl = 'https://securegw-stage.paytm.in'; // Use stage URL for testing
        $this->callbackUrl = url('/api/payments/paytm/callback');
    }

    
    private function generateChecksum(array $params): string
    {
        $params = array_filter($params, function($value) {
            return $value !== "" && $value !== null;
        });
        
        ksort($params);
        $checksum_string = '';
        
        foreach ($params as $key => $value) {
            $checksum_string .= $key . '=' . $value . '&';
        }
        
        $checksum_string = rtrim($checksum_string, '&');
        $checksum_string .= '&' . $this->merchantKey;
        
        return hash('sha256', $checksum_string);
    }

    
    private function verifyChecksum(array $params, string $checksum): bool
    {
        $generatedChecksum = $this->generateChecksum($params);
        return hash_equals($generatedChecksum, $checksum);
    }

    
    public function createPaymentLink(array $transactionData): array
    {
        try {
            $orderId = $transactionData['order_id'] ?? 'ORDER_' . time();
            $amount = $transactionData['amount'];
            $customerId = $transactionData['customer_id'] ?? 'CUST_' . time();
            $customerEmail = $transactionData['customer_email'] ?? '';
            $customerMobile = $transactionData['customer_mobile'] ?? '';
            $callbackUrl = $transactionData['callback_url'] ?? $this->callbackUrl;

            $params = [
                'MID' => $this->merchantId,
                'ORDER_ID' => $orderId,
                'CUST_ID' => $customerId,
                'INDUSTRY_TYPE_ID' => $this->industryType,
                'CHANNEL_ID' => $this->channelId,
                'TXN_AMOUNT' => number_format($amount, 2, '.', ''),
                'WEBSITE' => $this->websiteName,
                'EMAIL' => $customerEmail,
                'MOBILE_NO' => $customerMobile,
                'CALLBACK_URL' => $callbackUrl,
                'PAYMENT_MODE_ONLY' => 'YES',
                'AUTH_MODE' => 'USRPWD',
                'PAYMENT_TYPE_ID' => 'UPI',
                'MERCHANT_KEY' => $this->merchantKey
            ];

            $checksum = $this->generateChecksum($params);
            $params['CHECKSUMHASH'] = $checksum;


            $paymentUrl = $this->createSimplifiedPaymentUrl($params);

            return [
                'success' => true,
                'order_id' => $orderId,
                'amount' => $amount,
                'params' => $params,
                'form_url' => $this->baseUrl . '/theia/processTransaction',
                'payment_url' => $paymentUrl, // Direct payment URL
                'checksum' => $checksum,
                'data' => $params
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Paytm Payment Link creation failed',
                'error' => $e->getMessage()
            ];
        }
    }

    
    private function createSimplifiedPaymentUrl(array $params): string
    {
        $queryParams = http_build_query($params);
        return $this->baseUrl . '/theia/processTransaction?' . $queryParams;
    }

    
    public function createTransaction(array $transactionData): array
    {
        try {
            $orderId = $transactionData['order_id'] ?? 'ORDER_' . time();
            $amount = $transactionData['amount'];
            $customerId = $transactionData['customer_id'] ?? 'CUST_' . time();
            $customerEmail = $transactionData['customer_email'] ?? '';
            $customerMobile = $transactionData['customer_mobile'] ?? '';
            $callbackUrl = $transactionData['callback_url'] ?? $this->callbackUrl;

            $params = [
                'MID' => $this->merchantId,
                'ORDER_ID' => $orderId,
                'CUST_ID' => $customerId,
                'INDUSTRY_TYPE_ID' => $this->industryType,
                'CHANNEL_ID' => $this->channelId,
                'TXN_AMOUNT' => number_format($amount, 2, '.', ''),
                'WEBSITE' => $this->websiteName,
                'EMAIL' => $customerEmail,
                'MOBILE_NO' => $customerMobile,
                'CALLBACK_URL' => $callbackUrl,
                'PAYMENT_MODE_ONLY' => 'YES',
                'AUTH_MODE' => 'USRPWD',
                'PAYMENT_TYPE_ID' => 'UPI',
                'MERCHANT_KEY' => $this->merchantKey
            ];

            $checksum = $this->generateChecksum($params);
            $params['CHECKSUMHASH'] = $checksum;


            return [
                'success' => true,
                'order_id' => $orderId,
                'amount' => $amount,
                'params' => $params,
                'form_url' => $this->baseUrl . '/theia/processTransaction',
                'checksum' => $checksum
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Paytm transaction creation failed',
                'error' => $e->getMessage()
            ];
        }
    }

    
    public function verifyTransaction(array $responseData): array
    {
        try {
            $orderId = $responseData['ORDERID'];
            $txnId = $responseData['TXNID'] ?? null;
            $txnAmount = $responseData['TXNAMOUNT'] ?? null;
            $paymentMode = $responseData['PAYMENTMODE'] ?? null;
            $currency = $responseData['CURRENCY'] ?? null;
            $txnDate = $responseData['TXNDATE'] ?? null;
            $responseCode = $responseData['RESPCODE'] ?? null;
            $responseMsg = $responseData['RESPMSG'] ?? null;
            $gatewayName = $responseData['GATEWAYNAME'] ?? null;
            $bankTxnId = $responseData['BANKTXNID'] ?? null;
            $bankName = $responseData['BANKNAME'] ?? null;
            $checksumHash = $responseData['CHECKSUMHASH'] ?? null;

            $isValidChecksum = $this->verifyChecksum($responseData, $checksumHash);

            if (!$isValidChecksum) {
                

                return [
                    'success' => false,
                    'message' => 'Checksum verification failed',
                    'order_id' => $orderId
                ];
            }

            $isSuccess = $responseCode === '01' && $responseMsg === 'Txn Success';

            

            return [
                'success' => $isSuccess,
                'order_id' => $orderId,
                'transaction_id' => $txnId,
                'amount' => $txnAmount,
                'payment_mode' => $paymentMode,
                'currency' => $currency,
                'transaction_date' => $txnDate,
                'response_code' => $responseCode,
                'response_message' => $responseMsg,
                'gateway_name' => $gatewayName,
                'bank_transaction_id' => $bankTxnId,
                'bank_name' => $bankName,
                'is_checksum_valid' => $isValidChecksum
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Transaction verification failed',
                'error' => $e->getMessage()
            ];
        }
    }

    
    public function getTransactionStatus(string $orderId): array
    {
        try {
            $params = [
                'MID' => $this->merchantId,
                'ORDERID' => $orderId,
                'CHECKSUMHASH' => ''
            ];

            $checksum = $this->generateChecksum($params);
            $params['CHECKSUMHASH'] = $checksum;

            $response = Http::post($this->baseUrl . '/merchant-status/getTxnStatus', $params);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'transaction_status' => $data
                ];
            } else {

                return [
                    'success' => false,
                    'message' => 'Failed to get transaction status',
                    'error' => $response->json()
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to get transaction status',
                'error' => $e->getMessage()
            ];
        }
    }

    
    public function initiateRefund(string $orderId, string $txnId, float $refundAmount, string $refundId = null): array
    {
        try {
            $refundId = $refundId ?? 'REFUND_' . time();
            
            $params = [
                'MID' => $this->merchantId,
                'ORDERID' => $orderId,
                'TXNID' => $txnId,
                'REFUNDAMOUNT' => number_format($refundAmount, 2, '.', ''),
                'REFID' => $refundId,
                'CHECKSUMHASH' => ''
            ];

            $checksum = $this->generateChecksum($params);
            $params['CHECKSUMHASH'] = $checksum;

            $response = Http::post($this->baseUrl . '/refund/HANDLER_INTERNAL/REFUND', $params);

            if ($response->successful()) {
                $data = $response->json();
                
                

                return [
                    'success' => true,
                    'refund_id' => $refundId,
                    'order_id' => $orderId,
                    'txn_id' => $txnId,
                    'refund_amount' => $refundAmount,
                    'refund_status' => $data
                ];
            } else {

                return [
                    'success' => false,
                    'message' => 'Refund initiation failed',
                    'error' => $response->json()
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Refund initiation failed',
                'error' => $e->getMessage()
            ];
        }
    }
}
