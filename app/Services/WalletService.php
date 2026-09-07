<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use App\Services\PaymentGatewayService;

class WalletService
{
    public function ensureWallet(User $user): Wallet
    {
        return $user->wallet ?? $user->wallet()->create([
            'balance' => 0,
            'status' => Wallet::STATUS_ACTIVE,
        ]);
    }

    public function topUp(User $user, float $amount, string $description, ?array $meta = null): WalletTransaction
    {
        $wallet = $this->ensureWallet($user);

        if (!$wallet->isActive()) {
            throw new \Exception('Wallet is not active');
        }

        return $wallet->credit($amount, WalletTransaction::TYPE_WALLET_TOPUP, $description, $meta);
    }

    public function withdraw(User $user, float $amount, string $description, ?array $meta = null): WalletTransaction
    {
        $wallet = $this->ensureWallet($user);

        if (!$wallet->isActive()) {
            throw new \Exception('Wallet is not active');
        }

        return $wallet->debit($amount, WalletTransaction::TYPE_WALLET_WITHDRAWAL, $description, $meta);
    }

    public function processBookingPayment(Booking $booking): void
    {
        if ($booking->payment_method !== 'wallet' && $booking->payment_method !== 'split') {
            throw new \Exception('Invalid payment method');
        }

        $wallet = $this->ensureWallet($booking->user);

        if (!$wallet->isActive()) {
            throw new \Exception('Wallet is not active');
        }

        DB::transaction(function () use ($booking, $wallet) {
            $walletAmount = $booking->payment_method === 'wallet'
                ? $booking->total_amount
                : $booking->wallet_amount;

            $transaction = $wallet->debit(
                $walletAmount,
                WalletTransaction::TYPE_BOOKING_PAYMENT,
                "Payment for booking #{$booking->booking_code}",
                [
                    'booking_id' => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'payment_method' => $booking->payment_method,
                ]
            );

            $booking->update(['wallet_transaction_id' => $transaction->id]);
        });

        $booking->refresh();

        app(PaymentGatewayService::class)->processDriverPayout(
            $booking,
            $booking->total_amount,
            $booking->payment_method ?? 'wallet'
        );
    }

    public function processBookingRefund(Booking $booking, float $amount, string $reason): void
    {
        if (!$booking->wallet_transaction_id) {
            throw new \Exception('No wallet transaction found for this booking');
        }

        $transaction = WalletTransaction::findOrFail($booking->wallet_transaction_id);

        if ($amount > abs($transaction->amount)) {
            throw new \Exception('Refund amount cannot exceed the original payment');
        }

        DB::transaction(function () use ($booking, $transaction, $amount, $reason) {
            $refundTransaction = $transaction->wallet->credit(
                $amount,
                WalletTransaction::TYPE_BOOKING_REFUND,
                "Refund for booking #{$booking->booking_code}: {$reason}",
                [
                    'booking_id' => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'original_transaction_id' => $transaction->id,
                    'refund_reason' => $reason,
                ]
            );

            if ($booking->driver_id && $booking->driver_amount > 0) {
                $refundShare = ($amount / $booking->total_amount) * $booking->driver_amount;
                $booking->driver->driverProfile->decrement('pending_payout', $refundShare);
            }

            $transaction->update([
                'status' => WalletTransaction::STATUS_REVERSED,
                'meta_data' => array_merge($transaction->meta_data ?? [], [
                    'refunded_at' => now()->toDateTimeString(),
                    'refund_amount' => $amount,
                    'refund_reason' => $reason,
                    'refund_transaction_id' => $refundTransaction->id,
                ]),
            ]);
        });
    }

