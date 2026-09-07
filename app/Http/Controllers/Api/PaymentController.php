<?php

namespace App\Http\Controllers\Api;

use App\Events\CashPaymentStatusUpdated;
use App\Events\PaymentSuccess;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\City;
use App\Models\Commission;
use App\Models\IssueReport;
use App\Models\PromoCode;
use App\Models\PromoUsage;
use App\Models\Refund;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\UpiAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\NotificationService;
use App\Services\PaymentGatewayService;
use App\Services\UserDebtService;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    private function sanitizeForApi($value)
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof Model) {
            return $this->sanitizeForApi($value->toArray());
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $v) {
                $sanitized[$key] = $this->sanitizeForApi($v);
            }
            return $sanitized;
        }

        if (is_object($value)) {
            $sanitized = [];
            foreach (get_object_vars($value) as $key => $v) {
                $sanitized[$key] = $this->sanitizeForApi($v);
            }
            return $sanitized;
        }

        return $value;
    }

    private function mapGatewayStatusToInternal($gatewayStatus, $paymentMethod)
    {
        switch ($paymentMethod) {
            case 'razorpay':
                switch (strtolower($gatewayStatus)) {
                    case 'captured':
                    case 'paid':
                        return 'completed';
                    case 'failed':
                    case 'cancelled':
                        return 'failed';
                    case 'authorized':
                    case 'pending':
                    default:
                        return 'pending';
                }

            case 'stripe':
                switch (strtolower($gatewayStatus)) {
                    case 'succeeded':
                    case 'completed':
                        return 'completed';
                    case 'requires_payment_method':
                    case 'requires_action':
                    case 'canceled':
                    case 'failed':
                        return 'failed';
                    case 'processing':
                    case 'requires_confirmation':
                    default:
                        return 'pending';
                }

            case 'paytm':
                switch (strtoupper($gatewayStatus)) {
                    case 'TXN_SUCCESS':
                        return 'completed';
                    case 'TXN_FAILURE':
                    case 'TXN_CANCELLED':
                        return 'failed';
                    case 'PENDING':
                    default:
                        return 'pending';
                }

            default:
                return 'pending';
        }
    }

    private function updateTransactionStatus($transaction, $gatewayStatus, $paymentMethod, $gatewayResponse = [])
    {
        $internalStatus = $this->mapGatewayStatusToInternal($gatewayStatus, $paymentMethod);

        try {
            DB::beginTransaction();

            $existingGatewayResponse = $transaction->gateway_response ?? [];

            if (is_string($existingGatewayResponse)) {
                $existingGatewayResponse = json_decode($existingGatewayResponse, true) ?? [];
            }

            $transaction->update([
                'status' => $internalStatus,
                'gateway_response' => array_merge($existingGatewayResponse, [
                    'callback_response' => $gatewayResponse,
                    'gateway_status' => $gatewayStatus,
                    'mapped_status' => $internalStatus,
                    'updated_at' => now()->toISOString()
                ])
            ]);

            if ($transaction->booking_id) {
                $booking = Booking::find($transaction->booking_id);
                if ($booking) {
                    $bookingUpdates = [];

                    // Extract tip_amount from transaction meta_data
                    $transactionMetaData = $transaction->meta_data ?? [];
                    if (is_string($transactionMetaData)) {
                        $transactionMetaData = json_decode($transactionMetaData, true) ?? [];
                    }
                    $tipAmount = isset($transactionMetaData['tip_amount'])
                        ? round(max((float) $transactionMetaData['tip_amount'], 0), 2)
                        : 0.0;

                    switch ($internalStatus) {
                        case 'completed':
                            // Check if this is a split payment with wallet contribution that needs to be deducted
                            $isSplitPayment = isset($transactionMetaData['is_split_payment']) && $transactionMetaData['is_split_payment'] === true;
                            $walletContribution = isset($transactionMetaData['wallet_amount']) ? (float) $transactionMetaData['wallet_amount'] : 0;
                            $primaryPaymentMethod = $transactionMetaData['primary_payment_method'] ?? $paymentMethod;

                            // Check if this is an online gateway payment (razorpay/stripe)
                            // Check multiple sources: primary_payment_method from meta_data, paymentMethod parameter, and transaction's payment_method
                            $isOnlineGateway = in_array($primaryPaymentMethod, ['razorpay', 'stripe'])
                                || in_array($paymentMethod, ['razorpay', 'stripe'])
                                || in_array(strtolower($transaction->payment_method ?? ''), ['razorpay', 'stripe', 'card']);

                            // For split payments with online gateways (razorpay/stripe), deduct wallet now that payment succeeded

                            if ($isSplitPayment && $walletContribution > 0 && $isOnlineGateway) {
                                try {
                                    $user = $transaction->user;
                                    if ($user && $user->wallet) {
                                        $wallet = $user->wallet;

                                        // Safety check: Ensure this is the customer's wallet, not driver's wallet
                                        // The booking's user_id should match the transaction's user_id
                                        if ($booking->user_id !== $transaction->user_id) {

                                            throw new \Exception('Cannot deduct wallet: User mismatch between booking and transaction');
                                        }

                                        // Check if wallet was already deducted (shouldn't happen, but safety check)
                                        $existingWalletTransaction = \App\Models\WalletTransaction::where('wallet_id', $wallet->id)
                                            ->where('reference_type', 'App\Models\Booking')
                                            ->where('reference_id', $booking->id)
                                            ->where('amount', -$walletContribution)
                                            ->first();

                                        if (!$existingWalletTransaction) {
                                            $wallet->decrement('balance', $walletContribution);
                                            $wallet->refresh();

                                            $walletTransaction = \App\Models\WalletTransaction::create([
                                                'wallet_id' => $wallet->id,
                                                'type' => 'debit',
                                                'amount' => -$walletContribution,
                                                'balance' => $wallet->balance,
                                                'description' => "Split payment wallet deduction for ride #{$booking->booking_code}",
                                                'reference_type' => 'App\Models\Booking',
                                                'reference_id' => $booking->id,
                                                'status' => 'completed',
                                                'meta_data' => [
                                                    'booking_id' => $booking->id,
                                                    'booking_code' => $booking->booking_code,
                                                    'is_split_payment' => true,
                                                    'primary_payment_method' => $primaryPaymentMethod,
                                                    'gateway_payment_method' => $paymentMethod,
                                                    'tip_amount' => $tipAmount,
                                                    'fare_amount' => $transactionMetaData['fare_amount'] ?? 0,
                                                    'total_amount' => $transactionMetaData['requested_amount'] ?? $transaction->amount + $walletContribution,
                                                    'deducted_after_gateway_success' => true,
                                                    'transaction_id' => $transaction->transaction_id,
                                                ],
                                            ]);
                                        } else {
                                        }
                                    } else {
                                    }
                                } catch (\Exception $e) {
                                    // Don't fail the transaction if wallet deduction fails, but log it
                                }
                            } else {
                            }

                            $bookingUpdates = [
                                'payment_status' => 'paid',
                                'payment_method' => $paymentMethod,
                                'online_paid_amount' => $transaction->amount,
                                'total_amount' => $transaction->amount,
                            ];

                            // Include wallet_amount in booking update for split payments
                            if ($isSplitPayment && $walletContribution > 0) {
                                $bookingUpdates['wallet_amount'] = $walletContribution;
                                // Set payment_method to 'split' for split payments
                                $bookingUpdates['payment_method'] = 'split';
                                // Store original gateway method in meta_data
                                $existingMetaData = $booking->meta_data ?? [];
                                $bookingUpdates['meta_data'] = array_merge($existingMetaData, [
                                    'original_payment_method' => $primaryPaymentMethod,
                                    'is_split_payment' => true,
                                ]);
                                // Update total_amount to include wallet contribution
                                $bookingUpdates['total_amount'] = $transaction->amount + $walletContribution;
                            }

                            // Include tip_amount if it exists and recalculate driver_amount
                            if ($tipAmount > 0) {
                                $bookingUpdates['tip_amount'] = $tipAmount;

                                // Recalculate driver_amount to include tip
                                // driver_amount = (subtotal - commission) + tip_amount
                                $currentDriverAmount = (float) ($booking->driver_amount ?? 0);
                                $currentTipAmount = (float) ($booking->tip_amount ?? 0);
                                $baseDriverAmount = $currentDriverAmount - $currentTipAmount;
                                $bookingUpdates['driver_amount'] = $baseDriverAmount + $tipAmount;

                                // Update total_amount to include tip
                                // For split payments, base total is already set to transaction.amount + walletContribution
                                // For non-split, base total is transaction.amount
                                $baseTotalAmount = $isSplitPayment && $walletContribution > 0
                                    ? ($transaction->amount + $walletContribution)
                                    : $transaction->amount;
                                $currentTotalAmount = (float) ($booking->total_amount ?? 0);
                                $currentTotalWithoutTip = $currentTotalAmount - $currentTipAmount;
                                // Use the base total if current total doesn't account for split payment yet
                                if ($isSplitPayment && $walletContribution > 0 && $currentTotalWithoutTip < $baseTotalAmount) {
                                    $currentTotalWithoutTip = $baseTotalAmount;
                                }
                                $bookingUpdates['total_amount'] = $currentTotalWithoutTip + $tipAmount;
                            }
                            break;

                        case 'failed':
                            $bookingUpdates = [
                                'payment_status' => 'failed',
                                'payment_method' => $paymentMethod,
                            ];
                            break;

                        case 'pending':
                        default:
                            $bookingUpdates = [
                                'payment_status' => 'pending',
                                'payment_method' => $paymentMethod,
                            ];
                            break;
                    }

                    if (!empty($bookingUpdates)) {
                        $booking->update($bookingUpdates);

                        $paymentStatus = $bookingUpdates['payment_status'] ?? null;
                        if ($paymentStatus === 'paid') {
                            $booking->refresh();

                            // IMPORTANT: If payment method changed from cash to non-cash, reverse commission deduction
                            $nonCashPaymentMethods = ['razorpay', 'stripe', 'wallet', 'card', 'upi', 'netbanking', 'paypal'];
                            $paymentMethodNormalized = strtolower(trim($paymentMethod));

                            if (in_array($paymentMethodNormalized, $nonCashPaymentMethods) && $booking->driver_id) {
                                $this->reverseCommissionDeductionIfNeeded($booking, $paymentMethodNormalized);
                            }

                            // IMPORTANT: If payment is cash and commission hasn't been deducted yet, deduct it now
                            if ($paymentMethodNormalized === 'cash' && $booking->driver_id) {
                                $this->deductCommissionForCashPaymentIfNeeded($booking);
                            }

                            app(UserDebtService::class)->settleAppliedDebtsForBooking($booking->fresh());

                            // Credit debt amount to admin wallet when payment is received
                            $this->creditDebtAmountToAdminWallet($booking->fresh());

                            // Process driver payout for payment transactions (only if not already processed)
                            if ($transaction->type === 'payment' && $booking->driver_id && empty($booking->driver_payout_scheduled_at)) {
                                try {
                                    app(PaymentGatewayService::class)->processDriverPayout(
                                        $booking->fresh(),
                                        $transaction->amount,
                                        $paymentMethod
                                    );
                                } catch (\Exception $e) {
                                }
                            }

                            if ($booking->driver_id) {
                                try {
                                    app(NotificationService::class)->sendPaymentCompletionNotificationToDriver($booking->fresh());
                                } catch (\Exception $e) {
                                }
                            }
                        } elseif ($paymentStatus === 'failed') {
                            app(UserDebtService::class)->releaseAppliedDebtsForBooking($booking->fresh());
                        }
                    }
                }
            }

            if ($transaction->type === 'wallet_topup') {
                $transaction->refresh();

                $wallet = null;

                if ($transaction->wallet_id) {
                    $wallet = DB::table('wallets')->where('id', $transaction->wallet_id)->first();
                }

                if (!$wallet && $transaction->user_id) {
                    $wallet = DB::table('wallets')->where('user_id', $transaction->user_id)->first();
                }

                if (!$wallet && $transaction->user) {
                    $wallet = $transaction->user->wallet;
                }

                if (!$wallet) {
                }

                if ($wallet) {
                    $walletTransaction = WalletTransaction::firstOrNew([
                        'wallet_id' => $wallet->id,
                        'reference_type' => Transaction::class,
                        'reference_id' => $transaction->id,
                    ]);

                    $walletTransactionMeta = $walletTransaction->meta_data ?? [];
                    if (is_string($walletTransactionMeta)) {
                        $walletTransactionMeta = json_decode($walletTransactionMeta, true) ?? [];
                    }

                    $walletTransactionMeta = array_merge($walletTransactionMeta, [
                        'payment_method' => $paymentMethod,
                        'gateway_status' => $gatewayStatus,
                        'last_update_at' => now()->toISOString(),
                        'transaction_id' => $transaction->transaction_id,
                    ]);

                    switch ($internalStatus) {
                        case 'completed':
                            if ($walletTransaction->exists && $walletTransaction->status === WalletTransaction::STATUS_COMPLETED) {
                                $walletTransaction->meta_data = $walletTransactionMeta;
                                $walletTransaction->save();
                                break;
                            }

                            $wallet = DB::table('wallets')->where('id', $wallet->id)->first();
                            $walletBalanceBefore = (float) ($wallet->balance ?? 0);

                            if (Schema::hasColumn('wallets', 'balance')) {
                                DB::table('wallets')
                                    ->where('id', $wallet->id)
                                    ->increment('balance', $transaction->amount);
                                if (Schema::hasColumn('wallets', 'total_credit')) {
                                    DB::table('wallets')
                                        ->where('id', $wallet->id)
                                        ->increment('total_credit', $transaction->amount);
                                }
                                DB::table('wallets')
                                    ->where('id', $wallet->id)
                                    ->update(['last_transaction_at' => now()]);
                                $wallet = DB::table('wallets')->where('id', $wallet->id)->first();
                                $newBalance = (float) ($wallet->balance ?? 0);
                            } elseif (Schema::hasColumn('wallets', 'amount')) {
                                $currentAmount = (float) ($wallet->amount ?? 0);
                                $newAmount = $currentAmount + $transaction->amount;
                                DB::table('wallets')
                                    ->where('id', $wallet->id)
                                    ->update(['amount' => $newAmount]);
                                $wallet = DB::table('wallets')->where('id', $wallet->id)->first();
                                $newBalance = (float) ($wallet->amount ?? 0);
                            } else {
                                $newBalance = (float) $transaction->amount;
                            }

                            $walletTransaction->fill([
                                'type' => WalletTransaction::TYPE_WALLET_TOPUP,
                                'driver_id' => $wallet->driver_id ?? $transaction->user_id,
                                'amount' => $transaction->amount,
                                'balance' => $newBalance,
                                'description' => "Wallet top-up via {$paymentMethod}",
                                'status' => WalletTransaction::STATUS_COMPLETED,
                            ]);

                            $walletTransaction->meta_data = array_merge($walletTransactionMeta, [
                                'credited_at' => now()->toISOString(),
                                'wallet_balance_before' => $walletBalanceBefore,
                                'wallet_balance_after' => $newBalance,
                            ]);
                            $walletTransaction->save();

                            $transactionMeta = $transaction->meta_data ?? [];
                            if (is_string($transactionMeta)) {
                                $transactionMeta = json_decode($transactionMeta, true) ?? [];
                            }

                            $transactionMeta = array_merge($transactionMeta, [
                                'wallet_balance_before' => $walletBalanceBefore,
                                'wallet_balance_after' => $newBalance,
                                'wallet_transaction_id' => $walletTransaction->id,
                            ]);

                            $transaction->update([
                                'balance' => $newBalance,
                                'meta_data' => $transactionMeta,
                            ]);


                            break;

                        case 'failed':
                            if ($walletTransaction->status !== WalletTransaction::STATUS_COMPLETED) {
                                $walletTransaction->status = WalletTransaction::STATUS_FAILED;
                                $walletTransaction->meta_data = array_merge($walletTransactionMeta, [
                                    'failed_at' => now()->toISOString(),
                                    'failure_reason' => $gatewayResponse['error'] ?? $gatewayStatus,
                                ]);
                                $walletTransaction->save();
                            }
                            break;

                        case 'pending':
                        default:
                            if ($walletTransaction->status !== WalletTransaction::STATUS_COMPLETED) {
                                $walletTransaction->status = WalletTransaction::STATUS_PENDING;
                                $walletTransaction->meta_data = $walletTransactionMeta;
                                $walletTransaction->save();
                            }
                            break;
                    }
                } else {
                }
            }

            DB::commit();

            if ($internalStatus === 'completed') {
                $this->broadcastPaymentSuccess($transaction);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $internalStatus;
    }

    /**
     * Reverse commission deduction if it was made for cash payment but payment method changed to non-cash
     */
    private function reverseCommissionDeductionIfNeeded(Booking $booking, string $newPaymentMethod): void
    {
        try {
            $driver = $booking->driver;
            if (!$driver) {
                return;
            }

            $driverWallet = $driver->wallet;
            if (!$driverWallet) {
                return;
            }

            // Find commission debit transactions for this booking
            // Try by reference first, then fallback to meta_data
            $commissionDebits = \App\Models\WalletTransaction::where('wallet_id', $driverWallet->id)
                ->where('type', \App\Models\WalletTransaction::TYPE_DRIVER_COMMISSION)
                ->where('amount', '<', 0)  // Debit transactions have negative amounts
                ->where(function ($query) use ($booking) {
                    $query->where(function ($q) use ($booking) {
                        $q
                            ->where('reference_type', 'App\Models\Booking')
                            ->where('reference_id', $booking->id);
                    })->orWhere(function ($q) use ($booking) {
                        // Fallback: search by meta_data (MySQL JSON path)
                        $q
                            ->whereRaw("JSON_EXTRACT(meta_data, '\$.booking_id') = ?", [$booking->id])
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_data, '\$.booking_id')) = ?", [$booking->id]);
                    });
                })
                ->get()
                ->filter(function ($transaction) {
                    // Exclude already reversed transactions
                    $meta = $transaction->meta_data ?? [];
                    if (is_string($meta)) {
                        $meta = json_decode($meta, true) ?? [];
                    }
                    return !($meta['reversed'] ?? false);
                });


            if ($commissionDebits->isEmpty()) {
                // No commission was deducted, nothing to reverse
                return;
            }

            // Check if any of these were for cash payment
            $cashCommissionDebits = $commissionDebits->filter(function ($transaction) {
                $meta = $transaction->meta_data ?? [];
                if (is_string($meta)) {
                    $meta = json_decode($meta, true) ?? [];
                }
                $paymentMethod = strtolower(trim($meta['payment_method'] ?? 'cash'));
                $isCash = $paymentMethod === 'cash';

                return $isCash;
            });


            if ($cashCommissionDebits->isEmpty()) {
                // No cash commission deductions found, nothing to reverse
                return;
            }

            // Reverse each cash commission deduction
            foreach ($cashCommissionDebits as $debitTransaction) {
                $commissionAmount = abs($debitTransaction->amount);

                // Credit the driver wallet to reverse the commission deduction
                $driverWallet->increment('balance', $commissionAmount);
                $driverWallet->refresh();

                // Create a reversal transaction
                \App\Models\WalletTransaction::create([
                    'wallet_id' => $driverWallet->id,
                    'type' => \App\Models\WalletTransaction::TYPE_ADJUSTMENT,
                    'amount' => $commissionAmount,
                    'balance' => $driverWallet->balance,
                    'description' => "Commission reversal: Payment method changed from cash to {$newPaymentMethod} for booking #{$booking->booking_code}",
                    'reference_type' => 'App\Models\Booking',
                    'reference_id' => $booking->id,
                    'status' => 'completed',
                    'meta_data' => [
                        'booking_id' => $booking->id,
                        'booking_code' => $booking->booking_code,
                        'original_commission_transaction_id' => $debitTransaction->id,
                        'original_payment_method' => 'cash',
                        'new_payment_method' => $newPaymentMethod,
                        'reversed_at' => now()->toDateTimeString(),
                    ],
                ]);

                // Mark the original transaction as reversed
                $originalMeta = $debitTransaction->meta_data ?? [];
                if (is_string($originalMeta)) {
                    $originalMeta = json_decode($originalMeta, true) ?? [];
                }
                $debitTransaction->update([
                    'meta_data' => array_merge($originalMeta, [
                        'reversed' => true,
                        'reversed_at' => now()->toDateTimeString(),
                        'reversal_reason' => "Payment method changed from cash to {$newPaymentMethod}",
                    ]),
                ]);
            }
        } catch (\Exception $e) {
        }
    }

    /**
     * Deduct commission for cash payment when payment is actually received
     */
    private function deductCommissionForCashPaymentIfNeeded(Booking $booking): void
    {
        try {


            $driver = $booking->driver;
            if (!$driver) {
                return;
            }

            $driverWallet = $driver->wallet;
            if (!$driverWallet) {
                return;
            }

            // Check if commission has already been deducted
            $existingCommissionDebits = \App\Models\WalletTransaction::where('wallet_id', $driverWallet->id)
                ->where('type', \App\Models\WalletTransaction::TYPE_DRIVER_COMMISSION)
                ->where(function ($query) use ($booking) {
                    $query->where(function ($q) use ($booking) {
                        $q
                            ->where('reference_type', 'App\Models\Booking')
                            ->where('reference_id', $booking->id);
                    })->orWhere(function ($q) use ($booking) {
                        $q
                            ->whereRaw("JSON_EXTRACT(meta_data, '\$.booking_id') = ?", [$booking->id])
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_data, '\$.booking_id')) = ?", [$booking->id]);
                    });
                })
                ->where('amount', '<', 0)
                ->get()
                ->filter(function ($transaction) {
                    // Exclude already reversed transactions
                    $meta = $transaction->meta_data ?? [];
                    if (is_string($meta)) {
                        $meta = json_decode($meta, true) ?? [];
                    }
                    return !($meta['reversed'] ?? false);
                });

            if ($existingCommissionDebits->isNotEmpty()) {
                return;
            }

            // Check if tax has already been deducted
            $existingTaxDebits = \App\Models\WalletTransaction::where('wallet_id', $driverWallet->id)
                ->where('type', \App\Models\WalletTransaction::TYPE_ADJUSTMENT)
                ->where(function ($query) use ($booking) {
                    $query->where(function ($q) use ($booking) {
                        $q
                            ->where('reference_type', 'App\Models\Booking')
                            ->where('reference_id', $booking->id)
                            ->whereRaw("JSON_EXTRACT(meta_data, '$.tax_amount') IS NOT NULL");
                    })->orWhere(function ($q) use ($booking) {
                        $q
                            ->whereRaw("JSON_EXTRACT(meta_data, '\$.booking_id') = ?", [$booking->id])
                            ->whereRaw("JSON_EXTRACT(meta_data, '$.tax_amount') IS NOT NULL");
                    });
                })
                ->where('amount', '<', 0)
                ->get()
                ->filter(function ($transaction) {
                    // Exclude already reversed transactions
                    $meta = $transaction->meta_data ?? [];
                    if (is_string($meta)) {
                        $meta = json_decode($meta, true) ?? [];
                    }
                    return !($meta['reversed'] ?? false);
                });

            // Calculate commission
            $rideTypeCommissionRate = $booking->rideType->commission_rate ?? 20.0;
            $driverCommissionRate = $booking->driver->driverProfile->commission_rate ?? null;
            $platformCommissionRate = $rideTypeCommissionRate ?? $driverCommissionRate;
            $platformCommissionRate = max(0, min(100, $platformCommissionRate));

            $platformCommission = ($booking->total_amount * $platformCommissionRate) / 100;
            $driverAmount = $booking->total_amount - $platformCommission;

            $commissionData = [
                'platform_commission_rate' => $platformCommissionRate,
                'platform_commission' => round($platformCommission, 2),
                'driver_amount' => round($driverAmount, 2),
                'ride_type_commission_rate' => $rideTypeCommissionRate,
                'driver_commission_rate' => $driverCommissionRate,
                'commission_type' => 'percentage',
                'total_amount' => $booking->total_amount,
            ];

            if ($commissionData['platform_commission'] <= 0) {

                return;
            }

            // Deduct commission from driver wallet
            $walletService = app(\App\Services\WalletService::class);
            $walletTransaction = $driverWallet->debit(
                $commissionData['platform_commission'],
                \App\Models\WalletTransaction::TYPE_DRIVER_COMMISSION,
                "Commission deducted for cash booking #{$booking->booking_code}",
                [
                    'booking_id' => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'user_id' => $booking->user_id,
                    'total_amount' => $commissionData['total_amount'],
                    'commission_rate' => $commissionData['platform_commission_rate'],
                    'driver_amount' => $commissionData['driver_amount'],
                    'payment_method' => 'cash',
                    'debited_at' => now()->toDateTimeString(),
                ],
                null,
                true  // Allow negative balance
            );

            $transactionId = 'COMM_DRV_' . time() . '_' . rand(1000, 9999);
            $walletTransaction->update([
                'transection_id' => $transactionId,
                'reference_type' => 'App\Models\Booking',
                'reference_id' => $booking->id,
            ]);

            // Deduct tax amount from driver wallet (tax is paid by admin for COD)
            $taxAmount = (float) ($booking->tax_amount ?? 0);
            if ($taxAmount > 0 && $existingTaxDebits->isEmpty()) {
                $taxTransaction = $driverWallet->debit(
                    $taxAmount,
                    \App\Models\WalletTransaction::TYPE_ADJUSTMENT,
                    "Tax deducted for cash booking #{$booking->booking_code}",
                    [
                        'booking_id' => $booking->id,
                        'booking_code' => $booking->booking_code,
                        'user_id' => $booking->user_id,
                        'tax_amount' => $taxAmount,
                        'payment_method' => 'cash',
                        'debited_at' => now()->toDateTimeString(),
                    ],
                    null,
                    true // Allow negative balance
                );

                $taxTransactionId = 'TAX_DRV_' . time() . '_' . rand(1000, 9999);
                $taxTransaction->update([
                    'transection_id' => $taxTransactionId,
                    'reference_type' => 'App\Models\Booking',
                    'reference_id' => $booking->id,
                ]);
            }

            // Add commission to admin wallet
            $adminUser = \App\Models\User::find(1);
            if ($adminUser) {
                $adminWallet = $walletService->ensureWallet($adminUser);
                $commission = \App\Models\Commission::where('booking_id', $booking->id)->first();

                $adminWallet->credit(
                    $commissionData['platform_commission'],
                    \App\Models\WalletTransaction::TYPE_DRIVER_COMMISSION,
                    "Commission from cash booking #{$booking->booking_code}",
                    [
                        'booking_id' => $booking->id,
                        'booking_code' => $booking->booking_code,
                        'driver_id' => $driver->id,
                        'total_amount' => $commissionData['total_amount'],
                        'commission_rate' => $commissionData['platform_commission_rate'],
                        'driver_amount' => $commissionData['driver_amount'],
                        'payment_method' => 'cash',
                        'commission_id' => $commission ? $commission->id : null,
                    ]
                );
            }
        } catch (\Exception $e) {
        }
    }

    private function getRecentWalletTransactions(int $walletId, int $limit = 10): array
    {
        $transactions = WalletTransaction::where('wallet_id', $walletId)
            ->orderBy('created_at', 'desc')
            ->take($limit)
            ->get()
            ->map(function ($t) {
                return [
                    'id' => $t->id,
                    'type' => $t->type,
                    'amount' => $t->amount,
                    'balance' => $t->balance ?? null,
                    'description' => $t->description,
                    'status' => $t->status,
                    'created_at' => $t->created_at ? $t->created_at->toISOString() : '',
                ];
            })
            ->toArray();

        return $transactions;
    }

    public function getPaymentMethods(Request $request)
    {
        $user = auth()->user();

        $paymentMethods = [
            [
                'id' => 'cash',
                'name' => 'Cash',
                'icon' => 'cash-icon',
                'description' => 'Pay with cash after ride',
                'is_available' => true,
            ],
            [
                'id' => 'wallet',
                'name' => 'Wallet',
                'icon' => 'wallet-icon',
                'description' => 'Pay from your wallet balance',
                'is_available' => \App\Services\DemoModeService::isEnabled()
                    ? true
                    : ($user->wallet && $user->wallet->balance > 0),
                'balance' => \App\Services\DemoModeService::isEnabled()
                    ? \App\Services\DemoModeService::getDemoWalletBalance()
                    : ($user->wallet ? $user->wallet->balance : 0),
            ],
            [
                'id' => 'paypal',
                'name' => 'PayPal',
                'icon' => 'paypal-icon',
                'description' => 'Pay with PayPal',
                'is_available' => config('services.paypal.enabled', false),
            ],
            [
                'id' => 'razorpay',
                'name' => 'RazorPay',
                'icon' => 'razorpay-icon',
                'description' => 'Pay with RazorPay',
                'is_available' => config('services.razorpay.enabled', false),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $paymentMethods
        ]);
    }

    public function getPaymentBreakdown(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|exists:bookings,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();
        $booking = Booking::where('id', $request->booking_id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $breakdown = [
            'total_fare' => $booking->total_fare,
            'distance_fare' => $booking->distance_fare,
            'time_fare' => $booking->time_fare,
            'waiting_charge' => $booking->waiting_charge ?? 0,
            'night_charge' => $booking->night_charge ?? 0,
            'surge_amount' => $booking->surge_amount ?? 0,
            'tax_amount' => $booking->tax_amount ?? 0,
            'booking_fee' => $booking->booking_fee ?? 0,
            'discount_amount' => $booking->discount_amount ?? 0,
            'debt_amount' => $booking->debt_amount ?? 0,
            'wallet_amount' => $booking->wallet_amount ?? 0,
            'final_amount' => $booking->total_amount,
        ];

        $walletBalance = $user->wallet ? $user->wallet->balance : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'breakdown' => $breakdown,
                'wallet_balance' => $walletBalance,
                'can_use_wallet' => $walletBalance > 0,
            ]
        ]);
    }

    public function applyPromoCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|exists:bookings,id',
            'promo_code' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();
        $booking = Booking::where('id', $request->booking_id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if (in_array($booking->status, ['completed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot apply or change promo code for completed or cancelled bookings'
            ], 400);
        }

        $promoCode = PromoCode::where('code', strtoupper($request->promo_code))
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();

        if (!$promoCode) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired promo code'
            ], 400);
        }

        $alreadyUsed = PromoUsage::where('user_id', $user->id)
            ->where('promo_code_id', $promoCode->id)
            ->where('booking_id', '!=', $booking->id)
            ->exists();

        if ($alreadyUsed) {
            return response()->json([
                'success' => false,
                'message' => 'You have already used this promo code'
            ], 400);
        }

        if ($booking->city_id) {
            $applicableCities = $promoCode->cities()->pluck('cities.id')->toArray();
            if (!empty($applicableCities) && !in_array($booking->city_id, $applicableCities)) {
                $city = City::find($booking->city_id);
                $cityName = $city ? $city->name : 'your city';

                return response()->json([
                    'success' => false,
                    'message' => "This promo code is not applicable in {$cityName}"
                ], 400);
            }
        }

        if ($booking->total_fare < $promoCode->minimum_fare) {
            return response()->json([
                'success' => false,
                'message' => "Minimum fare of {$promoCode->minimum_fare} required for this promo code"
            ], 400);
        }

        $discountAmount = 0;
        if ($promoCode->discount_type === 'percentage') {
            $discountAmount = ($booking->total_fare * $promoCode->discount_value) / 100;
        } else {
            $discountAmount = $promoCode->discount_value;
        }

        $discountAmount = min($discountAmount, $booking->total_fare);

        try {
            DB::beginTransaction();

            $isChanging = !empty($booking->promo_code);

            $booking->update([
                'promo_code' => $promoCode->code,
                'discount_amount' => $discountAmount,
                'final_fare' => $booking->total_fare - $discountAmount,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $isChanging ? 'Promo code changed successfully' : 'Promo code applied successfully',
                'data' => [
                    'promo_code' => $promoCode->code,
                    'discount_amount' => $discountAmount,
                    'final_fare' => $booking->final_fare,
                    'original_fare' => $booking->total_fare,
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to apply promo code'
            ], 500);
        }
    }

    public function removePromoCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|exists:bookings,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();
        $booking = Booking::where('id', $request->booking_id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if (in_array($booking->status, ['completed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot remove promo code from completed or cancelled bookings'
            ], 400);
        }

        if (!$booking->promo_code) {
            return response()->json([
                'success' => false,
                'message' => 'No promo code applied to this booking'
            ], 400);
        }

        try {
            DB::beginTransaction();

            PromoUsage::where('user_id', $user->id)
                ->where('booking_id', $booking->id)
                ->delete();

            $booking->update([
                'promo_code' => null,
                'discount_amount' => 0,
                'final_fare' => $booking->total_fare,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Promo code removed successfully',
                'data' => [
                    'final_fare' => $booking->final_fare,
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove promo code'
            ], 500);
        }
    }

    public function processPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|exists:bookings,id',
            'payment_method' => 'required|in:cash,wallet,paypal,razorpay',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'string|in:INR,USD',
            'use_wallet' => 'boolean',
            'wallet_amount' => 'numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();
        $booking = Booking::where('id', $request->booking_id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        try {
            DB::beginTransaction();

            $paymentMethod = $request->payment_method;
            $amount = $request->amount;
            $currency = $request->currency ?? 'INR';
            $useWallet = $request->use_wallet ?? false;
            $walletAmount = $request->wallet_amount ?? 0;

            if ($useWallet && $walletAmount > 0) {
                $wallet = $user->wallet;

                if (!$wallet || $wallet->balance < $walletAmount) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Insufficient wallet balance'
                    ], 400);
                }

                $wallet->decrement('balance', $walletAmount);

                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'type' => 'debit',
                    'amount' => $walletAmount,
                    'description' => "Payment for booking #{$booking->booking_code}",
                    'reference_type' => 'App\Models\Booking',
                    'reference_id' => $booking->id,
                    'status' => 'completed',
                ]);

                $booking->wallet_amount = $walletAmount;
            }

            $booking->update([
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentMethod === 'cash' ? 'pending' : 'paid',
                'online_paid_amount' => $paymentMethod !== 'cash' ? $amount - $walletAmount : 0,
                'cash_amount' => $paymentMethod === 'cash' ? $amount - $walletAmount : 0,
            ]);

            DB::commit();

            if ($paymentMethod !== 'cash' && $booking->payment_status === 'paid' && $booking->driver_id) {
                try {
                    $booking->refresh();
                    app(NotificationService::class)->sendPaymentCompletionNotificationToDriver($booking->fresh());
                } catch (\Exception $e) {
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment processed successfully',
                'data' => [
                    'payment_method' => $paymentMethod,
                    'payment_status' => $booking->payment_status,
                    'transaction_id' => 'TXN_' . time() . '_' . rand(1000, 9999),  // Temporary transaction ID
                    'amount' => $amount,
                    'currency' => $currency,
                    'wallet_amount' => $walletAmount,
                    'final_amount' => $amount,
                    'remaining_balance' => $user->wallet ? $user->wallet->balance : 0,
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed'
            ], 500);
        }
    }

    public function getWalletInfo(Request $request)
    {
        // Return demo data if demo mode is enabled
        if (\App\Services\DemoModeService::isEnabled()) {
            $demoTransactions = \App\Services\DemoModeService::getDemoWalletTransactions();
            return response()->json([
                'success' => true,
                'data' => [
                    'balance' => \App\Services\DemoModeService::getDemoWalletBalance(),
                    'transactions' => array_map(function ($t) {
                        return [
                            'id' => $t['id'],
                            'type' => $t['type'],
                            'amount' => $t['amount'],
                            'description' => $t['description'],
                            'status' => 'completed',
                            'created_at' => now()->toISOString(),
                        ];
                    }, $demoTransactions),
                ]
            ]);
        }

        $user = auth()->user();
        $wallet = $user->wallet;

        if (!$wallet) {
            return response()->json([
                'success' => true,
                'data' => [
                    'balance' => 0,
                    'transactions' => [],
                ]
            ]);
        }

        $transactions = WalletTransaction::where('wallet_id', $wallet->id)
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get()
            ->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => $transaction->amount,
                    'description' => $transaction->description,
                    'status' => $transaction->status,
                    'created_at' => $transaction->created_at->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'balance' => $wallet->balance,
                'transactions' => $transactions,
            ]
        ]);
    }

    public function getWalletTransactionList(Request $request)
    {
        // Return demo data if demo mode is enabled
        if (\App\Services\DemoModeService::isEnabled()) {
            $page = $request->get('page', 1);
            $limit = $request->get('limit', 10);
            $demoTransactions = \App\Services\DemoModeService::getDemoWalletTransactions();

            return response()->json([
                'success' => true,
                'data' => [
                    'transactions' => [
                        [
                            'date' => now()->format('D, d M'),
                            'balance' => number_format(\App\Services\DemoModeService::getDemoWalletBalance(), 2),
                            'transactions' => $demoTransactions,
                        ]
                    ],
                    'pagination' => [
                        'current_page' => (int) $page,
                        'total_pages' => 1,
                        'total_transactions' => count($demoTransactions),
                        'per_page' => (int) $limit
                    ],
                    'filters_applied' => [
                        'type' => $request->get('type'),
                        'status' => $request->get('status'),
                        'from_date' => $request->get('from_date'),
                        'to_date' => $request->get('to_date'),
                        'date' => $request->get('date'),
                        'min_amount' => $request->get('min_amount'),
                        'max_amount' => $request->get('max_amount'),
                        'amount' => $request->get('amount')
                    ]
                ]
            ]);
        }

        $driver = auth()->user();
        $wallet = $driver->wallet;

        $hasPage = $request->has('page');
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 10);
        $type = $request->get('type');  // 'credit', 'debit', or null for all
        $status = $request->get('status');  // 'completed', 'pending', 'failed', or null for all

        $fromDate = $request->get('from_date');
        $toDate = $request->get('to_date');
        $date = $request->get('date');  // Specific date

        $minAmount = $request->get('amount_min') ?? $request->get('min_amount');
        $maxAmount = $request->get('amount_max') ?? $request->get('max_amount');
        $amount = $request->get('amount');  // Exact amount

        if (!$wallet) {
            return response()->json([
                'success' => true,
                'data' => [
                    'pagination' => [
                        'current_page' => 1,
                        'total_pages' => 0,
                        'total_transactions' => 0,
                        'per_page' => $limit
                    ],
                    'filters_applied' => [
                        'type' => $type,
                        'status' => $status,
                        'from_date' => $fromDate,
                        'to_date' => $toDate,
                        'date' => $date,
                        'amount_min' => $minAmount,
                        'amount_max' => $maxAmount,
                        'amount' => $amount
                    ]
                ]
            ]);
        }

        $query = WalletTransaction::where('wallet_id', $wallet->id);

        // If type is 'debit', filter for negative amounts
        if ($type === 'debit') {
            $query->where('amount', '<', 0);
        } elseif ($type) {
            $query->where('type', $type);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($date) {
            $query->whereDate('created_at', $date);
        } else {
            if ($fromDate) {
                $query->whereDate('created_at', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('created_at', '<=', $toDate);
            }
        }

        if ($amount) {
            $query->where('amount', $amount);
        } else {
            // For debit transactions, use absolute value for amount filtering
            if ($type === 'debit') {
                if ($minAmount !== null && $minAmount !== '') {
                    $query->whereRaw('ABS(amount) >= ?', [$minAmount]);
                }
                if ($maxAmount !== null && $maxAmount !== '') {
                    $query->whereRaw('ABS(amount) <= ?', [$maxAmount]);
                }
            } else {
                if ($minAmount !== null && $minAmount !== '') {
                    $query->where('amount', '>=', $minAmount);
                }
                if ($maxAmount !== null && $maxAmount !== '') {
                    $query->where('amount', '<=', $maxAmount);
                }
            }
        }


        $totalTransactions = $query->count();

        $transactionsQuery = $query->orderBy('created_at', 'desc');

        if ($hasPage) {
            $transactions = $transactionsQuery
                ->skip(($page - 1) * $limit)
                ->take($limit)
                ->get();
            $totalPages = ceil($totalTransactions / $limit);
        } else {
            $transactions = $transactionsQuery->get();
            $totalPages = 1;
            $limit = $totalTransactions;
        }

        // Group transactions by date (same format for all types including debit)
        $groupedTransactions = $transactions->groupBy(function ($transaction) {
            return $transaction->created_at->format('Y-m-d');
        })->map(function ($dayTransactions, $date) {
            $formattedDate = \Carbon\Carbon::parse($date)->format('D, d M');
            $latestBalance = $dayTransactions->first()->balance ?? 0;

            return [
                'date' => $formattedDate,
                'balance' => number_format($latestBalance, 2),
                'transactions' => $dayTransactions->map(function ($transaction) {
                    return [
                        'id' => (int) $transaction->id,
                        'type' => $transaction->type ?? '',
                        'is_positive' => $transaction->amount >= 0 ? 1 : 0,
                        'amount' => number_format($transaction->amount, 2),
                        'balance' => number_format($transaction->balance ?? 0, 2),
                        'description' => $transaction->description ?? '',
                        'status' => $transaction->status ?? '',
                        'reference_type' => $transaction->reference_type ?? '',
                        'reference_id' => $transaction->reference_id ? (int) $transaction->reference_id : 0,
                        'created_at' => $transaction->created_at ? $transaction->created_at->toISOString() : '',
                        'updated_at' => $transaction->updated_at ? $transaction->updated_at->toISOString() : '',
                    ];
                })->values()->toArray()
            ];
        })->values()->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'transactions' => $groupedTransactions,
                'pagination' => [
                    'current_page' => (int) $page,
                    'total_pages' => $totalPages,
                    'total_transactions' => $totalTransactions,
                    'per_page' => (int) $limit
                ],
                'filters_applied' => [
                    'type' => $type,
                    'status' => $status,
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    'date' => $date,
                    'min_amount' => $minAmount,
                    'max_amount' => $maxAmount,
                    'amount' => $amount
                ]
            ]
        ]);
    }

    public function getWalletOverview(Request $request)
    {
        $driver = auth()->user();
        $wallet = DB::table('wallets')->where('user_id', $driver->id)->first();

        $currentBalance = ($wallet && $wallet->balance !== null && $wallet->balance !== '') ? $wallet->balance : 0;

        $scheduledPayout = (!empty($driver->scheduled_payout_date)) ? $driver->scheduled_payout_date : 'Fri, 16th Mar';

        $hasBankAccount = method_exists($driver, 'bank_accounts') ? $driver->bank_accounts()->exists() : false;

        $recentTransactions = [];
        if ($wallet && $wallet->id) {
            $dbTransactions = WalletTransaction::where('wallet_id', $wallet->id)
                ->orderBy('created_at', 'desc')
                ->take(7)
                ->get();

            if ($dbTransactions->count() > 0) {
                $recentTransactions = $dbTransactions->map(function ($transaction) {
                    return [
                        'id' => $transaction->id,
                        'type' => $transaction->type,
                        'description' => $transaction->description,
                        'amount' => $transaction->amount,
                        'time' => $transaction->created_at->format('g:i A'),
                        'is_positive' => $transaction->amount >= 0 ? 1 : 0,
                    ];
                })->toArray();
            }
        }

        if (empty($recentTransactions)) {
            $recentTransactions = [];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'current_balance' => $currentBalance,
                'scheduled_payout' => $scheduledPayout,
                'has_bank_account' => $hasBankAccount,
                'recent_transactions' => $recentTransactions,
                'tabs' => [
                    ['name' => 'Wallet', 'selected' => true],
                    ['name' => 'Earning', 'selected' => false]
                ]
            ]
        ]);
    }

    public function getBankAccountInfo(Request $request)
    {
        $driver = auth()->user();
        $upiAccount = UpiAccount::where('driver_id', $driver->id)->first();

        $bankAccount = null;
        if (method_exists($driver, 'bank_accounts')) {
            $bankAccount = $driver->bank_accounts()->first();
        }

        if (!$bankAccount) {
            $bankAccount = (object) [
                'account_holder_name' => '',
                'account_number' => '',
                'ifsc_code' => '',
                'bank_name' => '',
            ];
        }

        return response()->json($this->sanitizeForApi([
            'success' => true,
            'data' => [
                'tabs' => [
                    ['name' => 'Bank Account', 'selected' => true],
                    ['name' => 'UPI ID', 'selected' => false]
                ],
                'instruction_text' => "Your earnings will be automatically transferred to this bank account. Make sure it's active and correct to receive payouts without delays",
                'account_details' => [
                    'id' => $bankAccount->id ?? '',
                    'account_holder_name' => $bankAccount->account_holder_name ?? 'Michal Clark',
                    'account_number' => $bankAccount->account_number ?? '99887766550000',
                    'ifsc_code' => $bankAccount->ifsc_code ?? 'BANKXDEMO',
                    'bank_name' => $bankAccount->bank_name ?? 'Demo Bank',
                    'upi_id' => $upiAccount->upi_id ?? '',
                ]
            ]
        ]));
    }

    public function addBankAccount(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'account_holder_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:20',
            'ifsc_code' => 'required|string|max:11',
            'bank_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();

        try {
            DB::beginTransaction();

            $bankAccount = null;
            if (method_exists($driver, 'bank_accounts')) {
                $bankAccount = $driver->bank_accounts()->updateOrCreate(
                    ['driver_id' => $driver->id],
                    [
                        'account_holder_name' => $request->account_holder_name,
                        'account_number' => $request->account_number,
                        'ifsc_code' => $request->ifsc_code,
                        'bank_name' => $request->bank_name,
                        'is_verified' => false,
                        'is_primary' => true,
                    ]
                );
            }

            if (!$bankAccount) {
                $bankAccount = (object) [
                    'id' => 1,
                    'account_holder_name' => $request->account_holder_name,
                    'account_number' => $request->account_number,
                    'ifsc_code' => $request->ifsc_code,
                    'bank_name' => $request->bank_name,
                    'is_verified' => false,
                    'is_primary' => true,
                ];
            }

            DB::commit();

            return response()->json($this->sanitizeForApi([
                'success' => true,
                'message' => 'Bank account added successfully',
                'data' => [
                    'bank_account' => $bankAccount instanceof Model ? $bankAccount->toArray() : (array) $bankAccount,
                    'verification_required' => true
                ]
            ]));
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to add bank account'
            ], 500);
        }
    }

    public function getWithdrawalInfo(Request $request)
    {
        $driver = auth()->user();
        $wallet = $driver->wallet;

        $currentBalance = ($wallet && $wallet->balance !== null && $wallet->balance !== '') ? $wallet->balance : 0;

        $maxWithdrawalLimit = 10000;

        $linkedAccount = null;
        if (method_exists($driver, 'bank_accounts')) {
            $linkedAccount = $driver->bank_accounts()->where('is_primary', true)->first();
        }

        $accountInfo = $linkedAccount ? [
            'account_type' => 'Linked Account',
            'account_number_masked' => 'xxxx xxxx ' . substr($linkedAccount->account_number, -4),
        ] : [
            'account_type' => 'Linked Account',
            'account_number_masked' => 'xxxx xxxx 1234',
        ];

        return response()->json($this->sanitizeForApi([
            'success' => true,
            'data' => [
                'current_balance' => $currentBalance,
                'maximum_withdrawal_limit' => $maxWithdrawalLimit,
                'default_withdrawal_amount' => 20,
                'preset_withdrawal_amounts' => [50, 100, 200],
                'deposit_account' => $accountInfo,
                'withdrawal_button_text' => 'withdraw Money'
            ]
        ]));
    }

    public function processWithdrawal(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'account_id' => 'required_without:test_amount|exists:bank_accounts,id',
            'test_amount' => 'nullable|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $driver = auth()->user();
        $wallet = DB::table('wallets')->where('user_id', $driver->id)->first();
        $amount = (float) $request->amount;
        $testAmount = $request->get('test_amount');

        if ($testAmount !== null) {
            $amountToDeduct = (float) $testAmount;
            $currentBalance = ($wallet && $wallet->balance !== null) ? (float) $wallet->balance : 0;

            if ($amountToDeduct > $currentBalance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Withdrawal amount exceeds wallet balance'
                ], 400);
            }

            $remainingBalance = max(0, $currentBalance - $amountToDeduct);

            if ($wallet && Schema::hasColumn('wallets', 'balance')) {
                $wallet->balance = $remainingBalance;
                $wallet->save();
            } elseif ($wallet && Schema::hasColumn('wallets', 'amount')) {
                $wallet->amount = $remainingBalance;
                $wallet->save();
            }

            $transactions = $wallet ? $this->getRecentWalletTransactions($wallet->id, 10) : [];
            return response()->json($this->sanitizeForApi([
                'success' => true,
                'message' => 'Test withdrawal applied to wallet',
                'data' => [
                    'withdrawal_amount' => $amountToDeduct,
                    'remaining_balance' => $remainingBalance,
                    'status' => 'pending',
                    'transactions' => $transactions
                ]
            ]));
        }

        $bankAccount = \App\Models\BankAccount::where('id', $request->account_id)
            ->where(function ($query) use ($driver) {
                $query
                    ->where('driver_id', $driver->id)
                    ->orWhere('user_id', $driver->id);
            })
            ->first();

        if (!$bankAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Bank account not found or does not belong to you'
            ], 404);
        }

        $wallet = DB::table('wallets')->where('user_id', $driver->id)->first();

        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found. Please create a wallet first.'
            ], 404);
        }

        $currentBalance = (float) ($wallet->balance ?? 0);
        if ($amount > $currentBalance) {
            return response()->json([
                'success' => false,
                'message' => 'Withdrawal amount exceeds wallet balance',
                'current_balance' => $currentBalance
            ], 400);
        }

        $maxWithdrawalLimit = 10000;
        if ($amount > $maxWithdrawalLimit) {
            return response()->json([
                'success' => false,
                'message' => "Maximum withdrawal limit is {$maxWithdrawalLimit}"
            ], 400);
        }

        try {
            DB::beginTransaction();

            DB::table('wallets')
                ->where('id', $wallet->id)
                ->decrement('balance', $amount);
            $wallet = DB::table('wallets')->where('id', $wallet->id)->first();
            $remainingBalance = (float) ($wallet->balance ?? 0);

            $walletTransaction = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'driver_id' => $driver->id,
                'type' => WalletTransaction::TYPE_WALLET_WITHDRAWAL,
                'amount' => -$amount,  // Negative for debit/withdrawal
                'balance' => $remainingBalance,
                'description' => "Withdrawal to bank account ({$bankAccount->bank_name} - {$bankAccount->account_number})",
                'reference_type' => 'App\Models\BankAccount',
                'reference_id' => $bankAccount->id,
                'status' => WalletTransaction::STATUS_PENDING,
                'meta_data' => [
                    'bank_account_id' => $bankAccount->id,
                    'account_holder_name' => $bankAccount->account_holder_name,
                    'account_number' => $bankAccount->account_number,
                    'ifsc_code' => $bankAccount->ifsc_code,
                    'bank_name' => $bankAccount->bank_name,
                    'withdrawal_requested_at' => now()->toDateTimeString(),
                ],
            ]);

            $withdrawalRequest = \App\Models\DriverWithdrawalRequest::create([
                'driver_id' => $driver->id,
                'bank_account_id' => $bankAccount->id,
                'amount' => $amount,
                'status' => \App\Models\DriverWithdrawalRequest::STATUS_PENDING,
                'meta_data' => [
                    'wallet_transaction_id' => $walletTransaction->id,
                    'requested_at' => now()->toDateTimeString(),
                ],
            ]);

            Transaction::create([
                'transaction_id' => 'TXN_' . time() . '_' . rand(1000, 9999),
                'wallet_id' => $wallet->id,
                'user_id' => $driver->id,
                'booking_id' => null,
                'type' => 'debit',
                'amount' => $amount,
                'balance' => $remainingBalance,
                'description' => "Withdrawal to bank account ({$bankAccount->bank_name} - {$bankAccount->account_number})",
                'status' => 'pending',
                'payment_method' => 'wallet',  // Payout through wallet
                'reference_id' => $withdrawalRequest->id,
                'reference_type' => 'App\Models\DriverWithdrawalRequest',
                'meta_data' => [
                    'bank_account_id' => $bankAccount->id,
                    'account_holder_name' => $bankAccount->account_holder_name,
                    'account_number' => $bankAccount->account_number,
                    'ifsc_code' => $bankAccount->ifsc_code,
                    'bank_name' => $bankAccount->bank_name,
                    'withdrawal_requested_at' => now()->toDateTimeString(),
                    'wallet_transaction_id' => $walletTransaction->id,
                ],
            ]);

            DB::commit();

            $transactions = $this->getRecentWalletTransactions($wallet->id, 10);
            return response()->json($this->sanitizeForApi([
                'success' => true,
                'message' => 'Withdrawal request submitted successfully',
                'data' => [
                    'withdrawal_request_id' => $withdrawalRequest->id,
                    'withdrawal_amount' => $amount,
                    'remaining_balance' => $remainingBalance,
                    'status' => 'pending',
                    'transactions' => $transactions
                ]
            ]));
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to process withdrawal: ' . $e->getMessage()
            ], 500);
        }
    }

    public function addUpiId(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'upi_id' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();

        try {
            DB::beginTransaction();

            $upiRecord = null;
            try {
                if (method_exists($driver, 'upi_accounts')) {
                    $upiRecord = $driver->upi_accounts()->updateOrCreate(
                        ['driver_id' => $driver->id],
                        [
                            'upi_id' => $request->upi_id,
                            'is_verified' => false,
                            'is_primary' => true,
                        ]
                    );
                } else {
                    $upiRecord = (object) [
                        'id' => rand(1000, 9999),
                        'driver_id' => $driver->id,
                        'upi_id' => $request->upi_id,
                        'is_verified' => false,
                        'is_primary' => true,
                    ];
                }
            } catch (\Exception $e) {
                $upiRecord = (object) [
                    'id' => rand(1000, 9999),
                    'driver_id' => $driver->id,
                    'upi_id' => $request->upi_id,
                    'is_verified' => false,
                    'is_primary' => true,
                ];
            }

            DB::commit();

            return response()->json($this->sanitizeForApi([
                'success' => true,
                'message' => 'UPI ID added successfully',
                'data' => [
                    'upi_account' => $upiRecord instanceof Model ? $upiRecord->toArray() : (array) $upiRecord,
                    'verification_required' => true
                ]
            ]));
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to add UPI ID'
            ], 500);
        }
    }

    public function getAddMoneyInfo(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'title' => 'Add Money',
                'subtitle' => 'Add Money to Wallet',
                'selected_amount' => 200,
                'currency_symbol' => '₹',
                'suggested_amounts' => [
                    ['amount' => 50, 'currency_symbol' => '₹', 'is_selected' => false],
                    ['amount' => 100, 'currency_symbol' => '₹', 'is_selected' => false],
                    ['amount' => 200, 'currency_symbol' => '₹', 'is_selected' => true],
                ],
                'action_button_text' => 'Add Money'
            ]
        ]);
    }

    public function getTransactionDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();
        $transactionId = $request->transaction_id;
        $transaction = \App\Models\WalletTransaction::where('id', $transactionId)
            ->whereHas('wallet', function ($query) use ($driver) {
                $query->where('user_id', $driver->id);
            })
            ->first();

        if (!$transaction) {
            $transactionData = [
                'transaction_id' => 'RIDE-TXN-842390',
                'amount' => 10.21,
                'status' => 'completed',
                'payment_method' => 'UPI',
                'trip_id' => 'ABC1234',
                'date' => 'Tue, 5 Aug',
                'time' => '2:45 PM',
                'timeline' => [
                    [
                        'event' => 'Payment Initiated via UPI',
                        'timestamp' => '9:45 AM',
                        'icon' => 'paypal_like_icon'
                    ],
                    [
                        'event' => 'Amount Processed',
                        'timestamp' => '9:46 AM',
                        'icon' => 'wallet_icon'
                    ],
                    [
                        'event' => 'Credited into Wallet',
                        'timestamp' => '9:46 AM',
                        'icon' => 'wallet_check_icon'
                    ]
                ],
                'action_button_text' => 'Download Receipt',
                'download_link' => url('/api/payments/driver/transaction/details/pdf?transaction_id=' . $transactionId)
            ];
        } else {
            $transaction->load(['reference']);
            $tripId = '-';
            if ($transaction->reference_type === 'App\Models\Booking' && $transaction->reference) {
                $tripId = $transaction->reference->booking_code ?? 'N/A';
            } elseif (isset($transaction->meta_data['booking_code'])) {
                $tripId = $transaction->meta_data['booking_code'];
            }

            $paymentMethod = 'N/A';
            if (isset($transaction->meta_data['payment_type'])) {
                $paymentMethod = $transaction->meta_data['payment_type'];
            } elseif (isset($transaction->meta_data['method'])) {
                $paymentMethod = $transaction->meta_data['method'];
            } elseif (!empty($transaction->payment_type)) {
                $paymentMethod = $transaction->payment_type;
            } elseif ($transaction->reference_type === 'App\Models\Booking' && $transaction->reference && !empty($transaction->reference->payment_method)) {
                $paymentMethod = $transaction->reference->payment_method;
            }

            $transactionData = [
                'transaction_id' => !empty($transaction->transection_id)
                    ? $transaction->transection_id
                    : 'TXN-' . str_pad($transaction->id, 6, '0', STR_PAD_LEFT),
                'amount' => abs((float) $transaction->amount),
                'status' => $transaction->status ?? 'completed',
                'payment_method' => $paymentMethod,
                'trip_id' => $tripId,
                'date' => $transaction->created_at->format('D, j M'),
                'time' => $transaction->created_at->format('g:i A'),
                'rejected_reason' => isset($transaction->meta_data['rejection_reason']) ? $transaction->meta_data['rejection_reason'] : '',
                'timeline' => $this->buildTransactionTimeline($transaction),
                'action_button_text' => $transaction->status === 'completed' ? 'Download Receipt' : 'Contact Support'
            ];
        }

        $transactionData['download_link'] = url('/api/payments/driver/transaction/details/pdf?transaction_id=' . $transactionId);

        return response()->json([
            'success' => true,
            'data' => [
                'tabs' => [
                    ['name' => 'Wallet', 'selected' => true],
                    ['name' => 'Earning', 'selected' => false]
                ],
                'transaction' => $transactionData
            ]
        ]);
    }

    public function downloadTransactionDetailsPdf(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = $this->authenticateUserFromRequest($request);
        if (!$driver) {
            return response()->json([
                'success' => false,
                'message' => 'Token Is Missing',
            ], 401);
        }

        $transactionId = $request->transaction_id;

        $transaction = \App\Models\WalletTransaction::where('id', $transactionId)
            ->whereHas('wallet', function ($query) use ($driver) {
                $query->where('user_id', $driver->id);
            })
            ->first();

        if (!$transaction) {
            $transactionData = [
                'transaction_id' => 'RIDE-TXN-842390',
                'amount' => 10.21,
                'status' => 'completed',
                'payment_method' => 'UPI',
                'trip_id' => 'ABC1234',
                'date' => 'Tue, 5 Aug',
                'time' => '2:45 PM',
                'full_date' => now()->format('d M Y, h:i A'),
                'timeline' => [
                    [
                        'event' => 'Payment Initiated via UPI',
                        'timestamp' => '9:45 AM',
                        'icon' => 'paypal_like_icon'
                    ],
                    [
                        'event' => 'Amount Processed',
                        'timestamp' => '9:46 AM',
                        'icon' => 'wallet_icon'
                    ],
                    [
                        'event' => 'Credited into Wallet',
                        'timestamp' => '9:46 AM',
                        'icon' => 'wallet_check_icon'
                    ]
                ]
            ];
        } else {
            $transaction->load(['reference']);

            $tripId = 'N/A';
            if ($transaction->reference_type === 'App\Models\Booking' && $transaction->reference) {
                $tripId = $transaction->reference->booking_code ?? 'N/A';
            } elseif (isset($transaction->meta_data['booking_code'])) {
                $tripId = $transaction->meta_data['booking_code'];
            }

            $paymentMethod = 'N/A';
            if (isset($transaction->meta_data['payment_method'])) {
                $paymentMethod = $transaction->meta_data['payment_method'];
            } elseif (isset($transaction->meta_data['method'])) {
                $paymentMethod = $transaction->meta_data['method'];
            } elseif (!empty($transaction->payment_type)) {
                $paymentMethod = $transaction->payment_type;
            } elseif ($transaction->reference_type === 'App\Models\Booking' && $transaction->reference && !empty($transaction->reference->payment_method)) {
                $paymentMethod = $transaction->reference->payment_method;
            }

            $transactionData = [
                'transaction_id' => !empty($transaction->transection_id)
                    ? $transaction->transection_id
                    : 'TXN-' . str_pad($transaction->id, 6, '0', STR_PAD_LEFT),
                'amount' => abs((float) $transaction->amount),
                'status' => $transaction->status ?? 'completed',
                'payment_method' => $paymentMethod,
                'trip_id' => $tripId,
                'date' => $transaction->created_at->format('D, j M'),
                'time' => $transaction->created_at->format('g:i A'),
                'full_date' => $transaction->created_at->format('d M Y, h:i A'),
                'timeline' => $this->buildTransactionTimeline($transaction),
                'type' => $transaction->type ?? 'credit'
            ];
        }

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);

        $html = $this->generateTransactionDetailsPdfHtml($transactionData);

        // clear any previous output that might corrupt the PDF
        if (ob_get_length()) {
            ob_end_clean();
        }

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();
        $filename = 'transaction-details-' . $transactionData['transaction_id'] . '.pdf';

        return response($output, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('Content-Length', strlen($output));
    }

    private function generateTransactionDetailsPdfHtml($transactionData)
    {
        $receiptNumber = 'RCP-' . date('Y') . '-' . str_pad(substr($transactionData['transaction_id'], -6), 6, '0', STR_PAD_LEFT);
        $currentDate = now()->format('d M Y, h:i A');
        $fullDate = $transactionData['full_date'] ?? $transactionData['date'] . ', ' . $transactionData['time'];

        $statusColor = '#6c757d';  // default
        if ($transactionData['status'] === 'completed') {
            $statusColor = '#28a745';
        } elseif ($transactionData['status'] === 'pending') {
            $statusColor = '#ffc107';
        } elseif ($transactionData['status'] === 'failed') {
            $statusColor = '#dc3545';
        }

        $transactionType = $transactionData['type'] ?? 'credit';
        $amountPrefix = $transactionType === 'credit' || $transactionType === 'wallet_topup' ? '+' : '-';
        $amountColor = ($transactionType === 'credit' || $transactionType === 'wallet_topup')
            ? '#28a745'
            : '#dc3545';


        $timelineHtml = '';
        if (isset($transactionData['timeline']) && is_array($transactionData['timeline'])) {
            foreach ($transactionData['timeline'] as $index => $event) {
                $timelineHtml .= '
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;">
                        <div style="font-weight: bold; color: #333; margin-bottom: 5px;">' . htmlspecialchars($event['event']) . '</div>
                        <div style="color: #666; font-size: 13px;">' . htmlspecialchars($event['timestamp'] ?? '') . '</div>
                    </td>
                </tr>';
            }
        }

        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Transaction Receipt - ' . $transactionData['transaction_id'] . '</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 0;
                    padding: 20px;
                    color: #333;
                }
                .header {
                    text-align: center;
                    margin-bottom: 30px;
                    border-bottom: 2px solid #333;
                    padding-bottom: 20px;
                }
                .header h1 {
                    margin: 0;
                    color: #333;
                    font-size: 24px;
                }
                .header p {
                    margin: 5px 0;
                    color: #666;
                }
                .invoice-info {
                    display: table;
                    width: 100%;
                    margin-bottom: 30px;
                }
                .invoice-info-left, .invoice-info-right {
                    display: table-cell;
                    width: 50%;
                    vertical-align: top;
                }
                .invoice-info-right {
                    text-align: right;
                }
                .section {
                    margin-bottom: 25px;
                }
                .section-title {
                    font-size: 18px;
                    font-weight: bold;
                    margin-bottom: 15px;
                    color: #333;
                    border-bottom: 1px solid #ddd;
                    padding-bottom: 5px;
                }
                .info-row {
                    display: table;
                    width: 100%;
                    margin-bottom: 10px;
                }
                .info-label {
                    display: table-cell;
                    width: 40%;
                    font-weight: bold;
                    color: #555;
                }
                .info-value {
                    display: table-cell;
                    width: 60%;
                    color: #333;
                }
                .status-badge {
                    display: inline-block;
                    padding: 5px 15px;
                    border-radius: 20px;
                    font-weight: bold;
                    font-size: 14px;
                    text-transform: uppercase;
                }
                .amount-section {
                    margin-top: 20px;
                    padding: 20px;
                    background-color: #f9f9f9;
                    border-radius: 5px;
                    text-align: center;
                }
                .amount-label {
                    font-size: 14px;
                    color: #666;
                    margin-bottom: 5px;
                }
                .amount-value {
                    font-size: 32px;
                    font-weight: bold;
                }
                .timeline-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 10px;
                }
                .timeline-table th {
                    background-color: #f5f5f5;
                    padding: 12px;
                    text-align: left;
                    border-bottom: 2px solid #ddd;
                    font-weight: bold;
                }
                .timeline-table td {
                    padding: 12px;
                    border-bottom: 1px solid #eee;
                }
                .footer {
                    margin-top: 40px;
                    text-align: center;
                    color: #666;
                    font-size: 12px;
                    border-top: 1px solid #ddd;
                    padding-top: 20px;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Transaction Receipt</h1>
                <p>Receipt Number: ' . $receiptNumber . '</p>
                <p>Generated on: ' . $currentDate . '</p>
            </div>

            <div class="invoice-info">
                <div class="invoice-info-left">
                    <div class="section">
                        <div class="section-title">Transaction Information</div>
                        <div class="info-row">
                            <div class="info-label">Transaction ID:</div>
                            <div class="info-value">' . htmlspecialchars($transactionData['transaction_id']) . '</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Date & Time:</div>
                            <div class="info-value">' . htmlspecialchars($fullDate) . '</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Payment Method:</div>
                            <div class="info-value">' . htmlspecialchars($transactionData['payment_method']) . '</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Trip ID:</div>
                            <div class="info-value">' . htmlspecialchars($transactionData['trip_id']) . '</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Status:</div>
                            <div class="info-value">
                                <span class="status-badge" style="background-color: ' . $statusColor . '20; color: ' . $statusColor . ';">
                                    ' . htmlspecialchars(ucfirst($transactionData['status'])) . '
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="amount-section">
                <div class="amount-label">Transaction Amount</div>
                <div class="amount-value" style="color: ' . $amountColor . ';">
                    ' . $amountPrefix . '' . number_format($transactionData['amount'], 2) . '
                </div>
            </div>

            <div class="section">
                <div class="section-title">Transaction Timeline</div>
                <table class="timeline-table">
                    <thead>
                        <tr>
                            <th>Event</th>
                        </tr>
                    </thead>
                    <tbody>
                        ' . $timelineHtml . '
                    </tbody>
                </table>
            </div>

            <div class="footer">
                <p>This is a computer-generated receipt. No signature required.</p>
                <p>Thank you for using our service!</p>
            </div>
        </body>
        </html>';

        return $html;
    }

    public function getEarningsOverview(Request $request)
    {
        // Return demo data if demo mode is enabled
        if (\App\Services\DemoModeService::isEnabled()) {
            $demoData = \App\Services\DemoModeService::getDemoEarningsData();
            return response()->json([
                'success' => true,
                'data' => [
                    'tabs' => [
                        ['name' => 'Wallet', 'selected' => false],
                        ['name' => 'Earning', 'selected' => true]
                    ],
                    'weekly_summary' => $demoData['weekly_summary'],
                    'daily_earnings_chart' => $demoData['daily_earnings_chart'],
                    'detailed_earning_breakdown' => [],
                    'ride_summary' => $demoData['ride_summary'],
                    'support' => [
                        'text' => 'Need Support?',
                        'icon_number' => 24
                    ]
                ]
            ]);
        }

        $driver = auth()->user();
        $selectedDay = $request->get('selected_day', '10');

        $fromDateParam = $request->get('from_date');
        $toDateParam = $request->get('to_date');

        if ($fromDateParam && $toDateParam) {
            try {
                $from = Carbon::parse($fromDateParam)->startOfDay();
                $to = Carbon::parse($toDateParam)->endOfDay();

                $dateRange = $from->format('d M') . ' - ' . $to->format('d M');

                $dailyEarnings = $this->generateRealDailyEarningsForRange($driver, $from, $to);

                $dailyEarnings = array_map(function ($row) use ($selectedDay) {
                    $row['highlighted'] = ((string) $row['date'] === (string) $selectedDay);
                    return $row;
                }, $dailyEarnings);

                $totalEarning = number_format(array_sum(array_column($dailyEarnings, 'earning')), 2, '.', '');
            } catch (\Throwable $e) {
                $from = Carbon::now()->startOfWeek();
                $to = Carbon::now()->endOfWeek();
                $dateRange = $from->format('d M') . ' - ' . $to->format('d M');
                $dailyEarnings = $this->generateRealDailyEarningsForRange($driver, $from, $to);
                $totalEarning = number_format(array_sum(array_column($dailyEarnings, 'earning')), 2, '.', '');
            }
        } else {
            $from = Carbon::now()->startOfWeek();
            $to = Carbon::now()->endOfWeek();
            $dateRange = $from->format('d M') . ' - ' . $to->format('d M');
            $dailyEarnings = $this->generateRealDailyEarningsForRange($driver, $from, $to);
            $totalEarning = number_format(array_sum(array_column($dailyEarnings, 'earning')), 2, '.', '');
        }

        $selectedDayEarnings = $this->getSelectedDayEarnings($driver, $selectedDay, $from, $to, $dailyEarnings, ['from' => $from, 'to' => $to]);
        $rideSummary = $this->getRideSummary($driver);

        // Calculate total earning using same logic as getSelectedDayEarnings
        $wallet = DB::table('wallets')->where('user_id', $driver->id)->first();

        if ($wallet) {
            $rangeStart = $from->copy()->startOfDay();
            $rangeEnd = $to->copy()->endOfDay();

            $wallet_earning = WalletTransaction::where('wallet_id', $wallet->id)
                ->where('type', 'credit')
                ->where(function ($q) {
                    $q
                        ->where('payment_type', '!=', 'cash')
                        ->orWhereNull('payment_type');
                })
                ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                ->sum('amount');

            $cash_earning = DB::table('bookings')
                ->where('driver_id', $driver->id)
                ->where('payment_method', 'cash')
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->whereBetween('completed_at', [$rangeStart, $rangeEnd])
                ->sum(DB::raw('COALESCE(driver_amount, COALESCE(total_amount, 0) - COALESCE(admin_commission, 0))'));

            $cash_earning = $cash_earning ?? 0;

            $deductions = WalletTransaction::where('wallet_id', $wallet->id)
                ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                ->where('type', 'adjustment')
                ->sum('amount');

            $deduction = abs($deductions);

            $incentive_reward = WalletTransaction::where('wallet_id', $wallet->id)
                ->where('type', 'incentive_reward')
                ->where('status', 'completed')
                ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                ->sum('amount');

            $totalEarning = $wallet_earning + $cash_earning - $deduction + $incentive_reward;
        } else {
            $totalEarning = 0;
        }

        $maxEarning = max(array_column($dailyEarnings, 'earning'));
        $yAxisMax = ceil($maxEarning / 100) * 100;  // Round up to nearest 100
        $yAxisMin = 0;

        return response()->json([
            'success' => true,
            'data' => [
                'tabs' => [
                    ['name' => 'Wallet', 'selected' => false],
                    ['name' => 'Earning', 'selected' => true]
                ],
                'weekly_summary' => [
                    'date_range' => $dateRange,
                    'total_earning_this_week' => (string) number_format($totalEarning, 2, '.', '')
                ],
                'daily_earnings_chart' => [
                    'y_axis_label' => 'USD',
                    'y_axis_min' => $yAxisMin,
                    'y_axis_max' => max($yAxisMax, 100),  // Minimum 100
                    'daily_data' => $dailyEarnings
                ],
                'detailed_earning_breakdown' => $selectedDayEarnings,
                'ride_summary' => $rideSummary,
                'support' => [
                    'text' => 'Need Support?',
                    'icon_number' => 24
                ]
            ]
        ]);
    }

    public function getEarningsList(Request $request)
    {
        // Return demo data if demo mode is enabled
        if (\App\Services\DemoModeService::isEnabled()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'filters' => [
                        'payment_source' => $request->get('payment_source', 'all'),
                        'earning_type' => $request->get('earning_type', 'all'),
                        'amount' => $request->get('amount', 'all')
                    ],
                    'earnings' => [
                        [
                            'id' => 1,
                            'booking_code' => 'BK240101A1B2',
                            'date' => now()->format('Y-m-d'),
                            'amount' => 250.0,
                            'payment_method' => 'cash',
                            'status' => 'completed',
                        ],
                        [
                            'id' => 2,
                            'booking_code' => 'BK240101C3D4',
                            'date' => now()->subDays(1)->format('Y-m-d'),
                            'amount' => 180.5,
                            'payment_method' => 'wallet',
                            'status' => 'completed',
                        ],
                    ]
                ]
            ]);
        }

        $driver = auth()->user();
        $paymentSource = $request->get('payment_source', 'all');
        $earningType = $request->get('earning_type', 'all');
        $amount = $request->get('amount', 'all');
        $amountMin = $request->get('amount_min');
        $amountMax = $request->get('amount_max');

        $earningsData = $this->getEarningsData($driver, $paymentSource, $earningType, $amount, $amountMin, $amountMax);

        return response()->json([
            'success' => true,
            'data' => [
                'filters' => [
                    'payment_source' => $paymentSource,
                    'earning_type' => $earningType,
                    'amount' => $amount,
                    'amount_min' => $amountMin ?? '',
                    'amount_max' => $amountMax ?? ''
                ],
                'earnings' => $earningsData
            ]
        ]);
    }

    public function getEarningDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'booking_code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();
        $bookingCode = $request->booking_code;

        $booking = Booking::where('booking_code', $bookingCode)
            ->where('driver_id', $driver->id)
            ->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found',
                'error' => 'No booking found with the provided booking code'
            ], 404);
        }

        $earningDetails = $this->buildEarningDetails($booking);

        $earningDetails['download_link'] = url('/api/payments/driver/earning/details/pdf?booking_code=' . $bookingCode);

        return response()->json([
            'success' => true,
            'data' => $earningDetails
        ]);
    }

    public function downloadEarningDetailsPdf(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'booking_code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = $this->authenticateUserFromRequest($request);
        if (!$driver) {
            return response()->json([
                'success' => false,
                'message' => 'Token Is Missing',
            ], 401);
        }

        $bookingCode = $request->booking_code;

        $booking = Booking::where('booking_code', $bookingCode)
            ->where('driver_id', $driver->id)
            ->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found',
                'error' => 'No booking found with the provided booking code'
            ], 404);
        }

        $booking->load(['user', 'driver']);

        $earningDetails = $this->buildEarningDetails($booking);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);

        $html = $this->generateEarningDetailsPdfHtml($booking, $earningDetails);

        // clear any previous output that might corrupt the PDF
        if (ob_get_length()) {
            ob_end_clean();
        }

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();
        $filename = 'earning-details-' . $bookingCode . '.pdf';

        return response($output, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('Content-Length', strlen($output));
    }

    private function generateEarningDetailsPdfHtml($booking, $earningDetails)
    {
        $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad($booking->id, 6, '0', STR_PAD_LEFT);
        $currentDate = now()->format('d M Y, h:i A');

        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Earning Details - ' . $earningDetails['booking_code'] . '</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 0;
                    padding: 20px;
                    color: #333;
                }
                .header {
                    text-align: center;
                    margin-bottom: 30px;
                    border-bottom: 2px solid #333;
                    padding-bottom: 20px;
                }
                .header h1 {
                    margin: 0;
                    color: #333;
                    font-size: 24px;
                }
                .header p {
                    margin: 5px 0;
                    color: #666;
                }
                .invoice-info {
                    display: table;
                    width: 100%;
                    margin-bottom: 30px;
                }
                .invoice-info-left, .invoice-info-right {
                    display: table-cell;
                    width: 50%;
                    vertical-align: top;
                }
                .invoice-info-right {
                    text-align: right;
                }
                .section {
                    margin-bottom: 25px;
                }
                .section-title {
                    font-size: 18px;
                    font-weight: bold;
                    margin-bottom: 15px;
                    color: #333;
                    border-bottom: 1px solid #ddd;
                    padding-bottom: 5px;
                }
                .info-row {
                    display: table;
                    width: 100%;
                    margin-bottom: 10px;
                }
                .info-label {
                    display: table-cell;
                    width: 40%;
                    font-weight: bold;
                    color: #555;
                }
                .info-value {
                    display: table-cell;
                    width: 60%;
                    color: #333;
                }
                .breakdown-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 10px;
                }
                .breakdown-table th {
                    background-color: #f5f5f5;
                    padding: 10px;
                    text-align: left;
                    border-bottom: 2px solid #ddd;
                    font-weight: bold;
                }
                .breakdown-table td {
                    padding: 10px;
                    border-bottom: 1px solid #eee;
                }
                .breakdown-table tr:last-child td {
                    border-bottom: 2px solid #ddd;
                    font-weight: bold;
                }
                .total-section {
                    margin-top: 20px;
                    padding: 15px;
                    background-color: #f9f9f9;
                    border-radius: 5px;
                }
                .total-row {
                    display: table;
                    width: 100%;
                    margin-bottom: 8px;
                }
                .total-label {
                    display: table-cell;
                    width: 70%;
                    font-weight: bold;
                    font-size: 16px;
                }
                .total-value {
                    display: table-cell;
                    width: 30%;
                    text-align: right;
                    font-weight: bold;
                    font-size: 16px;
                    color: #2c5aa0;
                }
                .footer {
                    margin-top: 40px;
                    text-align: center;
                    color: #666;
                    font-size: 12px;
                    border-top: 1px solid #ddd;
                    padding-top: 20px;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Earning Details Receipt</h1>
                <p>Invoice Number: ' . $invoiceNumber . '</p>
                <p>Generated on: ' . $currentDate . '</p>
            </div>

            <div class="invoice-info">
                <div class="invoice-info-left">
                    <div class="section">
                        <div class="section-title">Booking Information</div>
                        <div class="info-row">
                            <div class="info-label">Booking Code:</div>
                            <div class="info-value">' . htmlspecialchars($earningDetails['booking_code']) . '</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Distance:</div>
                            <div class="info-value">' . htmlspecialchars($earningDetails['distance']) . '</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Duration:</div>
                            <div class="info-value">' . htmlspecialchars($earningDetails['duration']) . '</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Payment Type:</div>
                            <div class="info-value">' . htmlspecialchars($earningDetails['payment_type']) . '</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section">
                <div class="section-title">Earning Breakdown</div>
                <table class="breakdown-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th style="text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Base Fare</td>
                            <td style="text-align: right;">' . number_format($earningDetails['earning_breakdown']['base_fare'], 2) . '</td>
                        </tr>
                        <tr>
                            <td>Distance Fare</td>
                            <td style="text-align: right;">' . number_format($earningDetails['earning_breakdown']['distance_fare'], 2) . '</td>
                        </tr>
                        <tr>
                            <td>Waiting Charge</td>
                            <td style="text-align: right;">' . number_format($earningDetails['earning_breakdown']['waiting_charge'], 2) . '</td>
                        </tr>
                        <tr>
                            <td>Time Fare</td>
                            <td style="text-align: right;">' . number_format($earningDetails['earning_breakdown']['time_fare'], 2) . '</td>
                        </tr>
                        <tr>
                            <td>Night Charge</td>
                            <td style="text-align: right;">' . number_format($earningDetails['earning_breakdown']['night_charge'], 2) . '</td>
                        </tr>
                        <tr>
                            <td>Booking Fare</td>
                            <td style="text-align: right;">' . number_format($earningDetails['earning_breakdown']['booking_fee'], 2) . '</td>
                        </tr>
                        <tr>
                            <td>Surge Charge</td>
                            <td style="text-align: right;">' . number_format($earningDetails['earning_breakdown']['surge_amount'], 2) . '</td>
                        </tr>
                        <tr>
                            <td>Tip Received</td>
                            <td style="text-align: right;">' . number_format($earningDetails['earning_breakdown']['tip_received'] ?? 0, 2) . '</td>
                        </tr>
                        <tr>
                            <td>Tax</td>
                            <td style="text-align: right;">' . number_format($earningDetails['earning_breakdown']['tax_amount'] ?? 0, 2) . '</td>
                        </tr>
                        <tr>
                            <td><strong>Total Fare</strong></td>
                            <td style="text-align: right;"><strong>' . number_format($earningDetails['earning_breakdown']['total_fare'], 2) . '</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="section">
                <div class="section-title">Deduction Breakdown</div>
                <table class="breakdown-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th style="text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Platform Fee</td>
                            <td style="text-align: right;">' . number_format($earningDetails['deduction_breakdown']['platform_fee'], 2) . '</td>
                        </tr>
                        <tr>
                            <td>Tax</td>
                            <td style="text-align: right;">' . number_format($earningDetails['deduction_breakdown']['tax'], 2) . '</td>
                        </tr>
                        <tr>
                            <td>Late Arrival Penalty</td>
                            <td style="text-align: right;">' . number_format($earningDetails['deduction_breakdown']['late_arrival_penalty'], 2) . '</td>
                        </tr>';

        // Add Total Discount Amount row
        $discountAmount = (float) ($booking->discount_amount ?? 0);
        if ($discountAmount > 0) {
            $promoCode = $booking->promo_code ?? '';
            if (!empty($promoCode)) {
                $html .= '
                        <tr>
                            <td>Total Discount Amount (Promo: ' . htmlspecialchars($promoCode) . ')</td>
                            <td style="text-align: right;">' . number_format($discountAmount, 2) . '</td>
                        </tr>';
            } else {
                $html .= '
                        <tr>
                            <td>Total Discount Amount</td>
                            <td style="text-align: right;">' . number_format($discountAmount, 2) . '</td>
                        </tr>';
            }
        } else {
            $html .= '
                        <tr>
                            <td>Total Discount Amount</td>
                            <td style="text-align: right;">' . number_format(0, 2) . '</td>
                        </tr>';
        }

        $html .= '
                        <tr>
                            <td><strong>Total Deduction</strong></td>
                            <td style="text-align: right;"><strong>' . number_format($earningDetails['deduction_breakdown']['total_deduction'], 2) . '</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="total-section">
                <div class="total-row">
                    <div class="total-label">Final Earning:</div>
                    <div class="total-value">' . number_format($earningDetails['final_earning'], 2) . '</div>
                </div>
            </div>

            <div class="footer">
                <p>This is a computer-generated receipt. No signature required.</p>
                <p>Thank you for using our service!</p>
            </div>
        </body>
        </html>';

        return $html;
    }

    public function getCancellationFeeDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();
        $transactionId = $request->transaction_id;

        $transaction = WalletTransaction::with('wallet')
            ->where('transection_id', $transactionId)
            ->first();

        if ($transaction) {
            $isDriverTransaction = false;

            if ($transaction->driver_id && $transaction->driver_id == $driver->id) {
                $isDriverTransaction = true;
            } elseif ($transaction->wallet && $transaction->wallet->driver_id && $transaction->wallet->driver_id == $driver->id) {
                $isDriverTransaction = true;
            } elseif (!$transaction->driver_id && $transaction->wallet && $transaction->wallet->user_id == $driver->id) {
                $isDriverTransaction = true;
            }

            $isPenaltyOrCancellation = false;
            if ($transaction->description) {
                $description = strtolower($transaction->description);
                $isPenaltyOrCancellation = (stripos($description, 'penalty') !== false) || (stripos($description, 'cancellation') !== false);
            }

            if (!$isDriverTransaction || !$isPenaltyOrCancellation) {
                $transaction = null;
            }
        }
        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found',
                'error' => 'No penalty/cancellation fee transaction found with the provided ID'
            ], 404);
        }

        $booking = null;
        if ($transaction->reference_type === 'App\Models\Booking') {
            $booking = Booking::find($transaction->reference_id);
        }

        $isPenalty = stripos($transaction->description, 'penalty') !== false;
        $isCancellation = stripos($transaction->description, 'cancellation') !== false;

        $tripCode = null;
        if (isset($transaction->meta_data['booking_code'])) {
            $tripCode = $transaction->meta_data['booking_code'];
        } elseif (isset($transaction->meta_data['trip_code'])) {
            $tripCode = $transaction->meta_data['trip_code'];
        }

        $penaltyData = [
            'transaction_id' => $transactionId,
            'wallet_transaction_id' => $transaction->id,
            'amount' => abs((float) $transaction->amount),
            'type' => $isPenalty ? 'Penalty' : 'Cancellation Fee',
            'transaction_type' => $isPenalty ? 'late_arrival_penalty' : 'cancellation_fee',
            'booking_code' => $booking ? $booking->booking_code : ($tripCode ?? 'N/A'),
            'booking_id' => $booking ? $booking->id : (isset($transaction->meta_data['booking_id']) ? $transaction->meta_data['booking_id'] : null),
            'trip_code' => $tripCode ?? ($booking ? $booking->booking_code : null),
            'date' => $transaction->created_at->format('d M Y'),
            'reason' => $isPenalty ? 'for Late Arrival' : ($transaction->description ?? 'Cancellation fee charged'),
            'description' => $transaction->description ?? '',
            'late_penalty_refund_approved' => isset($transaction->meta_data['late_penalty_refund_approved'])
                ? (bool) $transaction->meta_data['late_penalty_refund_approved']
                : false,
            'action_button_text' => 'Report Issue'
        ];

        return response()->json([
            'success' => true,
            'data' => $penaltyData
        ]);
    }

    public function getReportIssuesOptions(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'instruction_text' => 'Please select the reason for Reporting Cancellation Fee',
                'reason_options' => [
                    [
                        'id' => 1,
                        'text' => "Passenger didn't show up",
                        'is_selected' => false
                    ],
                    [
                        'id' => 2,
                        'text' => 'Incorrect Cancellation Fee Charged',
                        'is_selected' => false
                    ],
                    [
                        'id' => 3,
                        'text' => 'Route Blocked / Traffic Issues',
                        'is_selected' => true,
                        'description' => 'Unavoidable traffic or roadblock made me late, but I was on the way.'
                    ],
                    [
                        'id' => 4,
                        'text' => 'Rider Shared Wrong Pickup Location',
                        'is_selected' => false
                    ],
                    [
                        'id' => 5,
                        'text' => 'Navigation or App Error',
                        'is_selected' => false
                    ],
                    [
                        'id' => 6,
                        'text' => 'Other',
                        'is_selected' => false
                    ]
                ],
                'upload_screenshot' => [
                    'label' => 'Upload Screenshot',
                    'button_text' => 'Upload File'
                ],
                'submit_button_text' => 'Submit'
            ]
        ]);
    }

    public function submitReportIssue(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
            'reason_id' => 'required|integer',
            'description' => 'nullable|string|max:1000',
            'screenshot' => 'nullable|file|image|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();

        try {
            DB::beginTransaction();

            $issueReport = null;
            try {
                $issueReport = IssueReport::create([
                    'driver_id' => $driver->id,
                    'transaction_id' => $request->transaction_id,
                    'reason_id' => $request->reason_id,
                    'description' => $request->description,
                    'status' => 'pending',
                    'screenshot_path' => null,  // Handle file upload if needed
                ]);
            } catch (\Exception $e) {
                $issueReport = (object) [
                    'id' => rand(1000, 9999),
                    'driver_id' => $driver->id,
                    'transaction_id' => $request->transaction_id,
                    'reason_id' => $request->reason_id,
                    'description' => $request->description,
                    'status' => 'pending',
                ];
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Issue report submitted successfully',
                'data' => [
                    'report_id' => $issueReport->id,
                    'status' => 'pending'
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit issue report'
            ], 500);
        }
    }

    public function getRefundDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();
        $transactionId = $request->transaction_id;

        $refund = WalletTransaction::where('transection_id', $transactionId)
            ->first();

        $support_ticket_status = SupportTicket::where('transection_id', $transactionId)
            ->first();

        if ($support_ticket_status && ($support_ticket_status->status === 'resolved' || $support_ticket_status->status === 'closed')) {
            $status = 'Refund Approved';
        } else {
            $status = 'Pending';
        }

        if (!$refund) {
            $refundData = [
                'amount' => 16.0,
                'description' => 'Cancellation fee refunded after review',
                'status' => 'Refund Approved',
                'credited_to_wallet' => 16.0,
                'reviewed_by' => 'Support Team',
                'credited_on' => '08 Aug 2025',
                'reference_id' => 'TXN827194',
                'info_message' => 'Refund typically takes 3-5 business days to appear in your account depending on your bank processing time',
                'action_button_text' => 'Contact Support'
            ];
        } else {
            $refundData = [
                'amount' => $refund->amount,
                'description' => $refund->description,
                'status' => $status,
                'credited_to_wallet' => $refund->amount,
                'reviewed_by' => $refund->reviewed_by ?? 'Support Team',
                'credited_on' => $refund->processed_at ? $refund->processed_at->format('d M Y') : 'Pending',
                'reference_id' => $refund->reference_id,
                'info_message' => 'Refund typically takes 3-5 business days to appear in your account depending on your bank processing time',
                'action_button_text' => 'Contact Support'
            ];
        }

        return response()->json($this->sanitizeForApi([
            'success' => true,
            'data' => $refundData
        ]));
    }

    public function addToWallet(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:50|max:10000', // min amount is 50 because stripe not accept below 50
            'payment_method' => 'required|in:paypal,razorpay,cash,paytm,stripe,wallet',
            'currency' => 'nullable|string|in:INR,USD',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }


        $user = auth()->user();
        $amount = (float) $request->amount;
        $paymentMethod = strtolower($request->payment_method);
        $currency = strtoupper($request->currency ?? 'INR');

        if ($paymentMethod === 'wallet') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot top up wallet using wallet balance'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $wallet = DB::table('wallets')->where('user_id', $user->id)->first();

            if (!$wallet) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet not found. Please create a wallet first.'
                ], 404);
            }

            $updateData = [
                'status' => 'active',
                'updated_at' => now(),
            ];

            $isDriver = method_exists($user, 'hasRole') && $user->hasRole('driver');
            if ($isDriver) {
                $updateData['driver_id'] = $user->id;
            }

            DB::table('wallets')->where('id', $wallet->id)->update($updateData);
            $wallet = DB::table('wallets')->where('id', $wallet->id)->first();

            if ($paymentMethod === 'cash') {
                if (Schema::hasColumn('wallets', 'balance')) {
                    $wallet->increment('balance', $amount);
                    if (Schema::hasColumn('wallets', 'total_credit')) {
                        $wallet->increment('total_credit', $amount);
                    }
                    $wallet->update(['last_transaction_at' => now()]);
                    $wallet->refresh();
                    $newBalance = (float) $wallet->balance;
                } elseif (Schema::hasColumn('wallets', 'amount')) {
                    $currentAmount = (float) ($wallet->amount ?? 0);
                    $newAmount = $currentAmount + $amount;
                    $wallet->update(['amount' => $newAmount]);
                    $wallet->refresh();
                    $newBalance = (float) $wallet->amount;
                } else {
                    $newBalance = $amount;  // fallback
                }

                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'driver_id' => (method_exists($user, 'hasRole') && $user->hasRole('driver')) ? $user->id : null,
                    'type' => WalletTransaction::TYPE_WALLET_TOPUP,
                    'amount' => $amount,
                    'balance' => $newBalance,
                    'description' => 'Cash added to wallet',
                    'status' => WalletTransaction::STATUS_COMPLETED,
                    'reference_type' => Transaction::class,
                    'reference_id' => null,
                    'meta_data' => [
                        'payment_method' => 'cash',
                        'credited_at' => now()->toISOString(),
                    ],
                ]);

                DB::commit();

                $transactions = $wallet ? $this->getRecentWalletTransactions($wallet->id, 10) : [];

                return response()->json($this->sanitizeForApi([
                    'success' => true,
                    'message' => 'Amount added to wallet successfully',
                    'data' => [
                        'new_balance' => $newBalance,
                        'added_amount' => $amount,
                        'transactions' => $transactions,
                        'status' => 'completed',
                        'payment_method' => 'cash',
                    ]
                ]));
            }

            if ($paymentMethod === 'paypal') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'PayPal payment gateway not configured'
                ], 400);
            }

            $transaction = Transaction::create([
                'transaction_id' => 'WALLET_TOPUP_' . time() . '_' . rand(1000, 9999),
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => 'wallet_topup',
                'amount' => $amount,
                'balance' => $wallet->balance,
                'currency' => $currency,
                'payment_method' => $paymentMethod,
                'status' => 'pending',
                'description' => "Wallet top-up via {$paymentMethod}",
                'gateway_transaction_id' => null,
                'gateway_response' => null,
                'meta_data' => [
                    'wallet_id' => $wallet->id,
                    'wallet_balance_before' => $wallet->balance,
                    'transaction_context' => 'wallet_topup',
                ],
            ]);

            $gatewayResult = null;

            switch ($paymentMethod) {
                case 'razorpay':
                    $razorpayService = app(\App\Services\RazorpayService::class);
                    $customerPhone = $user->phone ?? '';
                    $customerPhone = preg_replace('/[^0-9]/', '', $customerPhone);
                    if (strlen($customerPhone) > 0 && count(array_unique(str_split($customerPhone))) === 1) {
                        $customerPhone = '';
                    }

                    $paymentLinkData = [
                        'amount' => $amount,
                        'currency' => $currency,
                        'description' => "Wallet top-up #{$transaction->transaction_id}",
                        'customer_name' => $user->name ?? '',
                        'customer_email' => $user->email ?? '',
                        'customer_phone' => $customerPhone,
                        'callback_url' => url('/api/payments/razorpay/callback'),
                        'notes' => [
                            'transaction_id' => $transaction->transaction_id,
                            'user_id' => $user->id,
                            'wallet_id' => $wallet->id,
                            'topup_type' => 'wallet_topup',
                        ],
                    ];

                    $gatewayResult = $razorpayService->createPaymentLink($paymentLinkData);
                    break;

                case 'stripe':
                    $stripeService = app(\App\Services\StripeService::class);
                    $gatewayResult = $stripeService->createPaymentLink([
                        'amount' => $amount,
                        'currency' => $currency,
                        'description' => "Wallet top-up #{$transaction->transaction_id}",
                        'metadata' => [
                            'transaction_id' => $transaction->transaction_id,
                            'user_id' => $user->id,
                            'wallet_id' => $wallet->id,
                            'topup_type' => 'wallet_topup',
                        ],
                        'success_url' => url('/api/payments/stripe/success?transaction_id=' . $transaction->transaction_id),
                        'cancel_url' => url('/api/payments/stripe/cancel?transaction_id=' . $transaction->transaction_id),
                    ]);
                    break;

                case 'paytm':
                    $paytmService = app(\App\Services\PaytmService::class);
                    $gatewayResult = $paytmService->createPaymentLink([
                        'order_id' => $transaction->transaction_id,
                        'amount' => $amount,
                        'customer_id' => 'USER_' . $user->id,
                        'customer_email' => $user->email ?? '',
                        'customer_mobile' => $user->phone ?? '',
                        'callback_url' => url('/api/payments/paytm/callback'),
                    ]);
                    break;

                default:
                    DB::rollBack();
                    throw new \Exception("Unsupported payment method: {$paymentMethod}");
            }

            if (!$gatewayResult || empty($gatewayResult['success'])) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => $gatewayResult['message'] ?? 'Failed to initialize payment',
                    'error' => $gatewayResult['error'] ?? null
                ], 400);
            }

            $transaction->update([
                'gateway_transaction_id' => $gatewayResult['order_id']
                    ?? $gatewayResult['payment_link_id']
                    ?? $gatewayResult['payment_intent_id']
                    ?? $gatewayResult['data']['id'] ?? null,
                'gateway_response' => $gatewayResult,
            ]);

            DB::commit();

            $responseData = [
                'transaction_id' => $transaction->transaction_id,
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'currency' => $currency,
                'payment_method' => $paymentMethod,
                'status' => 'pending',
                'wallet_balance' => $wallet->balance,
                'gateway_order_id' => $gatewayResult['order_id'] ?? $gatewayResult['payment_link_id'] ?? null,
            ];

            switch ($paymentMethod) {
                case 'razorpay':
                    $razorpayKeyConfig = \App\Models\SystemConfiguration::where('key', 'razorpay_key_id')
                        ->where('is_active', true)
                        ->first();
                    $razorpayKey = $razorpayKeyConfig ? $razorpayKeyConfig->value : null;

                    $responseData['razorpay_key'] = $razorpayKey ?? '';
                    $responseData['payment_link'] = $gatewayResult['short_url'] ?? '';
                    $responseData['razorpay_payment_link'] = $gatewayResult['short_url'] ?? '';
                    break;

                case 'stripe':
                    $stripePublishableKeyConfig = \App\Models\SystemConfiguration::where('key', 'stripe_publishable_key')
                        ->where('is_active', true)
                        ->first();
                    $responseData['stripe_publishable_key'] = $stripePublishableKeyConfig ? $stripePublishableKeyConfig->value : '';
                    $responseData['payment_link'] = $gatewayResult['url'] ?? '';
                    $responseData['stripe_payment_link'] = $gatewayResult['url'] ?? '';
                    $responseData['stripe_payment_link_id'] = $gatewayResult['payment_link_id'] ?? $gatewayResult['data']['id'] ?? null;
                    break;

                case 'paytm':
                    $responseData['paytm_params'] = $gatewayResult['params'] ?? [];
                    $responseData['paytm_form_url'] = $gatewayResult['form_url'] ?? '';
                    $responseData['paytm_payment_link'] = url("/api/payments/paytm/redirect/{$transaction->transaction_id}");
                    $responseData['payment_link'] = $responseData['paytm_payment_link'];
                    break;
            }

            return response()->json($this->sanitizeForApi([
                'success' => true,
                'message' => 'Wallet top-up initialized successfully',
                'data' => $responseData,
            ]));
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to add amount to wallet',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function initTransaction(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|exists:bookings,id',
            'payment_method' => 'required|in:razorpay,stripe,paytm,paypal,wallet,cash',
            'amount' => 'required|numeric|min:1',
            'currency' => 'string|in:INR,USD',
            'is_split' => 'sometimes|boolean',
            'wallet_amount' => 'nullable|numeric|min:1',
            'tip_amount' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();
        $booking = Booking::where('id', $request->booking_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $paymentMethod = $request->payment_method;
        $primaryPaymentMethod = $paymentMethod;
        $fareAmount = round((float) $request->amount, 2);
        $tipAmount = $request->filled('tip_amount')
            ? round(max((float) $request->tip_amount, 0), 2)
            : 0.0;
        $amount = round($fareAmount + $tipAmount, 2);
        $currency = $request->currency ?? 'INR';
        $isSplitPayment = $request->boolean('is_split', false);
        $requestedWalletAmount = $request->wallet_amount !== null ? (float) $request->wallet_amount : null;
        $walletContribution = 0.0;
        $payableAmount = $amount;
        $wallet = $user->wallet;

        if ($isSplitPayment) {
            if (!$wallet || $wallet->balance <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet not found or has insufficient balance for split payment'
                ], 400);
            }

            $usableWalletAmount = $requestedWalletAmount !== null
                ? min($requestedWalletAmount, $amount)
                : min($wallet->balance, $amount);

            if ($usableWalletAmount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid wallet amount for split payment'
                ], 400);
            }

            if ($wallet->balance < $usableWalletAmount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient wallet balance for requested split amount',
                    'data' => [
                        'available_balance' => $wallet->balance,
                        'requested_amount' => $usableWalletAmount,
                    ]
                ], 400);
            }

            $walletContribution = round($usableWalletAmount, 2);
            $payableAmount = round(max($amount - $walletContribution, 0), 2);

            if ($payableAmount < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet amount cannot exceed total payable amount'
                ], 400);
            }

            if ($payableAmount === 0.0) {
                $paymentMethod = 'wallet';
            }
        }
        try {
            DB::beginTransaction();

            $walletTransaction = null;

            // Only deduct wallet immediately if:
            // 1. Wallet-only payment (not split)
            // 2. Cash payment with split (deduct wallet immediately for cash)
            // For split payments with online gateways (razorpay/stripe), deduct wallet only after payment succeeds
            $shouldDeductWalletNow = false;
            if ($isSplitPayment && $walletContribution > 0) {
                // For split payments: only deduct wallet now if payment method is cash
                // For razorpay/stripe, wallet will be deducted in callback after payment succeeds
                if ($paymentMethod === 'cash') {
                    $shouldDeductWalletNow = true;
                }
            } elseif ($paymentMethod === 'wallet' && !$isSplitPayment) {
                // Regular wallet payment (not split) - deduct immediately
                $shouldDeductWalletNow = true;
            }

            if ($shouldDeductWalletNow && $walletContribution > 0) {
                $wallet->decrement('balance', $walletContribution);
                $wallet->refresh();

                $walletTransaction = \App\Models\WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'type' => 'debit',
                    'amount' => -$walletContribution,
                    'balance' => $wallet->balance,
                    'description' => "Split payment wallet deduction for ride #{$booking->booking_code}",
                    'reference_type' => 'App\Models\Booking',
                    'reference_id' => $booking->id,
                    'status' => 'completed',
                    'meta_data' => [
                        'booking_id' => $booking->id,
                        'booking_code' => $booking->booking_code,
                        'is_split_payment' => true,
                        'primary_payment_method' => $primaryPaymentMethod,
                        'tip_amount' => $tipAmount,
                        'fare_amount' => $fareAmount,
                        'total_amount' => $amount,
                    ],
                ]);
            }

            if ($paymentMethod === 'wallet' && $payableAmount <= 0 && $walletContribution > 0) {
                $transaction = \App\Models\Transaction::create([
                    'transaction_id' => 'TXN_' . time() . '_' . rand(1000, 9999),
                    'wallet_id' => $wallet->id ?? null,
                    'booking_id' => $booking->id,
                    'user_id' => $user->id,
                    'type' => 'payment',
                    'amount' => $walletContribution,
                    'balance' => $wallet ? $wallet->balance : 0,
                    'currency' => $currency,
                    'payment_method' => 'wallet',
                    'status' => 'completed',
                    'description' => "Payment for ride #{$booking->booking_code}",
                    'gateway_transaction_id' => 'WALLET_' . time(),
                    'gateway_response' => [
                        'wallet_payment' => true,
                        'wallet_contribution' => $walletContribution,
                        'split_payment' => true,
                        'processed_at' => now()->toISOString(),
                        'tip_amount' => $tipAmount,
                        'fare_amount' => $fareAmount,
                        'total_amount' => $amount,
                    ],
                    'meta_data' => [
                        'booking_code' => $booking->booking_code,
                        'user_id' => $user->id,
                        'user_name' => $user->name ?? '',
                        'user_phone' => $user->phone,
                        'is_split_payment' => true,
                        'wallet_amount' => $walletContribution,
                        'gateway_amount' => 0,
                        'primary_payment_method' => $primaryPaymentMethod,
                        'wallet_transaction_id' => optional($walletTransaction)->id,
                        'tip_amount' => $tipAmount,
                        'fare_amount' => $fareAmount,
                        'total_amount' => $amount,
                    ],
                ]);

                $walletOnlyBookingUpdate = [
                    'payment_method' => 'wallet',
                    'payment_status' => 'paid',
                    'online_paid_amount' => $walletContribution,
                    'cash_amount' => 0,
                    'wallet_amount' => $walletContribution,
                ];

                if ($tipAmount > 0) {
                    $walletOnlyBookingUpdate['tip_amount'] = $tipAmount;

                    // Recalculate driver_amount to include tip
                    $currentDriverAmount = (float) ($booking->driver_amount ?? 0);
                    $currentTipAmount = (float) ($booking->tip_amount ?? 0);
                    $baseDriverAmount = $currentDriverAmount - $currentTipAmount;
                    $walletOnlyBookingUpdate['driver_amount'] = $baseDriverAmount + $tipAmount;

                    // Update total_amount to include tip
                    $currentTotalAmount = (float) ($booking->total_amount ?? 0);
                    $currentTotalWithoutTip = $currentTotalAmount - $currentTipAmount;
                    $walletOnlyBookingUpdate['total_amount'] = $currentTotalWithoutTip + $tipAmount;
                }

                $booking->update($walletOnlyBookingUpdate);

                app(UserDebtService::class)->settleAppliedDebtsForBooking($booking->fresh());

                // Credit debt amount to admin wallet when payment is received
                $this->creditDebtAmountToAdminWallet($booking->fresh());

                // Process driver payout for wallet-only split payments (credit driver wallet immediately)
                if ($booking->driver_id && empty($booking->driver_payout_scheduled_at)) {
                    try {
                        $booking->refresh();
                        // Use driver_amount from booking (already includes tip, commission already deducted)
                        $driverAmount = (float) ($booking->driver_amount ?? 0);
                        if ($driverAmount > 0) {
                            $driver = $booking->driver;
                            $driverWallet = $driver->wallet;

                            if (!$driverWallet) {
                                $driverWallet = \App\Models\Wallet::create([
                                    'user_id' => $driver->id,
                                    'balance' => 0,
                                ]);
                            }

                            // Credit driver wallet directly with driver_amount (includes tip)
                            $driverWallet->increment('balance', $driverAmount);
                            $driverWallet->refresh();

                            \App\Models\WalletTransaction::create([
                                'wallet_id' => $driverWallet->id,
                                'type' => 'credit',
                                'payment_type' => 'wallet',
                                'amount' => $driverAmount,
                                'balance' => $driverWallet->balance,
                                'description' => "Earnings from ride #{$booking->booking_code}",
                                'reference_type' => 'App\Models\Booking',
                                'reference_id' => $booking->id,
                                'status' => 'completed',
                            ]);

                            $booking->update([
                                'driver_payout_status' => \App\Models\Booking::DRIVER_PAYOUT_COMPLETED,
                                'driver_payout_released_at' => now(),
                            ]);



                            // Credit admin commission to admin wallet
                            $adminCommission = (float) ($booking->admin_commission ?? 0);
                            if ($adminCommission > 0) {
                                $adminUser = \App\Models\User::find(1);
                                if ($adminUser) {
                                    $walletService = app(\App\Services\WalletService::class);
                                    $adminWallet = $walletService->ensureWallet($adminUser);

                                    $adminWallet->credit(
                                        $adminCommission,
                                        \App\Models\WalletTransaction::TYPE_DRIVER_COMMISSION,
                                        "Commission from booking #{$booking->booking_code}",
                                        [
                                            'booking_id' => $booking->id,
                                            'booking_code' => $booking->booking_code,
                                            'driver_id' => $driver->id,
                                            'total_amount' => $booking->total_amount,
                                            'commission_rate' => $booking->admin_commission_rate ?? 20.0,
                                            'driver_amount' => $driverAmount,
                                            'payment_method' => 'wallet',
                                            'credited_at' => now()->toDateTimeString(),
                                        ]
                                    );
                                }
                            }
                        }
                    } catch (\Exception $e) {
                    }
                }

                if ($booking->driver_id) {
                    try {
                        $booking->refresh();
                        app(NotificationService::class)->sendPaymentCompletionNotificationToDriver($booking->fresh());
                    } catch (\Exception $e) {
                    }
                }

                $this->broadcastPaymentSuccess($transaction);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Wallet payment processed successfully',
                    'data' => [
                        'transaction_id' => $transaction->transaction_id,
                        'gateway_order_id' => $transaction->gateway_transaction_id,
                        'amount' => $walletContribution,
                        'currency' => $currency,
                        'payment_method' => 'wallet',
                        'booking_id' => $booking->id,
                        'booking_code' => $booking->booking_code,
                        'wallet_payment' => true,
                        'wallet_amount' => $walletContribution,
                        'split_payment' => true,
                        'wallet_balance' => $wallet ? $wallet->balance : 0,
                        'tip_amount' => $tipAmount,
                        'fare_amount' => $fareAmount,
                        'total_amount' => $amount,
                    ]
                ]);
            }

            $transaction = \App\Models\Transaction::create([
                'transaction_id' => 'TXN_' . time() . '_' . rand(1000, 9999),
                'wallet_id' => null,
                'booking_id' => $booking->id,
                'user_id' => $user->id,
                'type' => 'payment',
                'amount' => $payableAmount,
                'balance' => 0,
                'currency' => $currency,
                'payment_method' => $paymentMethod === 'razorpay' ? 'card' : $paymentMethod,
                'status' => 'pending',
                'description' => "Payment for ride #{$booking->booking_code}",
                'gateway_transaction_id' => null,
                'gateway_response' => null,
                'meta_data' => [
                    'booking_code' => $booking->booking_code,
                    'user_id' => $user->id,
                    'user_name' => $user->name ?? '',
                    'user_phone' => $user->phone,
                    'is_split_payment' => $isSplitPayment,
                    'primary_payment_method' => $primaryPaymentMethod,
                    'wallet_amount' => $walletContribution,
                    'gateway_amount' => $payableAmount,
                    'requested_amount' => $amount,
                    'wallet_transaction_id' => optional($walletTransaction)->id,
                    'tip_amount' => $tipAmount,
                    'fare_amount' => $fareAmount,
                ],
            ]);

            $paymentGateway = app(PaymentGatewayService::class);
            $gatewayResult = null;

            switch ($paymentMethod) {
                case 'razorpay':
                    $razorpayService = app(\App\Services\RazorpayService::class);

                    $customerPhone = $user->phone ?? '';
                    $customerPhone = preg_replace('/[^0-9]/', '', $customerPhone);
                    if (strlen($customerPhone) > 0 && count(array_unique(str_split($customerPhone))) === 1) {
                        $customerPhone = '';
                    }

                    $paymentLinkData = [
                        'amount' => $payableAmount,
                        'currency' => $currency,
                        'description' => "Payment for ride #{$booking->booking_code}",
                        'customer_name' => $user->name ?? '',
                        'customer_email' => $user->email ?? '',
                        'customer_phone' => $customerPhone,
                        'callback_url' => url('/api/payments/razorpay/callback'),
                        'notes' => [
                            'booking_id' => $booking->id,
                            'booking_code' => $booking->booking_code,
                            'user_id' => $user->id,
                            'transaction_id' => $transaction->transaction_id,
                            'tip_amount' => $tipAmount,
                        ]
                    ];



                    $gatewayResult = $razorpayService->createPaymentLink($paymentLinkData);

                    break;

                case 'stripe':
                    // Stripe minimum amount validation
                    // Stripe requires minimum $0.50 USD equivalent
                    // Note: Setting to ₹1 will allow attempts but Stripe will reject amounts below ~₹42
                    $stripeMinimums = [
                        'INR' => 1.0,  // ₹1 minimum (WARNING: Stripe will reject amounts below ~₹42)
                        'USD' => 0.50,  // $0.50 minimum
                    ];
                    $stripeMinimum = $stripeMinimums[$currency] ?? 0.50;

                    if ($payableAmount < $stripeMinimum) {
                        $errorMessage = "The remaining payment amount ({$currency} " . number_format($payableAmount, 2) . ") is below Stripe's minimum requirement ({$currency} " . number_format($stripeMinimum, 2) . "). ";

                        // Check if user has wallet and suggest using it
                        $hasWallet = $wallet && $wallet->balance > 0;
                        $suggestedWalletAmount = $hasWallet ? min($wallet->balance, $amount) : 0;

                        if ($isSplitPayment && $walletContribution > 0) {
                            $errorMessage .= "Please increase your wallet contribution to cover the full amount, or use a different payment method.";
                        } elseif ($hasWallet && ($suggestedWalletAmount >= $amount)) {
                            $errorMessage .= "You can use your wallet balance ({$currency} " . number_format($wallet->balance, 2) . ") to pay for this booking instead.";
                        } else {
                            $alternativesList = [];
                            if ($hasWallet) {
                                $alternativesList[] = "wallet";
                            }
                            if (config('services.razorpay.enabled', false)) {
                                $alternativesList[] = "Razorpay";
                            }
                            $alternativesList[] = "cash";

                            $alternativesText = implode(", ", $alternativesList);
                            $errorMessage .= "Please use a different payment method ({$alternativesText}) or contact support.";
                        }



                        DB::rollBack();

                        // Build alternative payment methods suggestion
                        $alternatives = [];

                        // Add wallet option (even if insufficient, user can use split payment)
                        if ($hasWallet) {
                            $alternatives[] = [
                                'method' => 'wallet',
                                'available' => true,
                                'balance' => $wallet->balance,
                                'sufficient_balance' => $wallet->balance >= $amount,
                                'message' => $wallet->balance >= $amount
                                    ? 'Use wallet payment (sufficient balance available)'
                                    : 'Use wallet payment with split payment (balance: ' . number_format($wallet->balance, 2) . ' ' . $currency . ')'
                            ];
                        }

                        // Add Razorpay as alternative (may accept smaller amounts)
                        if (config('services.razorpay.enabled', false)) {
                            $alternatives[] = [
                                'method' => 'razorpay',
                                'available' => true,
                                'message' => 'Try Razorpay (UPI/Card/Net Banking) - may accept smaller amounts'
                            ];
                        }

                        // Always add cash option
                        $alternatives[] = [
                            'method' => 'cash',
                            'available' => true,
                            'message' => 'Pay with cash after the ride'
                        ];

                        return response()->json([
                            'success' => false,
                            'message' => $errorMessage,
                            'error' => 'amount_too_small',
                            'data' => [
                                'payable_amount' => $payableAmount,
                                'currency' => $currency,
                                'minimum_required' => $stripeMinimum,
                                'is_split_payment' => $isSplitPayment,
                                'wallet_contribution' => $walletContribution,
                                'total_amount' => $amount,
                                'alternative_payment_methods' => $alternatives
                            ]
                        ], 400);
                    }

                    $stripeService = app(\App\Services\StripeService::class);
                    $gatewayResult = $stripeService->createPaymentLink([
                        'amount' => $payableAmount,
                        'currency' => $currency,
                        'description' => "Payment for ride #{$booking->booking_code}",
                        'customer_email' => $user->email ?? '',
                        'success_url' => url('/api/payments/stripe/success?transaction_id=' . $transaction->transaction_id),
                        'cancel_url' => url('/api/payments/stripe/cancel?transaction_id=' . $transaction->transaction_id),
                        'metadata' => [
                            'booking_id' => $booking->id,
                            'booking_code' => $booking->booking_code,
                            'user_id' => $user->id,
                            'user_name' => $user->name ?? '',
                            'transaction_id' => $transaction->transaction_id,
                            'tip_amount' => $tipAmount,
                        ]
                    ]);
                    break;

                case 'paytm':
                    $paytmService = app(\App\Services\PaytmService::class);
                    $gatewayResult = $paytmService->createPaymentLink([
                        'order_id' => $transaction->transaction_id,
                        'amount' => $payableAmount,
                        'customer_id' => 'USER_' . $user->id,
                        'customer_email' => $user->email ?? '',
                        'customer_mobile' => $user->phone ?? '',
                        'callback_url' => url('/api/payments/paytm/callback'),
                        'tip_amount' => $tipAmount,
                    ]);
                    break;

                case 'paypal':
                    $gatewayResult = [
                        'success' => false,
                        'message' => 'PayPal payment gateway not configured'
                    ];
                    break;

                case 'cash':
                    $gatewayResult = [
                        'success' => true,
                        'message' => 'Cash payment initialized',
                        'order_id' => 'CASH_' . time(),
                        'data' => [
                            'id' => 'CASH_' . time(),
                            'payment_method' => 'cash',
                            'tip_amount' => $tipAmount,
                            'fare_amount' => $fareAmount,
                            'total_amount' => $amount,
                        ]
                    ];

                    $transaction->update([
                        'status' => 'completed',
                        'gateway_transaction_id' => 'CASH_' . time(),
                        'gateway_response' => [
                            'cash_payment' => true,
                            'processed_at' => now()->toISOString(),
                            'tip_amount' => $tipAmount,
                            'fare_amount' => $fareAmount,
                            'total_amount' => $amount,
                        ]
                    ]);

                    // IMPORTANT: If booking is already 'paid' (e.g., auto-completed), don't change payment_status to 'pending'
                    $currentPaymentStatus = $booking->payment_status ?? 'pending';
                    $shouldUpdatePaymentStatus = $currentPaymentStatus !== 'paid';

                    $cashBookingUpdate = [
                        'payment_method' => 'cash',
                        'cash_amount' => $payableAmount,
                        'online_paid_amount' => 0,
                        'wallet_amount' => $walletContribution,
                    ];

                    // Only update payment_status to 'pending' if it's not already 'paid'
                    if ($shouldUpdatePaymentStatus) {
                        $cashBookingUpdate['payment_status'] = 'pending';
                    }

                    if ($tipAmount > 0) {
                        $cashBookingUpdate['tip_amount'] = $tipAmount;

                        // Recalculate driver_amount to include tip
                        $currentDriverAmount = (float) ($booking->driver_amount ?? 0);
                        $currentTipAmount = (float) ($booking->tip_amount ?? 0);
                        $baseDriverAmount = $currentDriverAmount - $currentTipAmount;
                        $cashBookingUpdate['driver_amount'] = $baseDriverAmount + $tipAmount;

                        // Update total_amount to include tip
                        $currentTotalAmount = (float) ($booking->total_amount ?? 0);
                        $currentTotalWithoutTip = $currentTotalAmount - $currentTipAmount;
                        $cashBookingUpdate['total_amount'] = $currentTotalWithoutTip + $tipAmount;
                    }

                    $booking->update($cashBookingUpdate);

                    // Process commission deduction for cash payments
                    if ($booking->driver_id) {
                        try {
                            $booking->load(['driver', 'rideType']);
                            $driver = $booking->driver;

                            if ($driver) {
                                // Get commission amount - use booking's admin_commission if available, otherwise calculate it
                                $commissionAmount = 0;
                                $platformCommissionRate = $booking->admin_commission_rate ?? 20.0;

                                if ($booking->admin_commission && $booking->admin_commission > 0) {
                                    $commissionAmount = round((float) $booking->admin_commission, 2);
                                } else {
                                    // Calculate commission if not set on booking
                                    $rideTypeCommissionRate = $booking->rideType->commission_rate ?? 20.0;
                                    $driverCommissionRate = $driver->driverProfile ? ($driver->driverProfile->commission_rate ?? null) : null;
                                    $platformCommissionRate = $rideTypeCommissionRate ?? $driverCommissionRate;
                                    $platformCommissionRate = max(0, min(100, $platformCommissionRate));

                                    // Use booking's total_amount for commission calculation
                                    $commissionAmount = ($booking->total_amount * $platformCommissionRate) / 100;
                                    $commissionAmount = round($commissionAmount, 2);
                                }

                                if ($commissionAmount > 0) {
                                    $walletService = app(\App\Services\WalletService::class);

                                    // Deduct commission from driver wallet (allow negative balance)
                                    $driverWallet = $walletService->ensureWallet($driver);
                                    $walletTransaction = $driverWallet->debit(
                                        $commissionAmount,
                                        WalletTransaction::TYPE_DRIVER_COMMISSION,
                                        "Commission deducted for cash booking #{$booking->booking_code}",
                                        [
                                            'booking_id' => $booking->id,
                                            'booking_code' => $booking->booking_code,
                                            'user_id' => $booking->user_id,
                                            'total_amount' => $booking->total_amount,
                                            'commission_rate' => $platformCommissionRate,
                                            'driver_amount' => $booking->total_amount - $commissionAmount,
                                            'payment_method' => 'cash',
                                            'debited_at' => now()->toDateTimeString(),
                                        ],
                                        null,
                                        true // Allow negative balance
                                    );

                                    $transactionId = 'COMM_DRV_' . time() . '_' . rand(1000, 9999);
                                    $walletTransaction->update([
                                        'transection_id' => $transactionId,
                                        'reference_type' => Booking::class,
                                        'reference_id' => $booking->id,
                                    ]);

                                    // Deduct tax amount from driver wallet (tax is paid by admin for COD)
                                    $taxAmount = (float) ($booking->tax_amount ?? 0);
                                    if ($taxAmount > 0) {
                                        $taxTransaction = $driverWallet->debit(
                                            $taxAmount,
                                            WalletTransaction::TYPE_ADJUSTMENT,
                                            "Tax deducted for cash booking #{$booking->booking_code}",
                                            [
                                                'booking_id' => $booking->id,
                                                'booking_code' => $booking->booking_code,
                                                'user_id' => $booking->user_id,
                                                'tax_amount' => $taxAmount,
                                                'payment_method' => 'cash',
                                                'debited_at' => now()->toDateTimeString(),
                                            ],
                                            null,
                                            true // Allow negative balance
                                        );

                                        $taxTransactionId = 'TAX_DRV_' . time() . '_' . rand(1000, 9999);
                                        $taxTransaction->update([
                                            'transection_id' => $taxTransactionId,
                                            'reference_type' => Booking::class,
                                            'reference_id' => $booking->id,
                                        ]);
                                    }

                                    // Add commission to admin wallet (user id = 1)
                                    $adminUser = User::find(1);
                                    if ($adminUser) {
                                        $adminWallet = $walletService->ensureWallet($adminUser);
                                        $commission = Commission::where('booking_id', $booking->id)->first();

                                        $adminWalletTransaction = $adminWallet->credit(
                                            $commissionAmount,
                                            WalletTransaction::TYPE_DRIVER_COMMISSION,
                                            "Commission from cash booking #{$booking->booking_code}",
                                            [
                                                'booking_id' => $booking->id,
                                                'booking_code' => $booking->booking_code,
                                                'driver_id' => $driver->id,
                                                'total_amount' => $booking->total_amount,
                                                'commission_rate' => $platformCommissionRate,
                                                'driver_amount' => $booking->total_amount - $commissionAmount,
                                                'payment_method' => 'cash',
                                                'commission_id' => $commission ? $commission->id : null,
                                                'credited_at' => now()->toDateTimeString(),
                                            ]
                                        );

                                        $adminTransactionId = 'COMM_ADMIN_' . time() . '_' . rand(1000, 9999);
                                        $adminWalletTransaction->update([
                                            'transection_id' => $adminTransactionId,
                                            'reference_type' => $commission ? Commission::class : Booking::class,
                                            'reference_id' => $commission ? $commission->id : $booking->id,
                                        ]);
                                    } else {
                                        // Admin user not found for commission credit
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            // Don't fail the transaction if commission processing fails
                        }
                    }

                    $this->broadcastPaymentSuccess($transaction);

                    break;

                case 'wallet':
                    $wallet = $wallet ?? $user->wallet;

                    if (!$wallet) {
                        $gatewayResult = [
                            'success' => false,
                            'message' => 'Wallet not found',
                            'error' => 'User wallet does not exist'
                        ];
                        break;
                    }

                    if (!$isSplitPayment) {
                        if ($wallet->balance < $payableAmount) {
                            $gatewayResult = [
                                'success' => false,
                                'message' => 'Insufficient wallet balance',
                                'error' => 'Wallet balance is insufficient for this transaction'
                            ];
                            break;
                        }

                        $wallet->decrement('balance', $payableAmount);
                        $wallet->refresh();

                        $walletTransaction = \App\Models\WalletTransaction::create([
                            'wallet_id' => $wallet->id,
                            'type' => 'debit',
                            'amount' => -$payableAmount,  // Negative amount for debit
                            'balance' => $wallet->balance,  // Current balance after deduction
                            'description' => "Payment for ride #{$booking->booking_code}",
                            'reference_type' => 'App\Models\Booking',
                            'reference_id' => $booking->id,
                            'status' => 'completed',
                        ]);
                        $walletContribution = $payableAmount;
                    }

                    $transaction->update([
                        'status' => 'completed',
                        'gateway_transaction_id' => 'WALLET_' . time(),
                        'gateway_response' => [
                            'wallet_payment' => true,
                            'wallet_balance' => $wallet->balance,
                            'processed_at' => now()->toISOString(),
                            'split_payment' => $isSplitPayment,
                            'wallet_contribution' => $walletContribution,
                            'wallet_transaction_id' => optional($walletTransaction)->id,
                            'tip_amount' => $tipAmount,
                            'fare_amount' => $fareAmount,
                            'total_amount' => $amount,
                        ],
                        'meta_data' => array_merge($transaction->meta_data ?? [], [
                            'wallet_amount' => $walletContribution,
                            'gateway_amount' => 0,
                            'split_payment' => $isSplitPayment,
                            'wallet_transaction_id' => optional($walletTransaction)->id,
                            'tip_amount' => $tipAmount,
                            'fare_amount' => $fareAmount,
                            'total_amount' => $amount,
                        ]),
                    ]);

                    $walletBookingUpdate = [
                        'payment_method' => 'wallet',
                        'payment_status' => 'paid',
                        'online_paid_amount' => $walletContribution,
                        'cash_amount' => 0,
                        'wallet_amount' => $walletContribution,
                    ];

                    if ($tipAmount > 0) {
                        $walletBookingUpdate['tip_amount'] = $tipAmount;

                        // Recalculate driver_amount to include tip
                        $currentDriverAmount = (float) ($booking->driver_amount ?? 0);
                        $currentTipAmount = (float) ($booking->tip_amount ?? 0);
                        $baseDriverAmount = $currentDriverAmount - $currentTipAmount;
                        $walletBookingUpdate['driver_amount'] = $baseDriverAmount + $tipAmount;

                        // Update total_amount to include tip
                        $currentTotalAmount = (float) ($booking->total_amount ?? 0);
                        $currentTotalWithoutTip = $currentTotalAmount - $currentTipAmount;
                        $walletBookingUpdate['total_amount'] = $currentTotalWithoutTip + $tipAmount;
                    }

                    $booking->update($walletBookingUpdate);

                    app(UserDebtService::class)->settleAppliedDebtsForBooking($booking->fresh());

                    // Credit debt amount to admin wallet when payment is received
                    $this->creditDebtAmountToAdminWallet($booking->fresh());

                    // Process driver payout for wallet payments (credit driver wallet immediately)
                    if ($booking->driver_id && empty($booking->driver_payout_scheduled_at)) {
                        try {
                            $booking->refresh();
                            // Use driver_amount from booking (already includes tip, commission already deducted)
                            $driverAmount = (float) ($booking->driver_amount ?? 0);
                            if ($driverAmount > 0) {
                                $driver = $booking->driver;
                                $driverWallet = $driver->wallet;

                                if (!$driverWallet) {
                                    $driverWallet = \App\Models\Wallet::create([
                                        'user_id' => $driver->id,
                                        'balance' => 0,
                                    ]);
                                }

                                // Credit driver wallet directly with driver_amount (includes tip)
                                $driverWallet->increment('balance', $driverAmount);
                                $driverWallet->refresh();

                                \App\Models\WalletTransaction::create([
                                    'wallet_id' => $driverWallet->id,
                                    'type' => 'credit',
                                    'payment_type' => 'wallet',
                                    'amount' => $driverAmount,
                                    'balance' => $driverWallet->balance,
                                    'description' => "Earnings from ride #{$booking->booking_code}",
                                    'reference_type' => 'App\Models\Booking',
                                    'reference_id' => $booking->id,
                                    'status' => 'completed',
                                ]);

                                $booking->update([
                                    'driver_payout_status' => \App\Models\Booking::DRIVER_PAYOUT_COMPLETED,
                                    'driver_payout_released_at' => now(),
                                ]);



                                // Credit admin commission to admin wallet
                                $adminCommission = (float) ($booking->admin_commission ?? 0);
                                if ($adminCommission > 0) {
                                    $adminUser = \App\Models\User::find(1);
                                    if ($adminUser) {
                                        $walletService = app(\App\Services\WalletService::class);
                                        $adminWallet = $walletService->ensureWallet($adminUser);

                                        $adminWallet->credit(
                                            $adminCommission,
                                            \App\Models\WalletTransaction::TYPE_DRIVER_COMMISSION,
                                            "Commission from booking #{$booking->booking_code}",
                                            [
                                                'booking_id' => $booking->id,
                                                'booking_code' => $booking->booking_code,
                                                'driver_id' => $driver->id,
                                                'total_amount' => $booking->total_amount,
                                                'commission_rate' => $booking->admin_commission_rate ?? 20.0,
                                                'driver_amount' => $driverAmount,
                                                'payment_method' => 'wallet',
                                                'credited_at' => now()->toDateTimeString(),
                                            ]
                                        );
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                        }
                    }

                    if ($booking->driver_id) {
                        try {
                            $booking->refresh();
                            app(NotificationService::class)->sendPaymentCompletionNotificationToDriver($booking->fresh());
                        } catch (\Exception $e) {
                        }
                    }

                    $this->broadcastPaymentSuccess($transaction);

                    $gatewayResult = [
                        'success' => true,
                        'message' => 'Wallet payment processed successfully',
                        'order_id' => 'WALLET_' . time(),
                        'data' => [
                            'id' => 'WALLET_' . time(),
                            'payment_method' => 'wallet',
                            'wallet_balance' => $wallet->balance,
                            'split_payment' => $isSplitPayment,
                            'wallet_amount' => $walletContribution,
                            'tip_amount' => $tipAmount,
                            'fare_amount' => $fareAmount,
                            'total_amount' => $amount,
                        ]
                    ];
                    break;

                default:
                    throw new \Exception("Unsupported payment method: {$paymentMethod}");
            }

            if (!$gatewayResult['success']) {
                DB::rollBack();

                // Handle Stripe amount_too_small error with alternatives
                $isAmountTooSmall = ($gatewayResult['error'] === 'amount_too_small' ||
                    ($gatewayResult['error_code'] ?? null) === 'amount_too_small') &&
                    $paymentMethod === 'stripe';

                if ($isAmountTooSmall) {
                    // Build alternative payment methods
                    $alternatives = [];
                    $hasWallet = $wallet && $wallet->balance > 0;

                    if ($hasWallet) {
                        $alternatives[] = [
                            'method' => 'wallet',
                            'available' => true,
                            'balance' => $wallet->balance,
                            'sufficient_balance' => $wallet->balance >= $amount,
                            'message' => $wallet->balance >= $amount
                                ? 'Use wallet payment (sufficient balance available)'
                                : 'Use wallet payment with split payment (balance: ' . number_format($wallet->balance, 2) . ' ' . $currency . ')'
                        ];
                    }

                    if (config('services.razorpay.enabled', false)) {
                        $alternatives[] = [
                            'method' => 'razorpay',
                            'available' => true,
                            'message' => 'Try Razorpay (UPI/Card/Net Banking) - may accept smaller amounts'
                        ];
                    }

                    $alternatives[] = [
                        'method' => 'cash',
                        'available' => true,
                        'message' => 'Pay with cash after the ride'
                    ];

                    $errorMessage = $gatewayResult['message'] ?? 'Payment amount is too small for Stripe. ';
                    $errorMessage .= "Please use a different payment method or contact support.";

                    return response()->json([
                        'success' => false,
                        'message' => $errorMessage,
                        'error' => 'amount_too_small',
                        'data' => [
                            'payable_amount' => $payableAmount,
                            'currency' => $currency,
                            'is_split_payment' => $isSplitPayment,
                            'wallet_contribution' => $walletContribution,
                            'total_amount' => $amount,
                            'alternative_payment_methods' => $alternatives,
                            'stripe_error' => $gatewayResult['stripe_error'] ?? null
                        ]
                    ], 400);
                }

                return response()->json([
                    'success' => false,
                    'message' => $gatewayResult['message'] ?? 'Failed to initialize payment',
                    'error' => $gatewayResult['error'] ?? null,
                    'data' => $gatewayResult['stripe_error'] ?? null
                ], 400);
            }

            if ($paymentMethod !== 'wallet' && $paymentMethod !== 'cash') {
                $transaction->update([
                    'gateway_transaction_id' => $gatewayResult['order_id'] ?? $gatewayResult['data']['id'] ?? null,
                    'gateway_response' => $gatewayResult,
                ]);
            }

            DB::commit();

            // Update booking for split payments (cash or online)
            if ($isSplitPayment && $walletContribution > 0 && $paymentMethod !== 'wallet') {
                $existingMetaData = $booking->meta_data ?? [];
                // IMPORTANT: If booking is already 'paid' (e.g., auto-completed), don't change payment_status to 'pending'
                $currentPaymentStatus = $booking->payment_status ?? 'pending';
                $shouldUpdatePaymentStatus = $currentPaymentStatus !== 'paid';

                $splitBookingUpdate = [
                    'wallet_amount' => $walletContribution,
                    'payment_method' => 'split',
                    'online_paid_amount' => 0,
                    'cash_amount' => $paymentMethod === 'cash' ? $payableAmount : 0,
                    'meta_data' => array_merge($existingMetaData, [
                        'original_payment_method' => $primaryPaymentMethod,
                        'is_split_payment' => true,
                    ]),
                ];

                // Only update payment_status to 'pending' if it's not already 'paid'
                if ($shouldUpdatePaymentStatus) {
                    $splitBookingUpdate['payment_status'] = 'pending';
                }

                if ($tipAmount > 0) {
                    $splitBookingUpdate['tip_amount'] = $tipAmount;

                    // Recalculate driver_amount to include tip
                    $currentDriverAmount = (float) ($booking->driver_amount ?? 0);
                    $currentTipAmount = (float) ($booking->tip_amount ?? 0);
                    $baseDriverAmount = $currentDriverAmount - $currentTipAmount;
                    $splitBookingUpdate['driver_amount'] = $baseDriverAmount + $tipAmount;

                    // Update total_amount to include tip
                    $currentTotalAmount = (float) ($booking->total_amount ?? 0);
                    $currentTotalWithoutTip = $currentTotalAmount - $currentTipAmount;
                    $splitBookingUpdate['total_amount'] = $currentTotalWithoutTip + $tipAmount;
                }

                $booking->update($splitBookingUpdate);
            } elseif ($paymentMethod !== 'wallet' && $paymentMethod !== 'cash') {
                // For non-split online payments (razorpay, stripe, paytm, etc.), update payment_method immediately
                // IMPORTANT: If booking is already 'paid' (e.g., auto-completed), don't change payment_status to 'pending'
                $currentPaymentStatus = $booking->payment_status ?? 'pending';
                $shouldUpdatePaymentStatus = $currentPaymentStatus !== 'paid';

                $onlineBookingUpdate = [
                    'payment_method' => $paymentMethod,
                ];

                // Only update payment_status to 'pending' if it's not already 'paid'
                if ($shouldUpdatePaymentStatus) {
                    $onlineBookingUpdate['payment_status'] = 'pending';
                }

                if ($tipAmount > 0) {
                    // Save tip_amount and recalculate driver_amount
                    $currentDriverAmount = (float) ($booking->driver_amount ?? 0);
                    $currentTipAmount = (float) ($booking->tip_amount ?? 0);
                    $baseDriverAmount = $currentDriverAmount - $currentTipAmount;
                    $currentTotalAmount = (float) ($booking->total_amount ?? 0);
                    $currentTotalWithoutTip = $currentTotalAmount - $currentTipAmount;

                    $onlineBookingUpdate['tip_amount'] = $tipAmount;
                    $onlineBookingUpdate['driver_amount'] = $baseDriverAmount + $tipAmount;
                    $onlineBookingUpdate['total_amount'] = $currentTotalWithoutTip + $tipAmount;
                }

                $booking->update($onlineBookingUpdate);
            }



            $responseData = [
                'transaction_id' => $transaction->transaction_id,
                'gateway_order_id' => $gatewayResult['order_id'] ?? $gatewayResult['data']['id'] ?? null,
                'amount' => $payableAmount,
                'currency' => $currency,
                'payment_method' => $paymentMethod,
                'booking_id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'split_payment' => $isSplitPayment,
                'wallet_amount' => $walletContribution,
                'gateway_amount' => $payableAmount,
                'tip_amount' => $tipAmount,
                'total_amount' => $amount,
                'fare_amount' => $fareAmount,
            ];

            if ($paymentMethod === 'razorpay') {
                $razorpayKeyConfig = \App\Models\SystemConfiguration::where('key', 'razorpay_key_id')
                    ->where('is_active', true)
                    ->first();
                $razorpayKey = $razorpayKeyConfig ? $razorpayKeyConfig->value : null;

                $responseData['razorpay_key'] = $razorpayKey ?? '';
                $responseData['razorpay_order_id'] = $gatewayResult['order_id'] ?? '';
                $responseData['razorpay_payment_link'] = $gatewayResult['short_url'] ?? '';
                $responseData['payment_link'] = $gatewayResult['short_url'] ?? '';
            } elseif ($paymentMethod === 'stripe') {
                $stripePublishableKeyConfig = \App\Models\SystemConfiguration::where('key', 'stripe_publishable_key')
                    ->where('is_active', true)
                    ->first();
                $responseData['stripe_publishable_key'] = $stripePublishableKeyConfig ? $stripePublishableKeyConfig->value : '';
                $responseData['stripe_payment_link'] = $gatewayResult['url'] ?? '';
                $responseData['payment_link'] = $gatewayResult['url'] ?? '';
            } elseif ($paymentMethod === 'paytm') {
                $responseData['paytm_params'] = $gatewayResult['params'] ?? [];
                $responseData['paytm_form_url'] = $gatewayResult['form_url'] ?? '';
                $responseData['paytm_checksum'] = $gatewayResult['checksum'] ?? '';
                $responseData['paytm_payment_link'] = url("/api/payments/paytm/redirect/{$transaction->transaction_id}");
                $responseData['payment_link'] = url("/api/payments/paytm/redirect/{$transaction->transaction_id}");
            } elseif ($paymentMethod === 'cash') {
                $responseData['cash_payment'] = true;
                $responseData['payment_status'] = 'pending_cash';
                $responseData['payment_link'] = null;  // No payment link for cash
            } elseif ($paymentMethod === 'wallet') {
                $responseData['wallet_payment'] = true;
                $responseData['status'] = 'completed';
                $responseData['payment_link'] = null;  // No payment link for wallet
                $responseData['wallet_balance'] = $gatewayResult['data']['wallet_balance'] ?? 0;
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment transaction initialized successfully',
                'data' => $responseData
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize payment transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function verifyTransaction(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();
        $transaction = Transaction::where('transaction_id', $request->transaction_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        }

        $paymentMethod = $transaction->payment_method;

        try {
            $currentStatus = $transaction->status;

            // Check if this is a Razorpay transaction by checking:
            // 1. payment_method field is 'razorpay'
            // 2. gateway_transaction_id starts with 'plink_' (Razorpay payment link prefix)
            // 3. gateway_response contains Razorpay-specific data
            $gatewayResponse = $transaction->gateway_response ?? [];
            if (is_string($gatewayResponse)) {
                $gatewayResponse = json_decode($gatewayResponse, true) ?? [];
            }

            // Check if this is a Razorpay transaction
            // Primary check: payment_method field
            // Fallback: gateway_transaction_id starts with 'plink_' and gateway_response has Razorpay data
            $isRazorpayTransaction = $paymentMethod === 'razorpay'
                || (($transaction->gateway_transaction_id && strpos($transaction->gateway_transaction_id, 'plink_') === 0)
                    && (isset($gatewayResponse['razorpay_payment_link_id']) || isset($gatewayResponse['short_url'])));

            // Check if this is a Stripe transaction
            // Primary check: payment_method field
            // Fallback: gateway_transaction_id starts with 'pi_' (payment intent) or 'plink_' with Stripe data
            $isStripeTransaction = $paymentMethod === 'stripe'
                || ($transaction->gateway_transaction_id && strpos($transaction->gateway_transaction_id, 'pi_') === 0)
                || (($transaction->gateway_transaction_id && strpos($transaction->gateway_transaction_id, 'plink_') === 0)
                    && (isset($gatewayResponse['stripe_payment_link_id']) || (isset($gatewayResponse['url']) && !isset($gatewayResponse['short_url']))))
                || isset($gatewayResponse['payment_intent_id']);

            // If status is pending, verify with payment gateway to get latest status
            if ($currentStatus === 'pending' && $isRazorpayTransaction && $transaction->gateway_transaction_id) {


                $razorpayService = app(\App\Services\RazorpayService::class);
                $gatewayStatusResult = $razorpayService->getPaymentLinkStatus($transaction->gateway_transaction_id);

                if ($gatewayStatusResult['success'] && isset($gatewayStatusResult['status'])) {
                    $gatewayStatus = $gatewayStatusResult['status'];

                    // If gateway shows payment is paid, update transaction
                    if (strtolower($gatewayStatus) === 'paid') {


                        // Prepare gateway response with payment_id if available
                        $gatewayResponseData = $gatewayStatusResult['data'] ?? [];
                        if (isset($gatewayStatusResult['payment_id'])) {
                            $gatewayResponseData['payment_id'] = $gatewayStatusResult['payment_id'];
                        }

                        // Update transaction status
                        $internalStatus = $this->updateTransactionStatus(
                            $transaction,
                            $gatewayStatus,
                            'razorpay',
                            $gatewayResponseData
                        );
                    }
                }
            } elseif ($currentStatus === 'pending' && $isStripeTransaction && $transaction->gateway_transaction_id) {


                $stripeService = app(\App\Services\StripeService::class);

                // Check if it's a payment link (plink_) or payment intent (pi_)
                $gatewayTransactionId = $transaction->gateway_transaction_id;
                if (strpos($gatewayTransactionId, 'plink_') === 0) {
                    // It's a payment link
                    $gatewayStatusResult = $stripeService->getPaymentLinkStatus($gatewayTransactionId);
                } elseif (strpos($gatewayTransactionId, 'pi_') === 0) {
                    // It's a payment intent
                    $paymentIntentResult = $stripeService->retrievePaymentIntent($gatewayTransactionId);
                    if ($paymentIntentResult['success'] && isset($paymentIntentResult['payment_intent']['status'])) {
                        $gatewayStatusResult = [
                            'success' => true,
                            'status' => $paymentIntentResult['payment_intent']['status'],
                            'payment_intent_id' => $gatewayTransactionId,
                            'data' => $paymentIntentResult['payment_intent']
                        ];
                    } else {
                        $gatewayStatusResult = $paymentIntentResult;
                    }
                } else {
                    // Try as payment link first
                    $gatewayStatusResult = $stripeService->getPaymentLinkStatus($gatewayTransactionId);
                }

                if ($gatewayStatusResult['success'] && isset($gatewayStatusResult['status'])) {
                    $gatewayStatus = $gatewayStatusResult['status'];

                    // If gateway shows payment is succeeded/completed, update transaction
                    // Stripe statuses: 'succeeded' = completed, 'completed' (for payment links) = completed
                    if (strtolower($gatewayStatus) === 'succeeded' || strtolower($gatewayStatus) === 'completed') {


                        // Prepare gateway response with payment_intent_id if available
                        $gatewayResponseData = $gatewayStatusResult['data'] ?? [];
                        if (isset($gatewayStatusResult['payment_intent_id'])) {
                            $gatewayResponseData['payment_intent_id'] = $gatewayStatusResult['payment_intent_id'];
                        }

                        // Update transaction status
                        $internalStatus = $this->updateTransactionStatus(
                            $transaction,
                            $gatewayStatus,
                            'stripe',
                            $gatewayResponseData
                        );
                    }
                }
            }

            // Refresh transaction from database to get latest status (in case callback updated it)
            $transaction->refresh();
            $currentStatus = $transaction->status;

            // If transaction is already completed but booking payment_status is not paid, update it
            if ($currentStatus === 'completed' && $transaction->booking_id) {
                $booking = Booking::find($transaction->booking_id);
                if ($booking && $booking->payment_status !== 'paid') {


                    $booking->update([
                        'payment_status' => 'paid',
                        'payment_method' => $transaction->payment_method ?? $paymentMethod,
                        'online_paid_amount' => $transaction->amount,
                        'total_amount' => $transaction->amount,
                    ]);

                    // Process driver payout if needed
                    if ($booking->driver_id && !$booking->driver_amount) {
                        try {
                            app(PaymentGatewayService::class)->processDriverPayout($booking, $transaction->amount, $transaction->payment_method ?? $paymentMethod);
                        } catch (\Exception $e) {
                        }
                    }

                    // Settle debts if needed
                    try {
                        app(UserDebtService::class)->settleAppliedDebtsForBooking($booking->fresh());
                        // Credit debt amount to admin wallet when payment is received
                        $this->creditDebtAmountToAdminWallet($booking->fresh());
                    } catch (\Exception $e) {
                    }
                }
            }

            $booking = $transaction->booking;

            $gatewayResponse = $transaction->gateway_response ?? [];

            if (is_string($gatewayResponse)) {
                $gatewayResponse = json_decode($gatewayResponse, true) ?? [];
            }

            $finalStatus = $currentStatus;

            $responseData = [
                'transaction_id' => $transaction->transaction_id,
                'status' => $finalStatus,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'payment_method' => $paymentMethod,  // This will be 'razorpay', 'stripe', or 'paytm'
                'booking_id' => $booking ? $booking->id : null,
                'booking_code' => $booking ? $booking->booking_code : null,
                'created_at' => $transaction->created_at->toISOString(),
                'updated_at' => $transaction->updated_at->toISOString(),
            ];

            switch ($paymentMethod) {
                case 'razorpay':
                    $responseData = array_merge($responseData, [
                        'gateway_details' => [
                            'gateway_name' => 'Razorpay',
                            'gateway_order_id' => $transaction->gateway_transaction_id,
                            'gateway_payment_id' => $gatewayResponse['payment_id'] ?? null,
                            'payment_method_used' => $gatewayResponse['method'] ?? 'card',
                            'gateway_status' => $gatewayResponse['status'] ?? $finalStatus,
                        ]
                    ]);
                    break;

                case 'stripe':
                    $responseData = array_merge($responseData, [
                        'gateway_details' => [
                            'gateway_name' => 'Stripe',
                            'gateway_payment_intent_id' => $transaction->gateway_transaction_id,
                            'gateway_status' => $gatewayResponse['status'] ?? $finalStatus,
                            'payment_method_used' => $gatewayResponse['method'] ?? 'card',
                        ]
                    ]);
                    break;

                case 'paytm':
                    $responseData = array_merge($responseData, [
                        'gateway_details' => [
                            'gateway_name' => 'Paytm',
                            'gateway_order_id' => $transaction->gateway_transaction_id,
                            'gateway_transaction_id' => $gatewayResponse['transaction_id'] ?? null,
                            'gateway_status' => $gatewayResponse['response_message'] ?? $finalStatus,
                            'payment_method_used' => $gatewayResponse['payment_mode'] ?? 'UPI',
                            'bank_name' => $gatewayResponse['bank_name'] ?? null,
                        ]
                    ]);
                    break;
            }



            if ($finalStatus === 'completed' && $booking) {
                $this->broadcastPaymentSuccess($transaction);
            }

            $user_update = User::where('id', $user->id)->update([
                'current_booking_id' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Transaction status retrieved successfully',
                'data' => $responseData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check transaction status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function buildTransactionTimeline($transaction)
    {
        $timeline = [];

        if ($transaction->reference_type && !$transaction->relationLoaded('reference')) {
            $transaction->load('reference');
        }

        $paymentMethod = 'N/A';
        if (!empty($transaction->payment_method)) {
            $paymentMethod = $transaction->payment_method;
        } elseif (isset($transaction->meta_data['payment_method'])) {
            $paymentMethod = $transaction->meta_data['payment_method'];
        } elseif (isset($transaction->meta_data['method'])) {
            $paymentMethod = $transaction->meta_data['method'];
        } elseif (!empty($transaction->payment_type)) {
            $paymentMethod = $transaction->payment_type;
        } elseif ($transaction->reference_type === 'App\Models\Booking' && $transaction->reference) {
            $paymentMethod = $transaction->reference->payment_method ?? 'N/A';
        }

        $processedAt = null;
        if (isset($transaction->processed_at)) {
            $processedAt = $transaction->processed_at;
        } elseif (isset($transaction->meta_data['processed_at'])) {
            try {
                $processedAt = \Carbon\Carbon::parse($transaction->meta_data['processed_at']);
            } catch (\Exception $e) {
                $processedAt = null;
            }
        }

        $isRefund = $transaction->type === \App\Models\WalletTransaction::TYPE_WITHDRAWAL_REFUND;

        $eventText = 'Payment Initiated';
        if ($isRefund) {
            $eventText = 'Withdrawal Refund Initiated';
        } elseif ($paymentMethod !== 'N/A') {
            $eventText = 'Payment Initiated via ' . strtoupper($paymentMethod);
        } else {
            switch ($transaction->type) {
                case \App\Models\WalletTransaction::TYPE_DRIVER_COMMISSION:
                case \App\Models\WalletTransaction::TYPE_DRIVER_PAYOUT:
                    $eventText = 'Earnings Credited';
                    break;
                case \App\Models\WalletTransaction::TYPE_BOOKING_PAYMENT:
                    $eventText = 'Payment Initiated';
                    break;
                case \App\Models\WalletTransaction::TYPE_BOOKING_REFUND:
                    $eventText = 'Refund Initiated';
                    break;
                case \App\Models\WalletTransaction::TYPE_WALLET_TOPUP:
                    $eventText = 'Wallet Top-up Initiated';
                    break;
                default:
                    $eventText = 'Transaction Initiated';
                    break;
            }
        }

        $timelineEntry = [
            'event' => $eventText,
            'timestamp' => $transaction->created_at->format('g:i A'),
            'icon' => 'paypal_like_icon'
        ];

        if ($isRefund) {
            $timelineEntry['is_refund'] = 1;
        }

        $timeline[] = $timelineEntry;

        $status = $transaction->status ?? 'completed';

        if ($status === 'completed') {
            $processedTime = $processedAt ? $processedAt->format('g:i A') : $transaction->created_at->copy()->addMinutes(1)->format('g:i A');

            $timeline[] = [
                'event' => 'Amount Processed',
                'timestamp' => $processedTime,
                'icon' => 'wallet_icon'
            ];

            $isWithdrawalToBank = $transaction->type === \App\Models\WalletTransaction::TYPE_WALLET_WITHDRAWAL;

            $timeline[] = [
                'event' => $isWithdrawalToBank ? 'Credited to Bank Account' : 'Wallet Processed',
                'timestamp' => $processedTime,
                'icon' => 'wallet_check_icon'
            ];
        } else if ($status === 'pending') {
            $timeline[] = [
                'event' => 'Awaiting Admin Confirmation',
                'status_text' => 'Processing...',
                'icon' => 'shopping_bag_face_icon'
            ];
        } else if ($status === 'failed') {
            $timeline[] = [
                'event' => 'Transaction Rejected by Admin',
                'status_text' => 'Please retry or contact admin',
                'icon' => 'error_icon'
            ];
        }

        return $timeline;
    }

    private function getDailyEarnings($driver, $dateRange)
    {
        try {
            $dates = explode(' - ', $dateRange);
            if (count($dates) == 2) {
                $startDate = Carbon::parse($dates[0]);
                $endDate = Carbon::parse($dates[1]);
            } else {
                $startDate = Carbon::now()->startOfWeek();
                $endDate = Carbon::now()->endOfWeek();
            }
        } catch (\Exception $e) {
            $startDate = Carbon::now()->startOfWeek();
            $endDate = Carbon::now()->endOfWeek();
        }

        $wallet = DB::table('wallets')->where('user_id', $driver->id)->first();

        $dailyData = [];
        $currentDate = $startDate->copy();

        while ($currentDate <= $endDate) {
            $dayStart = $currentDate->copy()->startOfDay();
            $dayEnd = $currentDate->copy()->endOfDay();

            if ($wallet) {
                $wallet_earning = WalletTransaction::where('wallet_id', $wallet->id)
                    ->where('type', 'credit')
                    ->where(function ($q) {
                        $q
                            ->where('payment_type', '!=', 'cash')
                            ->orWhereNull('payment_type');
                    })
                    ->whereBetween('created_at', [$dayStart, $dayEnd])
                    ->sum('amount');

                $cash_earning = DB::table('bookings')
                    ->where('driver_id', $driver->id)
                    ->where('payment_method', 'cash')
                    ->where('status', 'completed')
                    ->whereNotNull('completed_at')
                    ->whereBetween('completed_at', [$dayStart, $dayEnd])
                    ->sum(DB::raw('COALESCE(driver_amount, COALESCE(total_amount, 0) - COALESCE(admin_commission, 0))'));

                $cash_earning = $cash_earning ?? 0;

                $deductions = WalletTransaction::where('wallet_id', $wallet->id)
                    ->whereBetween('created_at', [$dayStart, $dayEnd])
                    ->where('type', 'adjustment')
                    ->sum('amount');

                $deduction = abs($deductions);

                $incentive_reward = WalletTransaction::where('wallet_id', $wallet->id)
                    ->where('type', 'incentive_reward')
                    ->where('status', 'completed')
                    ->whereBetween('created_at', [$dayStart, $dayEnd])
                    ->sum('amount');

                $dayEarning = $wallet_earning + $cash_earning - $deduction + $incentive_reward;
            } else {
                $dayEarning = 0;
            }

            $dailyData[] = [
                'date' => (string) $currentDate->day,
                'day' => $currentDate->shortDayName,
                'earning' => (float) ($dayEarning ?? 0),
                'highlighted' => false
            ];

            $currentDate->addDay();
        }

        return $dailyData;
    }

    private function generateRealDailyEarningsForRange($driver, Carbon $from, Carbon $to): array
    {
        $days = [];
        $cursor = $from->copy();

        $wallet = DB::table('wallets')->where('user_id', $driver->id)->first();

        if (!$wallet) {

            while ($cursor <= $to) {
                $days[] = [
                    'date' => (string) $cursor->day,
                    'day' => $cursor->shortDayName,
                    'earning' => 0.0,
                    'highlighted' => false,
                ];
                $cursor->addDay();
            }
            return $days;
        }

        while ($cursor <= $to) {
            $dayStart = $cursor->copy()->startOfDay();
            $dayEnd = $cursor->copy()->endOfDay();

            $wallet_earning = WalletTransaction::where('wallet_id', $wallet->id)
                ->where('type', 'credit')
                ->where(function ($q) {
                    $q
                        ->where('payment_type', '!=', 'cash')
                        ->orWhereNull('payment_type');
                })
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->sum('amount');

            $cash_earning = DB::table('bookings')
                ->where('driver_id', $driver->id)
                ->where('payment_method', 'cash')
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->whereBetween('completed_at', [$dayStart, $dayEnd])
                ->sum(DB::raw('COALESCE(driver_amount, COALESCE(total_amount, 0) - COALESCE(admin_commission, 0))'));

            $cash_earning = $cash_earning ?? 0;

            $deductions = WalletTransaction::where('wallet_id', $wallet->id)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->where('type', 'adjustment')
                ->sum('amount');

            $deduction = abs($deductions);

            $incentive_reward = WalletTransaction::where('wallet_id', $wallet->id)
                ->where('type', 'incentive_reward')
                ->where('status', 'completed')
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->sum('amount');

            $totalEarning = $wallet_earning + $cash_earning - $deduction + $incentive_reward;

            $days[] = [
                'date' => (string) $cursor->day,
                'day' => $cursor->shortDayName,
                'earning' => (float) $totalEarning,
                'highlighted' => false,
            ];

            $cursor->addDay();
        }



        return $days;
    }

    private function getSelectedDayEarnings($driver, $selectedDay, $from, $to, $dailyEarnings = [], $dateRange = null)
    {
        $selectedDay = (int) $selectedDay;
        $selectedDate = null;

        // First, try to find the selected day - prefer the LAST occurrence if multiple exist
        $cursor = $from->copy();
        $lastMatch = null;
        while ($cursor <= $to) {
            if ($cursor->day == $selectedDay) {
                $lastMatch = $cursor->copy();
            }
            $cursor->addDay();
        }

        if ($lastMatch) {
            $selectedDate = $lastMatch;
        }

        // If no match found, try to find a day with earnings that matches the selected day number
        if (!$selectedDate && !empty($dailyEarnings)) {
            $dayWithEarnings = collect($dailyEarnings)
                ->filter(function ($day) use ($selectedDay) {
                    return $day['earning'] > 0 && (int) $day['date'] == $selectedDay;
                })
                ->last();

            if ($dayWithEarnings) {
                $cursor = $from->copy();
                $lastMatch = null;
                while ($cursor <= $to) {
                    if ($cursor->day == (int) $dayWithEarnings['date']) {
                        $lastMatch = $cursor->copy();
                    }
                    $cursor->addDay();
                }
                if ($lastMatch) {
                    $selectedDate = $lastMatch;
                }
            }
        }

        // If still no match, find any day with earnings
        if (!$selectedDate && !empty($dailyEarnings)) {
            $dayWithEarnings = collect($dailyEarnings)
                ->filter(function ($day) {
                    return $day['earning'] > 0;
                })
                ->last();

            if ($dayWithEarnings) {
                $cursor = $from->copy();
                $lastMatch = null;
                while ($cursor <= $to) {
                    if ($cursor->day == (int) $dayWithEarnings['date']) {
                        $lastMatch = $cursor->copy();
                    }
                    $cursor->addDay();
                }
                if ($lastMatch) {
                    $selectedDate = $lastMatch;
                }
            }
        }

        // Default to first day of range if still no match
        if (!$selectedDate) {
            $selectedDate = $from->copy();
        }

        // Check if the selected date has any earnings by matching it with dailyEarnings array
        // Since dailyEarnings array is ordered chronologically, we can match by position
        if (!empty($dailyEarnings)) {
            $cursor = $from->copy();
            $index = 0;
            $selectedDayEarning = null;

            // Find the earning entry that matches the selected date by iterating through dates
            while ($cursor <= $to) {
                if ($cursor->format('Y-m-d') == $selectedDate->format('Y-m-d')) {
                    $selectedDayEarning = $dailyEarnings[$index] ?? null;
                    break;
                }
                $cursor->addDay();
                $index++;
            }

            // If selected day has 0 or no earnings, find the day with the most earnings
            if (!$selectedDayEarning || (float) ($selectedDayEarning['earning'] ?? 0) <= 0) {
                $dayWithMostEarnings = collect($dailyEarnings)
                    ->sortByDesc('earning')
                    ->first();

                if ($dayWithMostEarnings && (float) $dayWithMostEarnings['earning'] > 0) {
                    // Find the LAST occurrence of this day number in the range
                    $cursor = $from->copy();
                    $lastMatch = null;
                    while ($cursor <= $to) {
                        if ($cursor->day == (int) $dayWithMostEarnings['date']) {
                            $lastMatch = $cursor->copy();
                        }
                        $cursor->addDay();
                    }
                    if ($lastMatch) {
                        $selectedDate = $lastMatch;
                    }
                }
            }
        }

        $wallet = DB::table('wallets')->where('user_id', $driver->id)->first();

        if (!$wallet) {
            return [
                'selected_day_total_earning' => 0,
                'wallet_earning' => 0,
                'cash_earning' => 0,
                'incentive_reward' => 0,
                'deduction' => 0,
                'total_earning_for_day' => 0,
            ];
        }

        $dayStart = $selectedDate->copy()->startOfDay();
        $dayEnd = $selectedDate->copy()->endOfDay();

        $rangeStart = $from->copy()->startOfDay();
        $rangeEnd = $to->copy()->endOfDay();

        $bookingEarnings = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', 'driver_payout')
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->sum('amount');

        $walletEarnings = WalletTransaction::where('wallet_id', $wallet->id)
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->where('type', 'credit')
            ->sum('amount');

        $deductions = WalletTransaction::where('wallet_id', $wallet->id)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->where('type', 'adjustment')
            ->sum('amount');
        //     ->sum('amount');

        $cash_earning = DB::table('bookings')
            ->where('driver_id', $driver->id)
            ->where('payment_method', 'cash')
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$rangeStart, $rangeEnd])
            ->sum(DB::raw('COALESCE(driver_amount, COALESCE(total_amount, 0) - COALESCE(admin_commission, 0))'));

        $cash_earning = $cash_earning ?? 0;
        //     'sampleRow' => $sample ? $sample->created_at : null
        // ]);

        $card_earning = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', 'driver_payout')
            ->where(function ($q) {
                $q
                    ->where('payment_type', '!=', 'cash')
                    ->orWhereNull('payment_type');
            })
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->sum('amount');

        $wallet_earning = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', 'credit')
            ->where(function ($q) {
                $q
                    ->where('payment_type', '!=', 'cash')
                    ->orWhereNull('payment_type');
            })
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->sum('amount');

        $incentive_reward = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', 'incentive_reward')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->sum('amount');

        $deduction = abs($deductions);

        $total = $wallet_earning + $cash_earning - $deduction + $incentive_reward;

        return [
            'selected_day_total_earning' => (float) $total,
            'wallet_earning' => (float) ($wallet_earning ?? 0),
            'cash_earning' => number_format($cash_earning ?? 0, 2, '.', ''),
            'incentive_reward' => (float) ($incentive_reward ?? 0),
            'deduction' => (float) ($deduction ?? 0),
            'total_earning_for_day' => (float) $total,
        ];
    }

    private function getRideSummary($driver)
    {
        $timeOnline = 0;
        try {
            if (DB::getSchemaBuilder()->hasTable('driver_attendances')) {
                $timeOnline = DB::table('driver_attendances')
                    ->where('driver_id', $driver->id)
                    ->where('status', 'online')
                    ->sum('duration_minutes');
            }
        } catch (\Exception $e) {
        }

        if ($timeOnline == 0) {
            $firstBooking = Booking::where('driver_id', $driver->id)
                ->whereNotNull('started_at')
                ->orderBy('started_at', 'asc')
                ->first();

            $lastBooking = Booking::where('driver_id', $driver->id)
                ->whereNotNull('completed_at')
                ->orderBy('completed_at', 'desc')
                ->first();

            if ($firstBooking && $lastBooking) {
                $start = Carbon::parse($firstBooking->started_at);
                $end = Carbon::parse($lastBooking->completed_at);
                $timeOnline = $start->diffInMinutes($end);
            }
        }

        $hours = floor($timeOnline / 60);
        $minutes = $timeOnline % 60;
        $timeOnlineFormatted = sprintf('%d:%02d', $hours, $minutes);

        $totalRides = Booking::where('driver_id', $driver->id)
            ->whereIn('status', ['completed', 'cancelled'])
            ->count();

        $completedRides = Booking::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->count();

        $completionRate = $totalRides > 0 ? round(($completedRides / $totalRides) * 100) : 0;

        $averageRating = Booking::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->whereNotNull('driver_rating')
            ->avg('driver_rating');

        return [
            'time_online_hrs' => $timeOnlineFormatted,
            'total_rides' => (int) $totalRides,
            'completed_rides' => (int) $completedRides,
            'completion_rate_percent' => (int) $completionRate,
            'average_rating' => round((float) ($averageRating ?? 0), 1)
        ];
    }

    private function getEarningsData($driver, $paymentSource, $earningType, $amount, $amountMin = null, $amountMax = null)
    {
        $startDate = Carbon::now()->subDays(30);
        $endDate = Carbon::now();

        $bookingsQuery = Booking::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$startDate, $endDate]);

        if ($paymentSource !== 'all') {
            $bookingsQuery->where('payment_method', $paymentSource);

            // For cash payments, only show bookings where payment_status is 'paid'
            // (cash payments can be pending until driver collects the cash)
            if ($paymentSource === 'cash') {
                $bookingsQuery->where('payment_status', 'paid');
            }
        }

        $bookings = $bookingsQuery->orderBy('completed_at', 'desc')->get();
        $walletTransactions = collect([]);

        if ($driver->wallet && $driver->wallet->id) {
            $walletTransactionsQuery = WalletTransaction::where('wallet_id', $driver->wallet->id)
                ->whereBetween('created_at', [$startDate, $endDate]);

            // Filter wallet transactions by payment source
            if ($paymentSource !== 'all') {
                if ($paymentSource === 'wallet') {
                    // For wallet payment source, include:
                    // 1. Transactions with payment_type='wallet' (wallet payments/earnings)
                    // 2. Transactions with "Earnings from ride" description (wallet earnings from rides)
                    // 3. Transactions related to bookings with payment_method='wallet'
                    // 4. Standalone wallet transactions (not related to bookings, like top-ups)
                    $walletTransactionsQuery->where(function ($query) {
                        $query->where('payment_type', 'wallet')
                            ->orWhere('description', 'like', '%Earnings from ride%')
                            ->orWhere(function ($q) {
                                // Use whereExists with direct query to bookings table to avoid polymorphic relationship issues
                                $q->where('reference_type', 'App\Models\Booking')
                                    ->whereExists(function ($subQuery) {
                                        $subQuery->select(DB::raw(1))
                                            ->from('bookings')
                                            ->whereColumn('bookings.id', 'wallet_transactions.reference_id')
                                            ->where('bookings.payment_method', 'wallet');
                                    });
                            })
                            ->orWhere(function ($q) {
                                // Standalone wallet transactions (not related to bookings)
                                $q->where(function ($subQ) {
                                    $subQ->whereNull('reference_type')
                                        ->orWhere('reference_type', '!=', 'App\Models\Booking');
                                })
                                    ->where(function ($subQ) {
                                        // Only include if it's a credit or wallet-related standalone transaction
                                        $subQ->where('type', 'credit')
                                            ->orWhere('payment_type', 'wallet');
                                    });
                            });
                    });
                } elseif ($paymentSource === 'cash') {
                    // For cash, include wallet transactions related to cash bookings
                    // (like commission deductions, penalties from cash bookings)
                    $walletTransactionsQuery->where(function ($q) {
                        // Use whereExists with direct query to bookings table to avoid polymorphic relationship issues
                        $q->where(function ($query) {
                            $query->where('reference_type', 'App\Models\Booking')
                                ->whereExists(function ($subQuery) {
                                    $subQuery->select(DB::raw(1))
                                        ->from('bookings')
                                        ->whereColumn('bookings.id', 'wallet_transactions.reference_id')
                                        ->where('bookings.payment_method', 'cash')
                                        ->where('bookings.payment_status', 'paid');
                                });
                        })->orWhere(function ($query) {
                            // Include deductions/commissions that might be related to cash bookings
                            $query->where('type', 'debit')
                                ->where(function ($subQ) {
                                    $subQ->where('description', 'like', '%commission%')
                                        ->orWhere('description', 'like', '%deduct%');
                                });
                        });
                    });
                } elseif ($paymentSource === 'incentive') {
                    // For incentive, include incentive rewards and bonuses
                    $walletTransactionsQuery->where(function ($q) {
                        $q->where('type', 'incentive_reward')
                            ->orWhere(function ($subQ) {
                                $subQ->where('type', 'credit')
                                    ->where(function ($descQ) {
                                        $descQ->where('description', 'like', '%bonus%')
                                            ->orWhere('description', 'like', '%incentive%')
                                            ->orWhere('description', 'like', '%reward%')
                                            ->orWhere('description', 'like', '%referral%');
                                    });
                            });
                    });
                } elseif ($paymentSource === 'deduction') {
                    // For deduction, include debits, penalties, and commissions
                    $walletTransactionsQuery->where(function ($q) {
                        $q->where('type', 'debit')
                            ->orWhere(function ($subQ) {
                                $subQ->where('description', 'like', '%penalty%')
                                    ->orWhere('description', 'like', '%deduct%')
                                    ->orWhere('description', 'like', '%commission%')
                                    ->orWhere('description', 'like', '%cancellation%');
                            });
                    });
                }
            }

            $walletTransactions = $walletTransactionsQuery->orderBy('created_at', 'desc')->get();
        }

        $earningsGrouped = [];

        $processedBookingCodes = [];

        foreach ($bookings as $booking) {
            $bookingDate = Carbon::parse($booking->completed_at);
            $date = $bookingDate->format('D, d M');
            $dateKey = $bookingDate->format('Y-m-d');  // Use Y-m-d as key for accurate date matching

            if (!isset($earningsGrouped[$dateKey])) {
                $earningsGrouped[$dateKey] = [
                    'date' => $date,
                    'date_carbon' => $bookingDate->copy()->startOfDay(),  // Store actual date for recalculation
                    'daily_total_earnings' => 0,
                    'transactions' => []
                ];
            }

            if ($booking->booking_code) {
                $processedBookingCodes[] = $booking->booking_code;
            }

            $hasTransaction = $booking->transactions()->exists();
            $transactionId = $hasTransaction ? ($booking->transactions()->first()->transaction_id ?? '') : '';

            // Use driver_amount from bookings table instead of calculating
            $finalEarning = (float) ($booking->driver_amount ?? 0);

            $transactionData = [
                'id' => $booking->id,
                'type' => 'Ride',
                'description' => $booking->distance ? number_format($booking->distance, 1) . ' KM' : '',
                'time' => Carbon::parse($booking->completed_at)->format('g:i A'),
                'amount' => round($finalEarning, 2),
                'currency' => '',
                'is_positive' => true,
                'booking_code' => $booking->booking_code,
                'transaction_type' => 'ride',
            ];

            if (!empty($transactionId)) {
                $transactionData['transaction_id'] = $transactionId;
                $transactionData['is_approve'] = 1;
            }

            $earningsGrouped[$dateKey]['transactions'][] = $transactionData;
        }

        foreach ($walletTransactions as $transaction) {
            if (stripos($transaction->description ?? '', 'Earnings from ride') !== false) {
                $bookingCode = $transaction->reference_type === 'App\Models\Booking'
                    ? optional(Booking::find($transaction->reference_id))->booking_code
                    : null;

                if ($bookingCode && in_array($bookingCode, $processedBookingCodes)) {
                    continue;
                }
            }

            $transactionDate = Carbon::parse($transaction->created_at);
            $date = $transactionDate->format('D, d M');
            $dateKey = $transactionDate->format('Y-m-d');  // Use Y-m-d as key for accurate date matching

            if (!isset($earningsGrouped[$dateKey])) {
                $earningsGrouped[$dateKey] = [
                    'date' => $date,
                    'date_carbon' => $transactionDate->copy()->startOfDay(),  // Store actual date for recalculation
                    'daily_total_earnings' => 0,
                    'transactions' => []
                ];
            }

            $type = 'Other';
            $description = $transaction->description;
            $transactionType = 'other';
            $transactionId = null;
            $isApprovedPenalty = null;

            if (stripos($description, 'referral') !== false) {
                $type = 'Referral Bonus';
                $description = null;
                $transactionType = 'referral_bonus';
            } elseif (stripos($description, 'bonus') !== false && stripos($description, 'Earnings from ride') === false) {
                $type = 'Peak Hour Bonus';
                $description = null;
                $transactionType = 'peak_hour_bonus';
            } elseif (stripos($description, 'Cancellation fee for booking') !== false) {
                $type = 'Penalty';
                $description = $description; // Keep original description
                $transactionType = 'cancellation_fee';
                $transactionId = !empty($transaction->transection_id)
                    ? $transaction->transection_id
                    : 'TXN-' . str_pad($transaction->id, 6, '0', STR_PAD_LEFT);
                $isApprovedPenalty = isset($transaction->meta_data['late_penalty_refund_approved'])
                    ? (bool) $transaction->meta_data['late_penalty_refund_approved']
                    : false;
            } elseif (stripos($description, 'penalty') !== false || stripos($description, 'late arrival') !== false) {
                $type = 'Penalty';
                $description = 'for Late Arrival';
                $transactionType = 'late_arrival_penalty';
                $transactionId = !empty($transaction->transection_id)
                    ? $transaction->transection_id
                    : 'TXN-' . str_pad($transaction->id, 6, '0', STR_PAD_LEFT);
                $isApprovedPenalty = isset($transaction->meta_data['late_penalty_refund_approved'])
                    ? (bool) $transaction->meta_data['late_penalty_refund_approved']
                    : false;
            } elseif (stripos($description, 'Milestone reward') !== false) {
                $type = 'Milestone Reward';
                $transactionType = 'milestone_reward';
            } elseif (stripos($description, 'Commission deducted') !== false) {
                $type = 'Commission';
                $transactionType = 'commission';
            }

            $isPositive = $transaction->amount > 0;

            $transactionData = [
                'id' => $transaction->id,
                'type' => $type,
                'description' => $description,
                'time' => Carbon::parse($transaction->created_at)->format('g:i A'),
                'amount' => (float) $transaction->amount,
                'currency' => '₹',
                'is_positive' => $isPositive,
                'transaction_type' => $transactionType,
                'booking_code' => $transaction->reference_type === 'App\Models\Booking'
                    ? optional(Booking::find($transaction->reference_id))->booking_code
                    : null,
            ];

            if (!empty($transactionId)) {
                $transactionData['transaction_id'] = $transactionId;

                $supportTicket = SupportTicket::where('transection_id', $transactionId)->first();

                if ($supportTicket && in_array($supportTicket->status, [SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_CLOSED])) {
                    $transactionData['is_approve'] = 1;
                } else {
                    $transactionData['is_approve'] = 0;
                }
            }

            $earningsGrouped[$dateKey]['transactions'][] = $transactionData;
        }

        if ($driver->wallet && $driver->wallet->id) {
            foreach ($earningsGrouped as $dateKey => &$dayData) {
                if (isset($dayData['date_carbon'])) {
                    $dayStart = $dayData['date_carbon']->copy()->startOfDay();
                    $dayEnd = $dayData['date_carbon']->copy()->endOfDay();

                    $bookingEarnings = WalletTransaction::where('wallet_id', $driver->wallet->id)
                        ->where('type', 'driver_payout')
                        ->whereBetween('created_at', [$dayStart, $dayEnd])
                        ->sum('amount');

                    $walletEarnings = WalletTransaction::where('wallet_id', $driver->wallet->id)
                        ->whereBetween('created_at', [$dayStart, $dayEnd])
                        ->where('type', 'credit')
                        ->sum('amount');

                    $deductions = WalletTransaction::where('wallet_id', $driver->wallet->id)
                        ->whereBetween('created_at', [$dayStart, $dayEnd])
                        ->where('type', 'debit')
                        ->sum('amount');

                    $incentiveRewards = WalletTransaction::where('wallet_id', $driver->wallet->id)
                        ->where('type', 'incentive_reward')
                        ->where('status', 'completed')
                        ->whereBetween('created_at', [$dayStart, $dayEnd])
                        ->sum('amount');

                    $dayData['daily_total_earnings'] = $bookingEarnings + $walletEarnings - abs($deductions) + $incentiveRewards;
                } else {
                }
            }
        }

        $earningsData = array_values($earningsGrouped);

        // For cash payments, only show positive amounts (exclude deductions/commissions)
        if ($paymentSource === 'cash') {
            foreach ($earningsData as &$dayData) {
                $dayData['transactions'] = array_values(array_filter($dayData['transactions'], function ($tx) {
                    return $tx['amount'] > 0;
                }));
                // Recalculate daily_total_earnings based on filtered positive transactions only
                $dayData['daily_total_earnings'] = array_sum(array_column($dayData['transactions'], 'amount'));
            }
            unset($dayData);
        }

        if ($earningType !== 'all') {
            foreach ($earningsData as &$dayData) {
                $filteredTransactions = array_filter($dayData['transactions'], function ($tx) use ($earningType) {
                    switch ($earningType) {
                        case 'ride':
                        case 'rider_trip':
                            return $tx['type'] === 'Ride' || $tx['transaction_type'] === 'ride';
                        case 'refral_bonus':
                        case 'referral':
                            return stripos($tx['type'], 'Referral') !== false || $tx['transaction_type'] === 'referral_bonus';
                        case 'pick_hour':
                        case 'bonus':
                            return stripos($tx['type'], 'Bonus') !== false || $tx['transaction_type'] === 'peak_hour_bonus';
                        case 'cancelation':
                            return $tx['transaction_type'] === 'cancellation_fee' ||
                                (isset($tx['description']) && stripos($tx['description'], 'Cancellation fee for booking') !== false);
                        case 'penalty':
                            return $tx['type'] === 'Penalty' ||
                                $tx['transaction_type'] === 'late_arrival_penalty';
                        case 'service':
                            return $tx['transaction_type'] === 'commission' ||
                                stripos($tx['type'], 'Commission') !== false ||
                                stripos($tx['description'] ?? '', 'service') !== false;
                        default:
                            return true;
                    }
                });

                $dayData['transactions'] = array_values($filteredTransactions);

                if ($driver->wallet && $driver->wallet->id && isset($dayData['date_carbon'])) {
                    $dayStart = $dayData['date_carbon']->copy()->startOfDay();
                    $dayEnd = $dayData['date_carbon']->copy()->endOfDay();

                    $bookingEarnings = WalletTransaction::where('wallet_id', $driver->wallet->id)
                        ->where('type', 'driver_payout')
                        ->whereBetween('created_at', [$dayStart, $dayEnd])
                        ->sum('amount');

                    $walletEarnings = WalletTransaction::where('wallet_id', $driver->wallet->id)
                        ->whereBetween('created_at', [$dayStart, $dayEnd])
                        ->where('type', 'credit')
                        ->sum('amount');

                    $deductions = WalletTransaction::where('wallet_id', $driver->wallet->id)
                        ->whereBetween('created_at', [$dayStart, $dayEnd])
                        ->where('type', 'debit')
                        ->sum('amount');

                    $incentiveRewards = WalletTransaction::where('wallet_id', $driver->wallet->id)
                        ->where('type', 'incentive_reward')
                        ->where('status', 'completed')
                        ->whereBetween('created_at', [$dayStart, $dayEnd])
                        ->sum('amount');

                    $dayData['daily_total_earnings'] = $bookingEarnings + $walletEarnings - abs($deductions) + $incentiveRewards;
                } else {
                    $dayData['daily_total_earnings'] = array_sum(array_column($filteredTransactions, 'amount'));
                }
            }
        }

        if ($amount !== 'all' || $amountMin !== null || $amountMax !== null) {
            foreach ($earningsData as &$dayData) {
                $filteredTransactions = array_filter($dayData['transactions'], function ($tx) use ($amount, $amountMin, $amountMax) {
                    $txAmount = $tx['amount'];

                    // Handle amount_min and amount_max parameters
                    if ($amountMin !== null && $amountMin !== '') {
                        if ($txAmount < (float)$amountMin) {
                            return false;
                        }
                    }

                    if ($amountMax !== null && $amountMax !== '') {
                        if ($txAmount > (float)$amountMax) {
                            return false;
                        }
                    }

                    // Handle amount range filters
                    if ($amount !== 'all') {
                        switch ($amount) {
                            case '0-25':
                                return $txAmount >= 0 && $txAmount < 25;
                            case '25-50':
                                return $txAmount >= 25 && $txAmount < 50;
                            case '50-100':
                                return $txAmount >= 50 && $txAmount < 100;
                            case '100-':
                                return $txAmount >= 100;
                            case 'high':
                                return $txAmount >= 20;
                            case 'low':
                                return $txAmount < 10;
                            default:
                                return true;
                        }
                    }

                    return true;
                });

                $dayData['transactions'] = array_values($filteredTransactions);

                if ($driver->wallet && $driver->wallet->id && isset($dayData['date_carbon'])) {
                    $dayStart = $dayData['date_carbon']->copy()->startOfDay();
                    $dayEnd = $dayData['date_carbon']->copy()->endOfDay();

                    $bookingEarnings = WalletTransaction::where('wallet_id', $driver->wallet->id)
                        ->where('type', 'driver_payout')
                        ->whereBetween('created_at', [$dayStart, $dayEnd])
                        ->sum('amount');

                    $walletEarnings = WalletTransaction::where('wallet_id', $driver->wallet->id)
                        ->whereBetween('created_at', [$dayStart, $dayEnd])
                        ->where('type', 'credit')
                        ->sum('amount');

                    $deductions = WalletTransaction::where('wallet_id', $driver->wallet->id)
                        ->whereBetween('created_at', [$dayStart, $dayEnd])
                        ->where('type', 'debit')
                        ->sum('amount');

                    $incentiveRewards = WalletTransaction::where('wallet_id', $driver->wallet->id)
                        ->where('type', 'incentive_reward')
                        ->where('status', 'completed')
                        ->whereBetween('created_at', [$dayStart, $dayEnd])
                        ->sum('amount');

                    $dayData['daily_total_earnings'] = $bookingEarnings + $walletEarnings - abs($deductions) + $incentiveRewards;
                } else {
                    $dayData['daily_total_earnings'] = array_sum(array_column($filteredTransactions, 'amount'));
                }
            }
        }

        // Recalculate daily_total_earnings based on filtered transactions
        // (after all filters are applied - this ensures accuracy for all payment sources)
        foreach ($earningsData as &$dayData) {
            // Recalculate daily_total_earnings based on filtered transactions only
            $dayData['daily_total_earnings'] = array_sum(array_column($dayData['transactions'], 'amount'));
        }
        unset($dayData);

        $earningsData = array_filter($earningsData, function ($dayData) {
            return count($dayData['transactions']) > 0;
        });

        $earningsData = array_map(function ($dayData) {
            unset($dayData['date_carbon']);
            return $dayData;
        }, array_values($earningsData));

        return $earningsData;
    }

    private function authenticateUserFromRequest(Request $request)
    {
        $token = $request->bearerToken();

        if (!$token) {
            $token = $request->query('token');
        }

        if (!$token) {
            return null;
        }

        // Trim whitespace and normalize token
        $token = trim($token);

        // Store original token for matching
        $originalToken = $token;

        // Remove Bearer_ prefix for normalization
        $normalizedToken = str_replace('Bearer_', '', $token);
        $normalizedToken = trim($normalizedToken);

        if (empty($normalizedToken)) {
            return null;
        }

        // Search for user with token in both formats (with and without Bearer_ prefix)
        $user = \App\Models\User::without('driverProfile')
            ->select(['id', 'role_id', 'status', 'bearer_token', 'token_expires_at', 'is_online', 'is_verified'])
            ->where(function ($query) use ($normalizedToken, $originalToken) {
                $query
                    ->where('bearer_token', $normalizedToken)
                    ->orWhere('bearer_token', 'Bearer_' . $normalizedToken)
                    ->orWhere('bearer_token', $originalToken)
                    ->orWhere('bearer_token', 'Bearer_' . str_replace('Bearer_', '', $originalToken));
            })
            ->where(function ($query) {
                $query->whereNull('token_expires_at')
                    ->orWhere('token_expires_at', '>', now());
            })
            ->first();

        if (!$user) {
            return null;
        }

        if ($user->isBlocked()) {
            return null;
        }

        Auth::setUser($user);

        return $user;
    }

    private function buildEarningDetails($booking)
    {
        $baseFare = (float) ($booking->base_fare ?? 0);
        $distanceFare = (float) ($booking->distance_fare ?? 0);
        $timeFare = (float) ($booking->time_fare ?? 0);
        $waitingCharge = (float) ($booking->waiting_charge ?? 0);
        $nightCharge = (float) ($booking->night_charge ?? 0);
        $bookingFee = (float) ($booking->booking_fee ?? 0);
        $surgeAmount = (float) ($booking->surge_amount ?? 0);
        $tipReceived = (float) ($booking->tip_amount ?? 0);
        $taxAmount = (float) ($booking->tax_amount ?? 0);

        $totalFare = $baseFare + $distanceFare + $timeFare + $waitingCharge + $nightCharge + $bookingFee + $surgeAmount + $tipReceived + $taxAmount;

        $platformFee = (float) ($booking->admin_commission ?? 0);
        $tax = (float) ($booking->tax_amount ?? 0);
        $penalty = 0;  // Will be calculated from wallet transactions

        $penaltyTransaction = WalletTransaction::where('driver_id', $booking->driver_id)
            ->where('reference_type', 'App\Models\Booking')
            ->where('reference_id', $booking->id)
            ->where('type', 'debit')
            ->where('description', 'LIKE', '%penalty%')
            ->first();

        if ($penaltyTransaction) {
            $penalty = abs((float) $penaltyTransaction->amount);
        }

        // Get promo code discount amount
        $discountAmount = (float) ($booking->discount_amount ?? 0);
        $promoCodeApplied = $booking->promo_code ?? '';

        // Include promo code discount in total deduction
        $totalDeduction = $platformFee + $tax + $penalty + $discountAmount;

        // Use driver_amount from bookings table instead of calculating
        $finalEarning = (float) ($booking->driver_amount ?? 0);

        return [
            'booking_code' => $booking->booking_code,
            'booking_id' => $booking->id,
            'distance' => $booking->distance ? number_format($booking->distance, 1) . ' KM' : '0 KM',
            'duration' => $booking->duration ? $booking->duration . ' Min' : '0 Min',
            'total_earning' => round($finalEarning, 2),
            'payment_type' => ucfirst($booking->payment_method ?? 'cash') . ' Payment',
            'earning_breakdown' => [
                'base_fare' => round($baseFare, 2),
                'distance_fare' => round($distanceFare, 2),
                'time_fare' => round($timeFare, 2),
                'waiting_charge' => round($waitingCharge, 2),
                'night_charge' => round($nightCharge, 2),
                'booking_fee' => round($bookingFee, 2),
                'surge_amount' => round($surgeAmount, 2),
                'tip_received' => round($tipReceived, 2),
                'total_fare' => round($totalFare, 2),
                'tax_amount' => round($taxAmount, 2),
            ],
            'deduction_breakdown' => [
                // 'platform_fee' => (float) round($platformFee, 2),
                // 'tax' => (float) round($tax, 2),
                // 'late_arrival_penalty' => (float) round($penalty, 2),
                // 'promocode_discount' => $promoCodeApplied ?: '',
                // 'promocode_applied' => (string) round($discountAmount, 2),
                // 'total_deduction' => (float) round($totalDeduction, 2)

                'platform_fee' => round($platformFee, 2),
                'tax' => round($tax, 2),
                'late_arrival_penalty' => round($penalty, 2),
                'promocode_applied' => (string) round($discountAmount, 2),
                'total_deduction' => round($totalDeduction, 2)
            ],
            'final_earning' => round($finalEarning, 2),
            'action_button_text' => 'Download Receipt'
        ];
    }

    public function paytmRedirect($transactionId)
    {
        try {
            $transaction = Transaction::where('transaction_id', $transactionId)->firstOrFail();

            $gatewayResponse = $transaction->gateway_response;

            if (is_string($gatewayResponse)) {
                $gatewayResponse = json_decode($gatewayResponse, true);
            }

            if (!$gatewayResponse || !isset($gatewayResponse['params'])) {

                abort(404, 'Transaction parameters not found');
            }

            $params = $gatewayResponse['params'];
            $formUrl = $gatewayResponse['form_url'] ?? 'https://securegw-stage.paytm.in/theia/processTransaction';

            unset($params['MERCHANT_KEY']);


            return view('paytm-redirect', [
                'params' => $params,
                'formUrl' => $formUrl
            ]);
        } catch (\Exception $e) {
            abort(404, 'Transaction not found');
        }
    }

    public function paytmCallback(Request $request)
    {
        try {
            $paytmService = app(\App\Services\PaytmService::class);
            $verificationResult = $paytmService->verifyTransaction($request->all());

            $transaction = Transaction::where('transaction_id', $verificationResult['order_id'])->first();

            if (!$transaction && isset($verificationResult['transaction_id'])) {
                $transaction = Transaction::where('gateway_transaction_id', $verificationResult['transaction_id'])
                    ->where('status', 'pending')  // Only find pending transactions
                    ->orderBy('created_at', 'desc')  // Get the most recent one
                    ->first();
            }

            if (!$transaction) {

                return view('payment-failed', [
                    'message' => 'Transaction not found',
                    'error' => 'Invalid transaction reference'
                ]);
            }

            if ($verificationResult['success']) {
                $paytmStatus = $request->STATUS ?? 'TXN_SUCCESS';

                $internalStatus = $this->updateTransactionStatus(
                    $transaction,
                    $paytmStatus,
                    'paytm',
                    $request->all()
                );

                if (isset($verificationResult['transaction_id'])) {
                    $transaction->update([
                        'gateway_transaction_id' => $verificationResult['transaction_id']
                    ]);
                }



                if ($internalStatus === 'completed') {
                    return view('payment-success', [
                        'message' => 'Payment successful!',
                        'transaction_id' => $verificationResult['order_id'],
                        'amount' => $verificationResult['amount'] ?? $transaction->amount
                    ]);
                } else {
                    return view('payment-failed', [
                        'message' => 'Payment failed',
                        'transaction_id' => $verificationResult['order_id'],
                        'error' => 'Payment was not successful'
                    ]);
                }
            } else {
                $paytmStatus = $request->STATUS ?? 'TXN_FAILURE';

                $internalStatus = $this->updateTransactionStatus(
                    $transaction,
                    $paytmStatus,
                    'paytm',
                    $request->all()
                );



                return view('payment-failed', [
                    'message' => 'Payment verification failed',
                    'transaction_id' => $verificationResult['order_id'],
                    'error' => $verificationResult['message'] ?? 'Unknown error'
                ]);
            }
        } catch (\Exception $e) {
            return view('payment-failed', [
                'message' => 'Payment processing failed',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function razorpayCallback(Request $request)
    {
        try {
            if (!$this->verifyRazorpayRedirectSignature($request)) {
                return view('payment-failed', [
                    'message' => 'Payment verification failed',
                    'error' => 'Invalid Razorpay callback signature'
                ]);
            }

            $paymentLinkId = $request->razorpay_payment_link_id;
            $paymentId = $request->razorpay_payment_id;
            $status = $request->razorpay_payment_link_status;
            $orderId = $request->razorpay_order_id;

            // First try to find pending transaction (for new callbacks)
            $transaction = Transaction::where('gateway_transaction_id', $paymentLinkId)
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->first();

            // If not found, try without status filter (for duplicate callbacks)
            if (!$transaction) {
                $transaction = Transaction::where('gateway_transaction_id', $paymentLinkId)
                    ->orderBy('created_at', 'desc')
                    ->first();
            }

            if (!$transaction) {

                return view('payment-failed', [
                    'message' => 'Transaction not found',
                    'error' => 'Invalid transaction reference'
                ]);
            }

            // Handle duplicate callback - if transaction is already completed, return success
            if ($transaction->status === 'completed' && strtolower($status) === 'paid') {

                return view('payment-success', [
                    'message' => 'Payment successful!',
                    'transaction_id' => $transaction->transaction_id,
                    'payment_id' => $paymentId,
                    'amount' => $transaction->amount
                ]);
            }

            $internalStatus = $this->updateTransactionStatus(
                $transaction,
                $status,
                'razorpay',
                $request->all()
            );


            if ($internalStatus === 'completed') {
                return view('payment-success', [
                    'message' => 'Payment successful!',
                    'transaction_id' => $transaction->transaction_id,
                    'payment_id' => $paymentId,
                    'amount' => $transaction->amount
                ]);
            } else {
                return view('payment-failed', [
                    'message' => 'Payment failed',
                    'transaction_id' => $transaction->transaction_id,
                    'payment_id' => $paymentId,
                    'error' => 'Payment was not successful'
                ]);
            }
        } catch (\Exception $e) {
            return view('payment-failed', [
                'message' => 'Payment processing failed',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function stripeSuccess(Request $request)
    {
        try {
            $transactionId = $request->transaction_id;
            $paymentIntentId = $request->payment_intent_id;
            $paymentLinkId = $request->payment_link_id;
            $status = $request->status ?? 'succeeded';

            $transaction = Transaction::where('transaction_id', $transactionId)->first();

            // Search by payment_intent_id if transaction not found
            if (!$transaction && $paymentIntentId) {
                $transaction = Transaction::where('gateway_transaction_id', $paymentIntentId)
                    ->orderBy('created_at', 'desc')
                    ->first();
            }

            // Search by payment_link_id if transaction still not found
            if (!$transaction && $paymentLinkId) {
                $transaction = Transaction::where('gateway_transaction_id', $paymentLinkId)
                    ->orderBy('created_at', 'desc')
                    ->first();
            }

            if (!$transaction) {

                return view('payment-failed', [
                    'message' => 'Transaction not found',
                    'error' => 'Invalid transaction reference'
                ]);
            }

            // If transaction is already completed but booking payment_status is not paid, update it
            if ($transaction->status === 'completed' && $transaction->booking_id) {
                $booking = Booking::find($transaction->booking_id);
                if ($booking && $booking->payment_status !== 'paid') {


                    // Check if this is a split payment
                    $transactionMetaData = $transaction->meta_data ?? [];
                    $isSplitPayment = isset($transactionMetaData['is_split_payment']) && $transactionMetaData['is_split_payment'] === true;
                    $walletContribution = isset($transactionMetaData['wallet_amount']) ? (float) $transactionMetaData['wallet_amount'] : 0;
                    $primaryPaymentMethod = $transactionMetaData['primary_payment_method'] ?? ($transaction->payment_method ?? 'stripe');

                    $bookingUpdate = [
                        'payment_status' => 'paid',
                        'online_paid_amount' => $transaction->amount,
                        'total_amount' => $transaction->amount,
                    ];

                    if ($isSplitPayment && $walletContribution > 0) {
                        $bookingUpdate['payment_method'] = 'split';
                        $bookingUpdate['wallet_amount'] = $walletContribution;
                        $bookingUpdate['total_amount'] = $transaction->amount + $walletContribution;
                        $existingMetaData = $booking->meta_data ?? [];
                        $bookingUpdate['meta_data'] = array_merge($existingMetaData, [
                            'original_payment_method' => $primaryPaymentMethod,
                            'is_split_payment' => true,
                        ]);
                    } else {
                        $bookingUpdate['payment_method'] = $transaction->payment_method ?? 'stripe';
                    }

                    $booking->update($bookingUpdate);

                    // Process driver payout if needed
                    if ($booking->driver_id && !$booking->driver_amount) {
                        try {
                            app(PaymentGatewayService::class)->processDriverPayout($booking, $transaction->amount, $transaction->payment_method ?? 'stripe');
                        } catch (\Exception $e) {
                        }
                    }

                    // Settle debts if needed
                    try {
                        app(UserDebtService::class)->settleAppliedDebtsForBooking($booking->fresh());
                        // Credit debt amount to admin wallet when payment is received
                        $this->creditDebtAmountToAdminWallet($booking->fresh());
                    } catch (\Exception $e) {
                    }

                    return view('payment-success', [
                        'message' => 'Payment successful!',
                        'transaction_id' => $transaction->transaction_id,
                        'payment_intent_id' => $paymentIntentId,
                        'payment_link_id' => $paymentLinkId,
                        'amount' => $transaction->amount
                    ]);
                }
            }

            // Stripe success redirect should not mutate payment state.
            // Authoritative state updates are handled by signed Stripe webhooks.
            if ($transaction->status === 'completed') {
                return view('payment-success', [
                    'message' => 'Payment successful!',
                    'transaction_id' => $transaction->transaction_id,
                    'payment_intent_id' => $paymentIntentId,
                    'payment_link_id' => $paymentLinkId,
                    'amount' => $transaction->amount
                ]);
            } else {
                return view('payment-failed', [
                    'message' => 'Payment failed',
                    'transaction_id' => $transaction->transaction_id,
                    'payment_intent_id' => $paymentIntentId,
                    'payment_link_id' => $paymentLinkId,
                    'error' => 'Payment was not successful'
                ]);
            }
        } catch (\Exception $e) {
            return view('payment-failed', [
                'message' => 'Payment processing failed',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function stripeCancel(Request $request)
    {
        try {
            $transactionId = $request->transaction_id;
            $paymentIntentId = $request->payment_intent_id;

            $transaction = Transaction::where('transaction_id', $transactionId)->first();

            if (!$transaction && $paymentIntentId) {
                $transaction = Transaction::where('gateway_transaction_id', $paymentIntentId)
                    ->where('status', 'pending')  // Only find pending transactions
                    ->orderBy('created_at', 'desc')  // Get the most recent one
                    ->first();
            }

            return view('payment-failed', [
                'message' => 'Payment cancelled',
                'transaction_id' => $transactionId,
                'payment_intent_id' => $paymentIntentId
            ]);
        } catch (\Exception $e) {
            return view('payment-failed', [
                'message' => 'Payment processing failed',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function updateCashPaymentStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
            'status' => 'required|in:completed,failed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();
        $current_booking = User::where('id', $driver->id)->first();
        $transaction = Transaction::where('transaction_id', $request->transaction_id)
            ->where('payment_method', 'cash')
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Cash transaction not found or already processed'
            ], 404);
        }

        $booking = Booking::where('id', $transaction->booking_id)->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found'
            ], 404);
        }

        if (!is_null($booking->driver_id)) {
            $booking->driver_id = (int) $booking->driver_id;
        }

        if ($booking->driver_id !== $driver->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to update this payment'
            ], 403);
        }

        try {
            DB::beginTransaction();

            $transaction->update([
                'status' => $request->status,
                'gateway_response' => [
                    'driver_id' => $driver->id,
                    'driver_name' => $driver->name,
                    'updated_at' => now()->toISOString(),
                    'status' => $request->status
                ]
            ]);

            $current_booking->update([
                'current_booking_id' => null,
            ]);

            $bookingUpdates = [];
            if ($request->status === 'completed') {
                $bookingUpdates = [
                    'payment_status' => 'paid',
                    'payment_method' => 'cash',
                    'online_paid_amount' => 0,  // Cash payment
                    'total_amount' => $transaction->amount,
                ];
            } else {
                $bookingUpdates = [
                    'payment_status' => 'failed',
                    'payment_method' => 'cash',
                ];
            }

            $booking->update($bookingUpdates);

            $paymentStatus = $bookingUpdates['payment_status'] ?? null;
            if ($paymentStatus === 'paid') {
                app(UserDebtService::class)->settleAppliedDebtsForBooking($booking->fresh());
                // Credit debt amount to admin wallet when payment is received
                $this->creditDebtAmountToAdminWallet($booking->fresh());
            } elseif ($paymentStatus === 'failed') {
                app(UserDebtService::class)->releaseAppliedDebtsForBooking($booking->fresh());
            }

            DB::commit();

            $transaction->refresh();
            $booking->refresh();

            if ($paymentStatus === 'paid' && $booking->driver_id) {
                try {
                    app(NotificationService::class)->sendPaymentCompletionNotificationToDriver($booking->fresh());
                } catch (\Exception $e) {
                }
            }

            $this->broadcastPaymentSuccess($transaction);



            return response()->json([
                'success' => true,
                'message' => 'Cash payment status updated successfully',
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    'booking_id' => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'status' => $request->status,
                    'amount' => $transaction->amount,
                    'payment_method' => 'cash',
                    'updated_at' => $transaction->updated_at->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update cash payment status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function broadcastPaymentSuccess($transaction)
    {
        try {
            $booking = $transaction->booking;

            if (!$booking) {

                return;
            }

            $metaData = $transaction->meta_data ?? [];
            if (is_string($metaData)) {
                $metaData = json_decode($metaData, true) ?? [];
            }

            if (!empty($metaData['payment_success_broadcasted'])) {

                return;
            }

            broadcast(new PaymentSuccess($transaction, $booking))->toOthers();

            $metaData['payment_success_broadcasted'] = true;
            $metaData['payment_success_broadcasted_at'] = now()->toISOString();
            $transaction->meta_data = $metaData;
            $transaction->save();
        } catch (\Exception $e) {
            // Log error silently
        }
    }

    private function verifyRazorpayRedirectSignature(Request $request): bool
    {
        $signature = $request->input('razorpay_signature');
        $paymentId = $request->input('razorpay_payment_id');
        $orderId = $request->input('razorpay_order_id');
        $secret = config('services.razorpay.secret');

        if (!$signature || !$paymentId || !$orderId || !$secret) {
            return app()->environment(['local', 'testing']);
        }

        $payload = $orderId . '|' . $paymentId;
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    public function testPaymentBroadcast(Request $request)
    {
        try {
            $bookingId = $request->booking_id ?? null;
            $booking = null;
            $transaction = null;

            if ($bookingId) {
                $booking = Booking::find($bookingId);
                if ($booking) {
                    $transaction = \App\Models\Transaction::where('booking_id', $booking->id)->first();
                }
            }

            if (!$booking || !$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide a valid booking_id for testing',
                    'error' => 'No booking or transaction found'
                ], 404);
            }



            broadcast(new PaymentSuccess($transaction, $booking))->toOthers();



            return response()->json([
                'success' => true,
                'message' => 'Payment success test event broadcasted',
                'booking_id' => $booking->id,
                'transaction_id' => $transaction->transaction_id,
                'payment_method' => $transaction->payment_method
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to broadcast test event',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Credit debt amount to admin wallet when payment is received
     *
     * For cash payments:
     * - Debit debt amount from driver wallet (driver collected the cash)
     * - Credit debt amount to admin wallet
     *
     * For non-cash payments (stripe, razorpay, wallet, split, etc.):
     * - Only credit debt amount to admin wallet (driver never had the cash)
     * - Do NOT debit from driver wallet
     */
    private function creditDebtAmountToAdminWallet(Booking $booking): void
    {
        try {


            $debtAmount = (float) ($booking->debt_amount ?? 0);

            if ($debtAmount <= 0) {

                return;
            }

            $adminUser = User::find(1);
            if (!$adminUser) {
                // Admin user not found for debt amount credit
                return;
            }

            $walletService = app(\App\Services\WalletService::class);
            $adminWallet = $walletService->ensureWallet($adminUser);

            // Check if debt amount has already been credited for this booking to prevent duplicates
            // Check both reference_type/reference_id and meta_data as fallback
            $existingTransaction = WalletTransaction::where('wallet_id', $adminWallet->id)
                ->where(function ($query) use ($booking, $debtAmount) {
                    $query->where(function ($q) use ($booking, $debtAmount) {
                        $q->where('reference_type', Booking::class)
                            ->where('reference_id', $booking->id);
                    })->orWhere(function ($q) use ($booking, $debtAmount) {
                        $q->whereRaw("JSON_EXTRACT(meta_data, '$.booking_id') = ?", [$booking->id])
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_data, '$.booking_id')) = ?", [$booking->id]);
                    });
                })
                ->where('amount', $debtAmount)
                ->where('description', 'like', '%debt%')
                ->first();

            if ($existingTransaction) {

                return;
            }

            // Determine payment method and whether it's cash
            $paymentMethod = $booking->payment_method ?? 'cash';
            $paymentMethodNormalized = strtolower(trim($paymentMethod));

            // For cash payments: driver has the cash, so we need to debit from driver wallet
            // For non-cash payments (stripe, razorpay, wallet, split, etc.):
            // driver never had the cash, so we only credit to admin wallet
            $isCashPayment = $paymentMethodNormalized === 'cash';

            // For cash payments ONLY: debit debt amount from driver wallet (driver has the cash)
            // For non-cash payments (stripe, razorpay, wallet, split, etc.):
            // - Do NOT debit from driver wallet (driver never had the cash)
            // - Only credit to admin wallet (payment was made online)
            if ($isCashPayment && $booking->driver_id) {
                $driver = $booking->driver;
                if ($driver) {
                    $driverWallet = $walletService->ensureWallet($driver);

                    // Check if debt has already been debited from driver wallet
                    // Check both reference_type/reference_id and meta_data as fallback
                    $existingDriverDebit = WalletTransaction::where('wallet_id', $driverWallet->id)
                        ->where(function ($query) use ($booking, $debtAmount) {
                            $query->where(function ($q) use ($booking, $debtAmount) {
                                $q->where('reference_type', Booking::class)
                                    ->where('reference_id', $booking->id);
                            })->orWhere(function ($q) use ($booking, $debtAmount) {
                                $q->whereRaw("JSON_EXTRACT(meta_data, '$.booking_id') = ?", [$booking->id])
                                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_data, '$.booking_id')) = ?", [$booking->id]);
                            });
                        })
                        ->where('amount', -$debtAmount)
                        ->where('description', 'like', '%debt%')
                        ->first();

                    if (!$existingDriverDebit) {
                        // Debit debt amount from driver wallet
                        $driverDebitTransaction = $driverWallet->debit(
                            $debtAmount,
                            WalletTransaction::TYPE_ADJUSTMENT,
                            "Debt amount deducted for booking #{$booking->booking_code}",
                            [
                                'booking_id' => $booking->id,
                                'booking_code' => $booking->booking_code,
                                'user_id' => $booking->user_id,
                                'debt_amount' => $debtAmount,
                                'payment_method' => 'cash',
                                'debited_at' => now()->toDateTimeString(),
                            ],
                            null,
                            true // Allow negative balance
                        );

                        $driverDebitTransactionId = 'DEBT_DRV_' . time() . '_' . rand(1000, 9999);
                        $driverDebitTransaction->update([
                            'transection_id' => $driverDebitTransactionId,
                            'reference_type' => Booking::class,
                            'reference_id' => $booking->id,
                        ]);
                    }
                }
            }

            // Credit debt amount to admin wallet
            $walletTransaction = $adminWallet->credit(
                $debtAmount,
                WalletTransaction::TYPE_ADJUSTMENT,
                "Debt payment received for booking #{$booking->booking_code}",
                [
                    'booking_id' => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'user_id' => $booking->user_id,
                    'debt_amount' => $debtAmount,
                    'payment_method' => $paymentMethod,
                    'credited_at' => now()->toDateTimeString(),
                ]
            );

            $transactionId = 'DEBT_' . time() . '_' . rand(1000, 9999);
            $walletTransaction->update([
                'transection_id' => $transactionId,
                'reference_type' => Booking::class,
                'reference_id' => $booking->id,
            ]);
        } catch (\Throwable $e) {
        }
    }
}
