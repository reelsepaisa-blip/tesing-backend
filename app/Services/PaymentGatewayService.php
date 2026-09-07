<?php

namespace App\Services;

use App\Jobs\ReleaseDriverPayout;
use App\Models\Booking;
use App\Models\SystemConfiguration;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use Illuminate\Support\Carbon;
use Exception;

class PaymentGatewayService
{
    protected $razorpayService;
    protected $stripeService;

    public function __construct()
    {
        $this->razorpayService = app(RazorpayService::class);
        $this->stripeService = app(StripeService::class);
    }

    public function processPayment(Booking $booking, array $paymentData): array
    {
        $paymentMethod = $paymentData['payment_method'];
        $amount = $paymentData['amount'];
        $currency = $paymentData['currency'] ?? 'INR';



        try {
            DB::beginTransaction();

            $transaction = Transaction::create([
                'transaction_id' => 'TXN_' . time() . '_' . rand(1000, 9999),
                'wallet_id' => null,
                'booking_id' => $booking->id,
                'user_id' => $booking->user_id,
                'type' => 'payment',
                'amount' => $amount,
                'balance' => 0,
                'currency' => $currency,
                'payment_method' => $paymentMethod,
                'status' => 'pending',
                'description' => "Payment for ride #{$booking->booking_code}",
                'gateway_transaction_id' => null,
                'gateway_response' => null,
                'meta_data' => $paymentData,
            ]);

            $result = match ($paymentMethod) {
                'razorpay' => $this->processRazorpayPayment($booking, $transaction, $paymentData),
                'stripe' => $this->processStripePayment($booking, $transaction, $paymentData),
                'wallet' => $this->processWalletPayment($booking, $transaction, $paymentData),
                'cash' => $this->processCashPayment($booking, $transaction, $paymentData),
                default => throw new Exception("Unsupported payment method: {$paymentMethod}")
            };

            if ($result['success']) {
                $transaction->update([
                    'status' => 'completed',
                    'gateway_transaction_id' => $result['transaction_id'] ?? null,
                    'gateway_response' => $result,
                    'processed_at' => now(),
                ]);

                $booking->update([
                    'payment_status' => 'paid',
                    'payment_method' => $paymentMethod,
                    'total_amount' => $amount,
                ]);

                $this->processDriverPayout($booking, $amount, $paymentMethod);

                DB::commit();



                return [
                    'success' => true,
                    'transaction_id' => $transaction->id,
                    'gateway_transaction_id' => $result['transaction_id'] ?? null,
                    'message' => 'Payment processed successfully',
                    'data' => $result
                ];
            } else {
                $transaction->update([
                    'status' => 'failed',
                    'gateway_response' => $result,
                    'failed_at' => now(),
                ]);

                DB::rollBack();

                return [
                    'success' => false,
                    'transaction_id' => $transaction->id,
                    'message' => $result['message'] ?? 'Payment processing failed',
                    'error' => $result['error'] ?? null
                ];
            }
        } catch (Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Payment processing failed',
                'error' => $e->getMessage()
            ];
        }
    }

    protected function processRazorpayPayment(Booking $booking, Transaction $transaction, array $paymentData): array
    {
        try {
            return $this->razorpayService->processPayment([
                'amount' => $paymentData['amount'],
                'currency' => $paymentData['currency'] ?? 'INR',
                'order_id' => $booking->booking_code,
                'customer_id' => $booking->user_id,
                'description' => "Payment for ride #{$booking->booking_code}",
                'payment_token' => $paymentData['payment_token'] ?? null,
            ]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Razorpay payment failed',
                'error' => $e->getMessage()
            ];
        }
    }

    protected function processStripePayment(Booking $booking, Transaction $transaction, array $paymentData): array
    {
        try {
            return $this->stripeService->processPayment([
                'amount' => $paymentData['amount'],
                'currency' => $paymentData['currency'] ?? 'inr',
                'customer_id' => $booking->user_id,
                'description' => "Payment for ride #{$booking->booking_code}",
                'payment_token' => $paymentData['payment_token'] ?? null,
            ]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Stripe payment failed',
                'error' => $e->getMessage()
            ];
        }
    }

    protected function processWalletPayment(Booking $booking, Transaction $transaction, array $paymentData): array
    {
        $user = $booking->user;
        $wallet = $user->wallet;
        $amount = $paymentData['amount'];

        if (!$wallet || $wallet->balance < $amount) {
            return [
                'success' => false,
                'message' => 'Insufficient wallet balance',
                'current_balance' => $wallet ? $wallet->balance : 0
            ];
        }

        try {
            $wallet->decrement('balance', $amount);
            $wallet->refresh();  // Refresh to get updated balance

            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance' => $wallet->balance,
                'description' => "Payment for ride #{$booking->booking_code}",
                'reference_type' => 'App\Models\Booking',
                'reference_id' => $booking->id,
                'status' => 'completed',
            ]);

            return [
                'success' => true,
                'transaction_id' => "WALLET_{$transaction->id}",
                'message' => 'Wallet payment successful',
                'remaining_balance' => $wallet->balance
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Wallet payment failed',
                'error' => $e->getMessage()
            ];
        }
    }

    protected function processCashPayment(Booking $booking, Transaction $transaction, array $paymentData): array
    {
        return [
            'success' => true,
            'transaction_id' => "CASH_{$transaction->id}",
            'message' => 'Cash payment recorded',
            'status' => 'pending_collection'
        ];
    }

    public function processDriverPayout(Booking $booking, float $amount, ?string $paymentMethod = null): void
    {
        if (!$booking->driver_id) {
            return;
        }

        $releaseAt = $this->calculateDriverPayoutReleaseTime($booking);

        if ($releaseAt) {
            $booking->update([
                'driver_payout_status' => Booking::DRIVER_PAYOUT_SCHEDULED,
                'driver_payout_scheduled_at' => $releaseAt,
            ]);

            ReleaseDriverPayout::dispatch($booking->id, $amount, $paymentMethod)
                ->delay($releaseAt);


            return;
        }

        $this->releaseDriverPayoutNow($booking, $amount, $paymentMethod);
    }

    protected function calculateDriverPayoutReleaseTime(Booking $booking): ?Carbon
    {
        $requiredHours = $this->getRefundRequiredHours();

        if ($requiredHours <= 0) {
            return null;
        }

        $completedAt = $booking->completed_at?->copy() ?? Carbon::now();
        $releaseAt = $completedAt->copy()->addHours($requiredHours);

        return $releaseAt->isFuture() ? $releaseAt : null;
    }

    protected function getRefundRequiredHours(): int
    {
        return (int) SystemConfiguration::getValue('refund_required_hours', 0);
    }

    public function releaseDriverPayoutNow(Booking $booking, float $amount, ?string $paymentMethod = null): void
    {
        if (!$booking->driver_id) {
            return;
        }

        // Allow re-processing if payout is marked completed but wallet_transaction_id is missing
        if ($booking->driver_payout_status === Booking::DRIVER_PAYOUT_COMPLETED && $booking->wallet_transaction_id) {

            return;
        }

        // If payout is marked completed but wallet_transaction_id is missing, log and continue
        if ($booking->driver_payout_status === Booking::DRIVER_PAYOUT_COMPLETED && !$booking->wallet_transaction_id) {
        }

        // Skip payout if booking has been refunded - driver should not receive payout for refunded bookings
        if ($booking->payment_status === 'refunded') {

            return;
        }

        // Check if there are refund transactions in transactions table
        $hasRefundTransaction = $booking->transactions()
            ->where('type', 'refund')
            ->where('status', 'completed')
            ->exists();

        if ($hasRefundTransaction) {

            return;
        }

        // Check if there are booking_refund wallet transactions for this booking
        // Refunds are stored in wallet_transactions with type 'booking_refund' and booking_id in meta_data
        $hasBookingRefund = \App\Models\WalletTransaction::where('type', \App\Models\WalletTransaction::TYPE_BOOKING_REFUND)
            ->where('status', 'completed')
            ->whereRaw('CAST(JSON_EXTRACT(meta_data, "$.booking_id") AS UNSIGNED) = ?', [$booking->id])
            ->exists();

        if ($hasBookingRefund) {

            return;
        }

        if (!$paymentMethod) {
            $latestPaymentTransaction = $booking
                ->transactions()
                ->where('type', 'payment')
                ->latest()
                ->first();

            $paymentMethod = $latestPaymentTransaction->payment_method
                ?? $booking->payment_method
                ?? 'cash';
        }

        // Normalize payment method to ensure consistent comparison
        $paymentMethod = strtolower(trim($paymentMethod));

        // Handle split payments - check original_payment_method from meta_data
        if ($paymentMethod === 'split') {
            $metaData = $booking->meta_data ?? [];
            if (is_string($metaData)) {
                $metaData = json_decode($metaData, true) ?? [];
            }
            $originalPaymentMethod = $metaData['original_payment_method'] ?? null;

            if ($originalPaymentMethod) {
                $paymentMethod = strtolower(trim($originalPaymentMethod));
            } else {
                // If no original_payment_method found, check if there's an online payment transaction
                $onlineTransaction = $booking->transactions()
                    ->where('type', 'payment')
                    ->whereIn('payment_method', ['razorpay', 'stripe', 'card', 'upi', 'netbanking'])
                    ->latest()
                    ->first();

                if ($onlineTransaction) {
                    $paymentMethod = strtolower(trim($onlineTransaction->payment_method));
                } else {
                    // For split payments, if wallet was used, treat as non-cash
                    // Driver should still get payout for split payments (wallet + online)
                    $paymentMethod = 'wallet'; // Default to wallet for split payments

                }
            }
        }

        // Skip adding to driver wallet for cash payments - driver collects cash directly
        if ($paymentMethod === 'cash') {

            return;
        }

        // Ensure this is a non-cash payment method
        $nonCashPaymentMethods = ['razorpay', 'stripe', 'wallet', 'card', 'upi', 'netbanking', 'paypal', 'split'];
        if (!in_array($paymentMethod, $nonCashPaymentMethods)) {

            return;
        }

        $driver = $booking->driver;

        // Use booking's already calculated driver_amount if it exists
        // This ensures consistency with the original commission calculation
        // (which may be based on subtotal before tax, not total_amount)
        if ($booking->driver_amount && $booking->driver_amount > 0) {
            $driverAmount = $booking->driver_amount;
            $commissionData = [
                'commission_rate' => $booking->admin_commission_rate ?? 20.0,
                'commission_amount' => $booking->admin_commission ?? ($amount - $driverAmount),
            ];
        } else {
            // Only recalculate if driver_amount is not set
            $commissionService = app(CommissionService::class);
            $commissionData = $commissionService->calculateCommission($booking, $amount);
            $driverAmount = $amount - $commissionData['commission_amount'];
        }

        // Check for approved refunds from driver wallet and deduct from driver amount
        $refundRequest = \App\Models\RefundRequest::where('booking_id', $booking->id)
            ->where('refund_source', \App\Models\RefundRequest::SOURCE_DRIVER_WALLET)
            ->whereIn('status', [
                \App\Models\RefundRequest::STATUS_APPROVED,
                \App\Models\RefundRequest::STATUS_PARTIALLY_APPROVED
            ])
            ->whereNotNull('approved_amount')
            ->where('approved_amount', '>', 0)
            ->first();

        $refundAmount = 0;
        if ($refundRequest && $refundRequest->approved_amount > 0) {
            $refundAmount = (float) $refundRequest->approved_amount;
            $originalDriverAmount = $driverAmount;
            $driverAmount = max(0, $driverAmount - $refundAmount);



            // If driver amount becomes 0 or negative after refund deduction, skip payout
            if ($driverAmount <= 0) {

                return;
            }
        }

        $driverWallet = $driver->wallet;
        if (!$driverWallet) {
            $driverWallet = Wallet::create([
                'user_id' => $driver->id,
                'balance' => 0,
            ]);
        }

        // IMPORTANT: For non-cash payments (Razorpay, Stripe, Wallet):
        // - Credit driver amount (after commission and refund deductions) to driver wallet
        // - Commission is NOT deducted from driver wallet (it's already excluded from driverAmount)
        // - Refund amounts from driver wallet are deducted from driver_amount before crediting
        // - Commission is added to admin wallet separately in processWalletTransactions

        $driverWallet->increment('balance', $driverAmount);
        $driverWallet->refresh();

        $paymentType = match ($paymentMethod) {
            'card', 'razorpay', 'stripe' => 'card',
            'upi', 'netbanking' => 'upi',
            'wallet' => 'wallet',
            default => 'cash'
        };

        $walletTransaction = WalletTransaction::create([
            'wallet_id' => $driverWallet->id,
            'type' => 'credit',
            'payment_type' => $paymentType,
            'amount' => $driverAmount,
            'balance' => $driverWallet->balance,
            'description' => "Earnings from ride #{$booking->booking_code}",
            'reference_type' => 'App\Models\Booking',
            'reference_id' => $booking->id,
            'status' => 'completed',
        ]);

        $transactionPaymentMethod = match ($paymentMethod) {
            'card', 'razorpay', 'stripe' => 'card',
            'upi', 'netbanking' => 'upi',
            'wallet' => 'wallet',
            default => 'cash'
        };

        $transaction = Transaction::create([
            'transaction_id' => 'TXN_' . time() . '_' . rand(1000, 9999),
            'wallet_id' => $driverWallet->id,
            'user_id' => $driver->id,
            'booking_id' => $booking->id,
            'type' => 'credit',
            'amount' => $driverAmount,
            'balance' => $driverWallet->balance,
            'description' => "Earnings from ride #{$booking->booking_code}",
            'status' => 'completed',
            'payment_method' => $transactionPaymentMethod,
            'reference_id' => $booking->id,
            'reference_type' => 'App\Models\Booking',
            'meta_data' => [
                'booking_code' => $booking->booking_code,
                'driver_id' => $driver->id,
                'driver_amount' => $driverAmount,
                'commission_amount' => $commissionData['commission_amount'],
                'commission_rate' => $commissionData['commission_rate'],
                'total_amount' => $amount,
                'wallet_transaction_id' => $walletTransaction->id,
            ],
        ]);

        if ($walletTransaction && $transaction) {
            $walletTransaction->update(['payment_type' => $transaction->payment_method]);
        }

        // Add commission to admin wallet
        if ($commissionData['commission_amount'] > 0) {
            $adminUser = \App\Models\User::find(1);
            if ($adminUser) {
                // Ensure admin wallet exists
                if (!$adminUser->wallet) {
                    \App\Models\Wallet::create([
                        'user_id' => $adminUser->id,
                        'balance' => 0,
                    ]);
                    $adminUser->refresh();
                }

                $adminWallet = $adminUser->wallet;
                $adminWallet->increment('balance', $commissionData['commission_amount']);

                // Add wallet transaction for admin
                WalletTransaction::create([
                    'wallet_id' => $adminWallet->id,
                    'type' => 'credit',
                    // commission_received is not standard in WalletTransaction TYPE constants seen so far,
                    // but looking at other files: TYPE_DRIVER_COMMISSION is used for DEBITING driver.
                    // We should check if there is a TYPE_COMMISSION_CREDIT or similar, or just use 'commission'.
                    // Based on PaymentController.php L686: \App\Models\WalletTransaction::TYPE_DRIVER_COMMISSION is used.
                    // Let's use TYPE_DRIVER_COMMISSION with credit amount.
                    'type' => \App\Models\WalletTransaction::TYPE_DRIVER_COMMISSION ?? 'driver_commission',
                    'amount' => $commissionData['commission_amount'],
                    'balance' => $adminWallet->balance,
                    'description' => "Commission from ride #{$booking->booking_code}",
                    'reference_type' => 'App\Models\Booking',
                    'reference_id' => $booking->id,
                    'status' => 'completed',
                    'meta_data' => [
                        'booking_code' => $booking->booking_code,
                        'driver_id' => $booking->driver_id,
                        'driver_amount' => $driverAmount,
                        'commission_amount' => $commissionData['commission_amount'],
                        'commission_rate' => $commissionData['commission_rate'],
                        'total_amount' => $amount,
                    ],
                ]);
            }
        }

        // Update booking - preserve existing driver_amount and admin_commission if already set
        $updateData = [
            'driver_payout_status' => Booking::DRIVER_PAYOUT_COMPLETED,
            'driver_payout_released_at' => now(),
            'driver_payout_scheduled_at' => null,
            'wallet_transaction_id' => $walletTransaction->id,
        ];

        // Only update commission fields if they weren't already set
        if (!$booking->admin_commission || $booking->admin_commission == 0) {
            $updateData['admin_commission'] = $commissionData['commission_amount'];
        }
        if (!$booking->admin_commission_rate) {
            $updateData['admin_commission_rate'] = $commissionData['commission_rate'];
        }
        if (!$booking->driver_amount || $booking->driver_amount == 0) {
            $updateData['driver_amount'] = $driverAmount;
        }

        $booking->update($updateData);
    }

    public function handleWebhook(string $gateway, array $webhookData): array
    {


        try {
            $result = match ($gateway) {
                'razorpay' => $this->razorpayService->handleWebhook($webhookData),
                'stripe' => $this->stripeService->handleWebhook($webhookData),
                default => throw new Exception("Unsupported gateway: {$gateway}")
            };

            if ($result['success'] && isset($result['transaction_id'])) {
                $this->updateTransactionFromWebhook($result);
            }

            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Webhook processing failed',
                'error' => $e->getMessage()
            ];
        }
    }

    protected function updateTransactionFromWebhook(array $webhookResult): void
    {
        $transaction = Transaction::where('gateway_transaction_id', $webhookResult['transaction_id'])
            ->orWhere('gateway_transaction_id', $webhookResult['order_id'] ?? null)
            ->orWhere('gateway_transaction_id', $webhookResult['payment_link_id'] ?? null)
            ->first();

        if (!$transaction) {

            return;
        }

        $existingGatewayResponse = $transaction->gateway_response ?? [];
        if (is_string($existingGatewayResponse)) {
            $existingGatewayResponse = json_decode($existingGatewayResponse, true) ?? [];
        }

        $transaction->update([
            'status' => $webhookResult['status'],
            'gateway_response' => array_merge($existingGatewayResponse, [
                'webhook_response' => $webhookResult,
                'webhook_processed_at' => now()->toISOString()
            ]),
            'processed_at' => now(),
        ]);

        if ($webhookResult['status'] === 'completed' && $transaction->booking->payment_status !== 'paid') {
            $transaction->booking->update([
                'payment_status' => 'paid',
                'payment_method' => $transaction->payment_method,
                'online_paid_amount' => $transaction->amount,
                'total_amount' => $transaction->amount,
            ]);

            if (!$transaction->booking->driver_amount) {
                $this->processDriverPayout($transaction->booking, $transaction->amount, $transaction->payment_method);
            }
        }
    }

    public function refundPayment(Transaction $transaction, float $refundAmount = null, string $refundReason = null): array
    {
        $refundAmount = $refundAmount ?? $transaction->amount;
        $refundReason = $refundReason ?? 'Booking cancellation';

        if ($refundAmount > $transaction->amount) {
            return [
                'success' => false,
                'message' => 'Insufficient balance. Refund amount (₹' . number_format($refundAmount, 2) . ') cannot exceed the original transaction amount (₹' . number_format($transaction->amount, 2) . ').',
                'error' => 'Refund amount exceeds transaction amount'
            ];
        }

        if ($refundAmount <= 0) {
            return [
                'success' => false,
                'message' => 'Refund amount must be greater than zero.',
                'error' => 'Invalid refund amount'
            ];
        }

        // Load booking relationship if not already loaded
        if (!$transaction->relationLoaded('booking')) {
            $transaction->load('booking');
        }



        try {
            $result = match ($transaction->payment_method) {
                'razorpay' => $this->razorpayService->refundPayment($transaction->gateway_transaction_id, $refundAmount),
                'stripe' => $this->stripeService->refundPayment($transaction->gateway_transaction_id, $refundAmount),
                'wallet' => $this->refundWalletPayment($transaction, $refundAmount),
                'cash' => $this->refundCashPayment($transaction, $refundAmount),
                default => throw new Exception("Refund not supported for payment method: {$transaction->payment_method}")
            };

            if ($result['success']) {
                // Get booking code safely
                $bookingCode = $transaction->booking ? ($transaction->booking->booking_code ?? $transaction->booking_id) : ($transaction->booking_id ?? 'N/A');

                $refundTransaction = Transaction::create([
                    'transaction_id' => 'REFUND_' . time() . '_' . rand(1000, 9999),
                    'wallet_id' => null,
                    'booking_id' => $transaction->booking_id,
                    'user_id' => $transaction->user_id,
                    'type' => 'refund',
                    'amount' => $refundAmount,
                    'balance' => 0,
                    'currency' => $transaction->currency,
                    'payment_method' => $transaction->payment_method,
                    'status' => 'completed',
                    'description' => "Refund for booking #{$bookingCode}",
                    'gateway_transaction_id' => $result['refund_id'] ?? null,
                    'gateway_response' => $result,
                    'processed_at' => now(),
                    'meta_data' => [
                        'original_transaction_id' => $transaction->id,
                        'refund_reason' => $refundReason
                    ],
                ]);

                $result['transaction_id'] = $refundTransaction->id;
            }

            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Refund processing failed',
                'error' => $e->getMessage()
            ];
        }
    }

    protected function refundWalletPayment(Transaction $transaction, float $refundAmount): array
    {
        // Load booking relationship if not already loaded
        if (!$transaction->relationLoaded('booking')) {
            $transaction->load('booking');
        }

        $user = User::find($transaction->user_id);

        if (!$user) {
            throw new Exception("User not found for transaction {$transaction->id}");
        }

        $wallet = $user->wallet;

        if (!$wallet) {
            $wallet = Wallet::create([
                'user_id' => $user->id,
                'balance' => 0,
            ]);
        }

        $wallet->increment('balance', $refundAmount);
        $wallet->refresh();  // Refresh to get updated balance

        // Get booking code safely
        $bookingCode = $transaction->booking ? ($transaction->booking->booking_code ?? $transaction->booking_id) : ($transaction->booking_id ?? 'N/A');

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => $refundAmount,
            'balance' => $wallet->balance,
            'description' => "Refund for booking #{$bookingCode}",
            'reference_type' => 'App\Models\Transaction',
            'reference_id' => $transaction->id,
            'status' => 'completed',
        ]);

        return [
            'success' => true,
            'refund_id' => "WALLET_REFUND_{$transaction->id}",
            'message' => 'Wallet refund successful',
            'new_balance' => $wallet->balance
        ];
    }

    protected function refundCashPayment(Transaction $transaction, float $refundAmount): array
    {
        return $this->refundWalletPayment($transaction, $refundAmount);
    }

    public function getAvailablePaymentMethods(User $user): array
    {
        $methods = [
            [
                'id' => 'cash',
                'name' => 'Cash',
                'icon' => 'cash-icon',
                'description' => 'Pay with cash after ride',
                'is_available' => true,
                'fee' => 0,
            ],
            [
                'id' => 'wallet',
                'name' => 'Wallet',
                'icon' => 'wallet-icon',
                'description' => 'Pay from your wallet balance',
                'is_available' => $user->wallet && $user->wallet->balance > 0,
                'balance' => $user->wallet ? $user->wallet->balance : 0,
                'fee' => 0,
            ],
        ];

        if (\App\Services\ConfigurationService::isServiceConfigured('razorpay')) {
            $methods[] = [
                'id' => 'razorpay',
                'name' => 'UPI / Card / Net Banking',
                'icon' => 'razorpay-icon',
                'description' => 'Pay with UPI, Card or Net Banking',
                'is_available' => true,
                'fee' => config('services.razorpay.fee', 0),
            ];
        }

        if (\App\Services\ConfigurationService::isServiceConfigured('stripe')) {
            $methods[] = [
                'id' => 'stripe',
                'name' => 'Credit / Debit Card',
                'icon' => 'stripe-icon',
                'description' => 'Pay with Credit or Debit Card',
                'is_available' => true,
                'fee' => config('services.stripe.fee', 0),
            ];
        }

        return $methods;
    }
}