    public function processDriverPayout(User $driver, float $amount, string $method, array $payoutDetails): void
    {
        if (!$driver->isDriver()) {
            throw new \Exception('User is not a driver');
        }

        $wallet = $this->ensureWallet($driver);

        if (!$wallet->isActive()) {
            throw new \Exception('Wallet is not active');
        }

        if ($amount > $driver->driverProfile->pending_payout) {
            throw new \Exception('Payout amount exceeds pending balance');
        }

        DB::transaction(function () use ($driver, $wallet, $amount, $method, $payoutDetails) {
            $walletTransaction = $wallet->debit(
                $amount,
                WalletTransaction::TYPE_DRIVER_PAYOUT,
                "Driver payout via {$method}",
                array_merge($payoutDetails, [
                    'payout_method' => $method,
                    'processed_at' => now()->toDateTimeString(),
                ])
            );

            $wallet->refresh();

            Transaction::create([
                'transaction_id' => 'TXN_' . time() . '_' . rand(1000, 9999),
                'wallet_id' => $wallet->id,
                'user_id' => $driver->id,
                'booking_id' => null,
                'type' => 'debit',
                'amount' => $amount,
                'balance' => $wallet->balance,
                'description' => "Driver payout via {$method}",
                'status' => 'completed',
                'payment_method' => 'wallet', // Payout through wallet
                'reference_id' => $payoutDetails['payout_id'] ?? null,
                'reference_type' => $payoutDetails['payout_type'] ?? 'payout',
                'meta_data' => array_merge($payoutDetails, [
                    'payout_method' => $method,
                    'processed_at' => now()->toDateTimeString(),
                    'wallet_transaction_id' => $walletTransaction->id,
                ]),
            ]);

            $driverProfile = $driver->driverProfile;

            $driverProfile->update([
                'last_payout_transaction_id' => $walletTransaction->id,
                'last_payout_at' => now(),
            ]);

            $driverProfile->decrement('pending_payout', $amount);
        });
    }

    public function addReferralBonus(User $user, float $amount, string $referralCode): WalletTransaction
    {
        $wallet = $this->ensureWallet($user);

        return $wallet->credit(
            $amount,
            WalletTransaction::TYPE_REFERRAL_BONUS,
            "Referral bonus for code: {$referralCode}",
            [
                'referral_code' => $referralCode,
                'credited_at' => now()->toDateTimeString(),
            ]
        );
    }

    public function addPromoCredit(User $user, float $amount, string $promoCode): WalletTransaction
    {
        $wallet = $this->ensureWallet($user);

        return $wallet->credit(
            $amount,
            WalletTransaction::TYPE_PROMO_CREDIT,
            "Promo credit for code: {$promoCode}",
            [
                'promo_code' => $promoCode,
                'credited_at' => now()->toDateTimeString(),
            ]
        );
    }

    
    public function deductFromWallet(User $user, float $amount, string $description, ?array $meta = null): WalletTransaction
    {
        $wallet = $this->ensureWallet($user);

        if (!$wallet->isActive()) {
            throw new \Exception('Wallet is not active');
        }

        return $wallet->debit($amount, WalletTransaction::TYPE_BOOKING_PAYMENT, $description, $meta);
    }

    
    public function transfer(User $sender, User $recipient, float $amount, array $data): array
    {
        $senderWallet = $this->ensureWallet($sender);
        $recipientWallet = $this->ensureWallet($recipient);

        if (!$senderWallet->isActive() || !$recipientWallet->isActive()) {
            throw new \Exception('One or both wallets are not active');
        }

        if ($senderWallet->balance < $amount) {
            throw new \Exception('Insufficient balance for transfer');
        }

        $description = $data['description'] ?? "Transfer to {$recipient->name}";

        return DB::transaction(function () use ($senderWallet, $recipientWallet, $amount, $description, $data) {
            $debitTransaction = $senderWallet->debit(
                $amount,
                WalletTransaction::TYPE_WALLET_WITHDRAWAL,
                $description,
                array_merge($data, ['transfer_type' => 'outgoing'])
            );

            $creditTransaction = $recipientWallet->credit(
                $amount,
                WalletTransaction::TYPE_WALLET_TOPUP,
                "Transfer from {$senderWallet->user->name}",
                array_merge($data, ['transfer_type' => 'incoming'])
            );

            return [
                'debit' => $debitTransaction,
                'credit' => $creditTransaction,
            ];
        });
    }
}
