<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\DriverRating;
use App\Models\DriverPayout;
use App\Services\NotificationService;
use App\Services\UserDebtService;
use App\Services\WalletService;
use App\Models\User;
use App\Models\WalletTransaction;


class DriverPaymentController extends Controller
{

    public function collectPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|exists:bookings,id',
            'payment_method' => 'required|in:cash,online',
            'amount_collected' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();
        $booking = Booking::where('id', $request->booking_id)
            ->where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->firstOrFail();

        if ($booking->payment_status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Payment already collected for this trip'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $paymentMethod = $request->payment_method;
            $amountCollected = $request->amount_collected;

            $booking->update([
                'payment_status' => 'paid',
                'payment_method' => $paymentMethod,
                'cash_amount' => $paymentMethod === 'cash' ? $amountCollected : 0,
                'online_paid_amount' => $paymentMethod === 'online' ? $amountCollected : 0,
            ]);

            // IMPORTANT: If payment is cash, deduct commission from driver wallet
            if ($paymentMethod === 'cash' && $booking->driver_id) {
                $this->deductCommissionForCashPaymentIfNeeded($booking);
            }

            // Settle debts when payment is received
            app(UserDebtService::class)->settleAppliedDebtsForBooking($booking->fresh());

            // Credit debt amount to admin wallet when payment is received
            $this->creditDebtAmountToAdminWallet($booking->fresh());

            DriverPayout::create([
                'driver_id' => $driver->id,
                'booking_id' => $booking->id,
                'amount' => $this->calculateDriverAmount($booking),
                'status' => 'pending',
                'payment_method' => $paymentMethod,
                'collected_at' => now(),
            ]);

            DB::commit();

            try {
                $booking->refresh();
                app(NotificationService::class)->sendPaymentCompletionNotificationToDriver($booking);
            } catch (\Exception $e) {
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment collected successfully',
                'data' => [
                    'payment_method' => $paymentMethod,
                    'amount_collected' => $amountCollected,
                    'driver_amount' => $this->calculateDriverAmount($booking),
                    'payment_status' => 'paid',
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to collect payment'
            ], 500);
        }
    }


    public function rateRider(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|exists:bookings,id',
            'rating' => 'required|integer|between:1,5',
            'feedback_tags' => 'array',
            'feedback_tags.*' => 'string|in:on_time,unclean,paid_incorrectly,rude_abusive,friendly_nature',
            'comments' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $driver = auth()->user();
        $booking = Booking::where('id', $request->booking_id)
            ->where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->firstOrFail();

        if ($booking->driver_rating) {
            return response()->json([
                'success' => false,
                'message' => 'Rider already rated for this trip'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $booking->update([
                'driver_rating' => $request->rating,
                'driver_review' => $request->comments,
            ]);

            DriverRating::create([
                'driver_id' => $driver->id,
                'rider_id' => $booking->user_id,
                'booking_id' => $booking->id,
                'rating' => $request->rating,
                'feedback_tags' => $request->feedback_tags ?? [],
                'comments' => $request->comments,
                'rated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rider rated successfully',
                'data' => [
                    'rating' => $request->rating,
                    'feedback_tags' => $request->feedback_tags ?? [],
                    'comments' => $request->comments,
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to rate rider'
            ], 500);
        }
    }


    public function getPaymentBreakdown($bookingId)
    {
        $driver = auth()->user();

        $booking = Booking::where('id', $bookingId)
            ->where('driver_id', $driver->id)
            ->where('status', 'completed')
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
            'final_amount' => $booking->final_fare,
            'driver_amount' => $this->calculateDriverAmount($booking),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'breakdown' => $breakdown,
                'payment_status' => $booking->payment_status,
                'payment_method' => $booking->payment_method,
            ]
        ]);
    }


    private function calculateDriverAmount($booking)
    {
        $commissionRate = config('app.driver_commission_rate', 0.8); // 80% by default
        return $booking->final_fare * $commissionRate;
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
                        $q->where('reference_type', 'App\Models\Booking')
                            ->where('reference_id', $booking->id);
                    })->orWhere(function ($q) use ($booking) {
                        $q->whereRaw("JSON_EXTRACT(meta_data, '$.booking_id') = ?", [$booking->id])
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_data, '$.booking_id')) = ?", [$booking->id]);
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
                        $q->where('reference_type', 'App\Models\Booking')
                            ->where('reference_id', $booking->id)
                            ->whereRaw("JSON_EXTRACT(meta_data, '$.tax_amount') IS NOT NULL");
                    })->orWhere(function ($q) use ($booking) {
                        $q->whereRaw("JSON_EXTRACT(meta_data, '$.booking_id') = ?", [$booking->id])
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
                true // Allow negative balance
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
                return;
            }

            $walletService = app(WalletService::class);
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

            // Non-cash payment methods (where driver doesn't have cash)
            $nonCashPaymentMethods = ['razorpay', 'stripe', 'wallet', 'card', 'upi', 'netbanking', 'paypal', 'split'];
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
            // Error handling
        }
    }
}
