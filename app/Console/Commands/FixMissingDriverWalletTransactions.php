<?php

namespace App\Console\Commands;

use Exception;
use App\Models\WalletTransaction;
use App\Models\Booking;
use App\Services\PaymentGatewayService;
use Illuminate\Console\Command;


class FixMissingDriverWalletTransactions extends Command
{
    protected $signature = 'payouts:fix-missing-wallet-transactions {--booking-id= : Specific booking ID to fix} {--all : Fix all bookings with missing wallet transactions}';

    protected $description = 'Fix bookings where driver payout is marked completed but wallet_transaction_id is missing';

    public function handle(PaymentGatewayService $paymentGatewayService): int
    {
        $bookingId = $this->option('booking-id');
        $fixAll = $this->option('all');

        if (!$bookingId && !$fixAll) {
            $this->error('Please specify either --booking-id=<id> or --all flag');
            return 1;
        }

        if ($bookingId) {
            $booking = Booking::find($bookingId);
            
            if (!$booking) {
                $this->error("Booking #{$bookingId} not found");
                return 1;
            }

            $this->fixBooking($booking, $paymentGatewayService);
        } else {
            // Find all bookings with completed payout but missing wallet_transaction_id
            $bookings = Booking::where('driver_payout_status', Booking::DRIVER_PAYOUT_COMPLETED)
                ->whereNull('wallet_transaction_id')
                ->whereNotNull('driver_id')
                ->whereNotNull('driver_amount')
                ->where('driver_amount', '>', 0)
                ->where('status', 'completed')
                ->where('payment_status', 'paid')
                ->get();

            if ($bookings->isEmpty()) {
                $this->info('No bookings found with missing wallet transactions.');
                return 0;
            }

            $this->info("Found {$bookings->count()} booking(s) with missing wallet transactions.");

            $fixed = 0;
            $failed = 0;

            foreach ($bookings as $booking) {
                try {
                    $this->fixBooking($booking, $paymentGatewayService);
                    $fixed++;
                } catch (Exception $e) {
                    $failed++;
                    $this->error("✗ Failed to fix booking #{$booking->booking_code}: {$e->getMessage()}");
                }
            }

            $this->info("\nSummary:");
            $this->info("  Fixed: {$fixed}");
            $this->info("  Failed: {$failed}");
            $this->info("  Total: {$bookings->count()}");
        }

        return 0;
    }

    protected function fixBooking(Booking $booking, PaymentGatewayService $paymentGatewayService): void
    {
        $this->line("Fixing booking #{$booking->booking_code} (ID: {$booking->id})...");

        // Check if wallet transaction already exists
        $existingTransaction = WalletTransaction::where('reference_type', Booking::class)
            ->where('reference_id', $booking->id)
            ->where('type', 'credit')
            ->where('status', 'completed')
            ->first();

        if ($existingTransaction) {
            $this->warn("⚠ Wallet transaction already exists (ID: {$existingTransaction->id}), updating booking...");
            
            $booking->update([
                'wallet_transaction_id' => $existingTransaction->id,
            ]);
            
            $this->info("✓ Updated booking with existing wallet transaction ID");
            return;
        }

        // Temporarily reset payout status to allow re-processing
        $originalStatus = $booking->driver_payout_status;
        $originalReleasedAt = $booking->driver_payout_released_at;
        
        $booking->update([
            'driver_payout_status' => Booking::DRIVER_PAYOUT_SCHEDULED,
            'driver_payout_released_at' => null,
        ]);

        try {
            // Re-process the payout
            $paymentGatewayService->releaseDriverPayoutNow(
                $booking->fresh(),
                $booking->total_amount,
                $booking->payment_method
            );

            $booking->refresh();
            
            if ($booking->wallet_transaction_id) {
                $this->info("✓ Successfully fixed booking #{$booking->booking_code}");
                $this->info("  Wallet Transaction ID: {$booking->wallet_transaction_id}");
                $this->info("  Driver Amount: ₹{$booking->driver_amount}");
            } else {
                throw new Exception('Wallet transaction ID still missing after processing');
            }
        } catch (Exception $e) {
            // Restore original status on failure
            $booking->update([
                'driver_payout_status' => $originalStatus,
                'driver_payout_released_at' => $originalReleasedAt,
            ]);
            throw $e;
        }
    }
}
