<?php

namespace App\Console\Commands;

use Exception;
use App\Models\Booking;
use App\Models\WalletTransaction;
use App\Services\PaymentGatewayService;
use Illuminate\Console\Command;


class ProcessScheduledPayouts extends Command
{
    protected $signature = 'payouts:process-scheduled {--limit=50 : Maximum number of payouts to process in one run}';

    protected $description = 'Process scheduled driver payouts that are past their scheduled time';

    public function handle(PaymentGatewayService $paymentGatewayService): int
    {
        $limit = (int) $this->option('limit');
        $now = now();

        $this->info("Processing scheduled payouts that are due...");

        // Log that cron job started running (with unique identifier for easy searching)

        // Find bookings with scheduled payouts that are past their scheduled time
        // Exclude refunded bookings - driver should not receive payout if booking was refunded
        $scheduledBookings = Booking::where('driver_payout_status', Booking::DRIVER_PAYOUT_SCHEDULED)
            ->whereNotNull('driver_payout_scheduled_at')
            ->where('driver_payout_scheduled_at', '<=', $now)
            ->whereNotNull('driver_id')
            ->whereNotNull('driver_amount')
            ->where('driver_amount', '>', 0)
            ->where('status', 'completed')
            ->where('payment_status', '!=', 'refunded') // Exclude refunded bookings
            ->whereDoesntHave('transactions', function ($query) {
                // Also exclude if there are refund transactions in transactions table
                $query->where('type', 'refund')
                    ->where('status', 'completed');
            })
            ->limit($limit)
            ->get();

        // Debug: Log how many bookings found before refund filter
        if ($scheduledBookings->isNotEmpty()) {
        }

        // Filter out bookings that have booking_refund wallet transactions
        // We do this in PHP to avoid complex JSON queries that might fail
        $scheduledBookings = $scheduledBookings->filter(function ($booking) {
            try {
                // Get all booking_refund transactions and check their meta_data
                $refundTransactions = WalletTransaction::where('type', WalletTransaction::TYPE_BOOKING_REFUND)
                    ->where('status', 'completed')
                    ->get();

                $hasBookingRefund = false;
                $matchedRefundId = null;

                foreach ($refundTransactions as $refundTransaction) {
                    $metaData = $refundTransaction->meta_data;

                    // Handle both array and JSON string formats
                    if (is_string($metaData)) {
                        $metaData = json_decode($metaData, true);
                    }

                    // Check if this refund transaction is for this booking
                    if (isset($metaData['booking_id']) && (int)$metaData['booking_id'] === (int)$booking->id) {
                        $hasBookingRefund = true;
                        $matchedRefundId = $refundTransaction->id;
                        break;
                    }
                }

                if ($hasBookingRefund) {
                    
                }

                return !$hasBookingRefund;
            } catch (Exception $e) {
                // If check fails, log and include the booking (safer to process than skip)
                return true; // Include booking if check fails
            }
        })->values();

        if ($scheduledBookings->isEmpty()) {
            $this->info('No scheduled payouts found that are due.');

            // Debug: Check if there are any scheduled bookings at all
            $totalScheduled = Booking::where('driver_payout_status', Booking::DRIVER_PAYOUT_SCHEDULED)
                ->whereNotNull('driver_payout_scheduled_at')
                ->where('driver_payout_scheduled_at', '<=', $now)
                ->count();

            // Debug: Check bookings without other filters
            $basicQuery = Booking::where('driver_payout_status', Booking::DRIVER_PAYOUT_SCHEDULED)
                ->whereNotNull('driver_payout_scheduled_at')
                ->where('driver_payout_scheduled_at', '<=', $now)
                ->whereNotNull('driver_id')
                ->whereNotNull('driver_amount')
                ->where('driver_amount', '>', 0)
                ->where('status', 'completed');

            $basicCount = $basicQuery->count();
            $basicIds = $basicQuery->pluck('id')->toArray();


            return 0;
        }

        $this->info("Found {$scheduledBookings->count()} scheduled payout(s) to process.");

        $processed = 0;
        $failed = 0;

        foreach ($scheduledBookings as $booking) {
            try {
                $this->line("Processing payout for booking #{$booking->booking_code} (ID: {$booking->id})...");

                // Check if booking has been refunded - skip payout if refunded
                if ($booking->payment_status === 'refunded') {
                    $this->warn("⚠ Booking #{$booking->booking_code} has been refunded, skipping driver payout...");

                    // Mark as completed without processing payout
                    $booking->update([
                        'driver_payout_status' => Booking::DRIVER_PAYOUT_COMPLETED,
                        'driver_payout_released_at' => now(),
                    ]);

                    

                    $processed++; // Count as processed (skipped)
                    continue;
                }

                // Check if there are refund transactions in transactions table
                $hasRefundTransaction = $booking->transactions()
                    ->where('type', 'refund')
                    ->where('status', 'completed')
                    ->exists();

                if ($hasRefundTransaction) {
                    $this->warn("⚠ Booking #{$booking->booking_code} has refund transactions, skipping driver payout...");

                    // Mark as completed without processing payout
                    $booking->update([
                        'driver_payout_status' => Booking::DRIVER_PAYOUT_COMPLETED,
                        'driver_payout_released_at' => now(),
                    ]);

                    

                    $processed++; // Count as processed (skipped)
                    continue;
                }

                // Check if there are booking_refund wallet transactions for this booking
                // Refunds are stored in wallet_transactions with type 'booking_refund' and booking_id in meta_data
                $hasBookingRefund = WalletTransaction::where('type', WalletTransaction::TYPE_BOOKING_REFUND)
                    ->where('status', 'completed')
                    ->whereRaw('CAST(JSON_EXTRACT(meta_data, "$.booking_id") AS UNSIGNED) = ?', [$booking->id])
                    ->exists();

                if ($hasBookingRefund) {
                    $this->warn("⚠ Booking #{$booking->booking_code} has been refunded (booking_refund found), skipping driver payout...");

                    // Mark as completed without processing payout
                    $booking->update([
                        'driver_payout_status' => Booking::DRIVER_PAYOUT_COMPLETED,
                        'driver_payout_released_at' => now(),
                    ]);

                    

                    $processed++; // Count as processed (skipped)
                    continue;
                }

                // Check if wallet transaction already exists for this booking (safeguard against double-processing)
                $existingTransaction = WalletTransaction::where('reference_type', Booking::class)
                    ->where('reference_id', $booking->id)
                    ->where('type', 'credit')
                    ->where('status', 'completed')
                    ->first();

                if ($existingTransaction) {
                    $this->warn("⚠ Wallet transaction already exists for booking #{$booking->booking_code}, marking as completed...");

                    // Just update the status without processing again
                    $booking->update([
                        'driver_payout_status' => Booking::DRIVER_PAYOUT_COMPLETED,
                        'driver_payout_released_at' => $existingTransaction->created_at ?? now(),
                    ]);

                    $processed++;
                    continue;
                }

                // Get the payment method from the booking
                $paymentMethod = $booking->payment_method;

                // Process the payout directly
                // Note: releaseDriverPayoutNow expects total_amount (before commission)
                // It will recalculate commission and credit the driver amount
                $paymentGatewayService->releaseDriverPayoutNow(
                    $booking,
                    $booking->total_amount,
                    $paymentMethod
                );

                // Update the booking to mark payout as completed
                $booking->update([
                    'driver_payout_status' => Booking::DRIVER_PAYOUT_COMPLETED,
                    'driver_payout_released_at' => now(),
                ]);

                $processed++;
                $this->info("✓ Successfully processed payout for booking #{$booking->booking_code}");

                
            } catch (Exception $e) {
                $failed++;
                $this->error("✗ Failed to process payout for booking #{$booking->booking_code}: {$e->getMessage()}");

            }
        }

        $this->info("\nSummary:");
        $this->info("  Processed: {$processed}");
        $this->info("  Failed: {$failed}");
        $this->info("  Total: {$scheduledBookings->count()}");


        return $failed > 0 ? 1 : 0;
    }
}
