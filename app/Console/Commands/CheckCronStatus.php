<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Models\Booking;
use Illuminate\Console\Command;

use Illuminate\Support\Facades\File;

class CheckCronStatus extends Command
{
    protected $signature = 'cron:check-status';

    protected $description = 'Check if scheduled payout cron job is running properly';

    public function handle(): int
    {
        $this->info('Checking Cron Job Status...');
        $this->newLine();

        // Check 1: Look for recent logs
        $this->info('1. Checking recent logs...');
        $logFile = storage_path('logs/laravel.log');

        if (File::exists($logFile)) {
            $logContent = File::get($logFile);
            $logLines = explode("\n", $logContent);

            // Get last 100 lines
            $recentLines = array_slice($logLines, -100);
            $recentLogs = implode("\n", $recentLines);

            // Check for ProcessScheduledPayouts logs
            $payoutLogs = [];
            foreach ($recentLines as $line) {
                if (strpos($line, 'ProcessScheduledPayouts') !== false) {
                    $payoutLogs[] = $line;
                }
            }

            if (!empty($payoutLogs)) {
                $this->info('   Found recent logs from ProcessScheduledPayouts command');
                $this->line('   Recent log entries:');
                foreach (array_slice($payoutLogs, -5) as $log) {
                    $this->line('   ' . substr($log, 0, 150));
                }
            } else {
                $this->warn('   No recent logs found from ProcessScheduledPayouts command');
                $this->line('   This might mean the cron job is not running.');
            }
        } else {
            $this->warn('   Log file not found at: ' . $logFile);
        }

        $this->newLine();

        // Check 2: Check for scheduled bookings
        $this->info('2. Checking scheduled payouts...');
        $now = now();

        $scheduledCount = Booking::where('driver_payout_status', Booking::DRIVER_PAYOUT_SCHEDULED)
            ->whereNotNull('driver_payout_scheduled_at')
            ->where('driver_payout_scheduled_at', '<=', $now)
            ->whereNotNull('driver_id')
            ->whereNotNull('driver_amount')
            ->where('driver_amount', '>', 0)
            ->where('status', 'completed')
            ->count();

        if ($scheduledCount > 0) {
            $this->warn("   Found {$scheduledCount} scheduled payout(s) that are due but not processed yet");
            $this->line('   This might indicate the cron job is not running properly.');
        } else {
            $this->info('   No overdue scheduled payouts found');
        }

        $totalScheduled = Booking::where('driver_payout_status', Booking::DRIVER_PAYOUT_SCHEDULED)
            ->whereNotNull('driver_payout_scheduled_at')
            ->count();

        $this->line("   Total scheduled payouts: {$totalScheduled}");

        $this->newLine();

        // Check 3: Check last run time
        $this->info('3. Checking last execution time...');

        if (File::exists($logFile)) {
            $logContent = File::get($logFile);
            $logLines = explode("\n", $logContent);

            $lastRun = null;
            foreach (array_reverse($logLines) as $line) {
                if (strpos($line, 'ProcessScheduledPayouts: Cron job started') !== false) {
                    // Extract timestamp from log line
                    if (preg_match('/\[([^\]]+)\]/', $line, $matches)) {
                        $lastRun = $matches[1];
                        break;
                    }
                }
            }

            if ($lastRun) {
                $lastRunTime = Carbon::parse($lastRun);
                $minutesAgo = $lastRunTime->diffInMinutes(now());

                if ($minutesAgo <= 2) {
                    $this->info("   Last run: {$minutesAgo} minute(s) ago ({$lastRun})");
                } elseif ($minutesAgo <= 5) {
                    $this->warn("   Last run: {$minutesAgo} minute(s) ago ({$lastRun})");
                } else {
                    $this->error("   Last run: {$minutesAgo} minute(s) ago ({$lastRun})");
                    $this->line('   Cron job might not be running!');
                }
            } else {
                $this->warn('   Could not find last execution time in logs');
            }
        }

        $this->newLine();

        // Summary
        $this->info('Summary:');
        $this->line('   To verify cron job is running:');
        $this->line('   1. Check Hostinger cron jobs panel');
        $this->line('   2. Run: tail -f storage/logs/laravel.log | grep ProcessScheduledPayouts');
        $this->line('   3. Manually test: php artisan payouts:process-scheduled');

        return 0;
    }
}
