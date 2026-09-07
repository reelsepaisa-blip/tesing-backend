<?php

namespace App\Console\Commands;

use Exception;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;


class FixIncorrectDriverPayout extends Command
{
    protected $signature = 'payouts:fix-incorrect-amount {--booking-id= : Booking ID to fix} {--transaction-id= : Transaction ID to fix} {--correct-amount= : Manually specify the correct driver amount}';

    protected $description = 'Fix driver payout transactions with incorrect amounts';

    public function handle(): int
    {
        $bookingId = $this->option('booking-id');
        $transactionId = $this->option('transaction-id');

        if (!$bookingId && !$transactionId) {
            $this->error('Please specify either --booking-id=<id> or --transaction-id=<id>');
            return 1;
        }

        if ($transactionId) {
            $transaction = Transaction::find($transactionId);
            if (!$transaction) {
                $this->error("Transaction #{$transactionId} not found");
                return 1;
            }
            $booking = $transaction->booking;
        } else {
            $booking = Booking::find($bookingId);
            if (!$booking) {
                $this->error("Booking #{$bookingId} not found");
                return 1;
            }
            
            // Find the credit transaction for this booking
            $transaction = Transaction::where('booking_id', $booking->id)
                ->where('type', 'credit')
                ->where('user_id', $booking->driver_id)
                ->latest()
                ->first();
                
            if (!$transaction) {
                $this->error("No credit transaction found for booking #{$bookingId}");
                return 1;
            }
        }

        if (!$booking) {
            $this->error("Booking not found for transaction");
            return 1;
        }

        $this->info("Fixing transaction #{$transaction->id} for booking #{$booking->booking_code}");
        $this->info("Current transaction amount: ₹{$transaction->amount}");
        
        // Get booking values for display
        $tipAmount = (float) ($booking->tip_amount ?? 0);
        $adminCommission = (float) ($booking->admin_commission ?? 0);
        $adminCommissionRate = (float) ($booking->admin_commission_rate ?? 20.0);
        
        // Check if correct amount is manually specified
        $manualCorrectAmount = $this->option('correct-amount');
        if ($manualCorrectAmount) {
            $correctDriverAmount = (float) $manualCorrectAmount;
            $this->info("Using manually specified correct amount: ₹{$correctDriverAmount}");
        } else {
            // Recalculate correct driver_amount based on original booking calculation
            // Commission is calculated on subtotal (before tax), not total_amount
            
            // Check transaction meta_data for original values (before incorrect update)
            $transactionMetaData = $transaction->meta_data ?? [];
            if (is_string($transactionMetaData)) {
                $transactionMetaData = json_decode($transactionMetaData, true) ?? [];
            }
            
            // Try to get original commission from transaction meta_data
            if (isset($transactionMetaData['commission_amount']) && isset($transactionMetaData['commission_rate'])) {
                $originalCommission = (float) $transactionMetaData['commission_amount'];
                $originalCommissionRate = (float) $transactionMetaData['commission_rate'];
                
                // Recalculate using original commission values
                if ($originalCommission > 0 && $originalCommissionRate > 0) {
                    $subtotalBeforeCommission = $originalCommission / ($originalCommissionRate / 100);
                    $baseDriverAmount = $subtotalBeforeCommission - $originalCommission;
                    $correctDriverAmount = $baseDriverAmount + $tipAmount;
                    $this->info("Using original commission from transaction meta_data");
                } else {
                    // Fallback to booking values
                    if ($adminCommission > 0 && $adminCommissionRate > 0) {
                        $subtotalBeforeCommission = $adminCommission / ($adminCommissionRate / 100);
                        $baseDriverAmount = $subtotalBeforeCommission - $adminCommission;
                        $correctDriverAmount = $baseDriverAmount + $tipAmount;
                    } else {
                        $correctDriverAmount = (float) ($booking->driver_amount ?? 0);
                    }
                }
            } else {
                // If we have admin_commission and rate, calculate subtotal before commission
                if ($adminCommission > 0 && $adminCommissionRate > 0) {
                    $subtotalBeforeCommission = $adminCommission / ($adminCommissionRate / 100);
                    $baseDriverAmount = $subtotalBeforeCommission - $adminCommission;
                    $correctDriverAmount = $baseDriverAmount + $tipAmount;
                } else {
                    // Fallback: use booking's driver_amount if commission data is missing
                    $correctDriverAmount = (float) ($booking->driver_amount ?? 0);
                }
            }
        }
        
        $this->info("Booking driver_amount (may be incorrect): ₹{$booking->driver_amount}");
        $this->info("Recalculated correct driver amount: ₹{$correctDriverAmount}");
        $this->info("Tip amount: ₹{$tipAmount}");
        $this->info("Admin commission: ₹{$adminCommission} ({$adminCommissionRate}%)");

        if ($transaction->amount == $correctDriverAmount) {
            $this->info("✓ Transaction amount is already correct!");
            return 0;
        }

        $difference = $transaction->amount - $correctDriverAmount;
        $this->warn("Difference: ₹{$difference}");
        
        if (abs($difference) < 0.01) {
            $this->info("✓ Amounts match (within rounding tolerance)!");
            return 0;
        }

        if (!$this->confirm('Do you want to proceed with fixing this transaction?')) {
            $this->info('Cancelled.');
            return 0;
        }

        try {
            DB::beginTransaction();

            // Find the wallet transaction
            $walletTransaction = WalletTransaction::where('reference_type', Booking::class)
                ->where('reference_id', $booking->id)
                ->where('type', 'credit')
                ->where('status', 'completed')
                ->latest()
                ->first();

            if (!$walletTransaction) {
                throw new Exception('Wallet transaction not found');
            }

            $driver = $booking->driver;
            $driverWallet = $driver->wallet;

            if (!$driverWallet) {
                throw new Exception('Driver wallet not found');
            }

            // Create adjustment debit transaction if overpaid
            if ($difference > 0) {
                $this->info("Creating debit adjustment for ₹{$difference}...");
                
                // Debit from wallet
                $driverWallet->decrement('balance', $difference);
                $driverWallet->refresh();

                // Create wallet transaction for adjustment
                $adjustmentWalletTransaction = WalletTransaction::create([
                    'wallet_id' => $driverWallet->id,
                    'type' => 'debit',
                    'amount' => $difference,
                    'balance' => $driverWallet->balance,
                    'description' => "Adjustment for incorrect payout - Booking #{$booking->booking_code}",
                    'reference_type' => 'App\Models\Booking',
                    'reference_id' => $booking->id,
                    'status' => 'completed',
                    'meta_data' => [
                        'booking_code' => $booking->booking_code,
                        'original_transaction_id' => $transaction->id,
                        'original_amount' => $transaction->amount,
                        'correct_amount' => $correctDriverAmount,
                        'adjustment_reason' => 'Incorrect payout amount correction',
                    ],
                ]);

                // Create transaction record for adjustment
                Transaction::create([
                    'transaction_id' => 'ADJ_' . time() . '_' . rand(1000, 9999),
                    'wallet_id' => $driverWallet->id,
                    'user_id' => $driver->id,
                    'booking_id' => $booking->id,
                    'type' => 'debit',
                    'amount' => $difference,
                    'balance' => $driverWallet->balance,
                    'description' => "Adjustment for incorrect payout - Booking #{$booking->booking_code}",
                    'status' => 'completed',
                    'payment_method' => 'adjustment',
                    'meta_data' => [
                        'booking_code' => $booking->booking_code,
                        'original_transaction_id' => $transaction->id,
                        'original_amount' => $transaction->amount,
                        'correct_amount' => $correctDriverAmount,
                        'wallet_transaction_id' => $adjustmentWalletTransaction->id,
                    ],
                ]);

                $this->info("✓ Created debit adjustment transaction");
            }

            // Store original amounts before updating
            $originalTransactionAmount = $transaction->amount;
            $originalWalletTransactionAmount = $walletTransaction->amount;
            
            // Update the original transaction amount
            $this->info("Updating transaction amount from ₹{$originalTransactionAmount} to ₹{$correctDriverAmount}...");
            
            $transactionMetaData = $transaction->meta_data ?? [];
            if (is_string($transactionMetaData)) {
                $transactionMetaData = json_decode($transactionMetaData, true) ?? [];
            }
            
            $transaction->update([
                'amount' => $correctDriverAmount,
                'balance' => $driverWallet->balance,
                'meta_data' => array_merge($transactionMetaData, [
                    'original_amount' => $originalTransactionAmount,
                    'corrected_at' => now()->toDateTimeString(),
                    'correction_reason' => 'Incorrect payout amount',
                ]),
            ]);

            // Update wallet transaction amount
            $walletTransactionMetaData = $walletTransaction->meta_data ?? [];
            if (is_string($walletTransactionMetaData)) {
                $walletTransactionMetaData = json_decode($walletTransactionMetaData, true) ?? [];
            }
            
            $walletTransaction->update([
                'amount' => $correctDriverAmount,
                'balance' => $driverWallet->balance,
                'meta_data' => array_merge($walletTransactionMetaData, [
                    'original_amount' => $originalWalletTransactionAmount,
                    'corrected_at' => now()->toDateTimeString(),
                    'correction_reason' => 'Incorrect payout amount',
                ]),
            ]);
            
            // Update booking with correct driver_amount
            $booking->update([
                'driver_amount' => $correctDriverAmount,
            ]);

            // Ensure booking has correct wallet_transaction_id
            if ($booking->wallet_transaction_id != $walletTransaction->id) {
                $booking->update([
                    'wallet_transaction_id' => $walletTransaction->id,
                ]);
            }

            DB::commit();

            $this->info("✓ Successfully fixed transaction!");
            $this->info("  Transaction ID: {$transaction->id}");
            $this->info("  Wallet Transaction ID: {$walletTransaction->id}");
            $this->info("  Corrected Amount: ₹{$correctDriverAmount}");
            $this->info("  Driver Wallet Balance: ₹{$driverWallet->balance}");

            

        } catch (Exception $e) {
            DB::rollBack();
            $this->error("✗ Failed to fix transaction: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }
}
