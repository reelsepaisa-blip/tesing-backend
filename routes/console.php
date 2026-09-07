<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('drivers:process-auto-locations --continuous')
    ->name('driver-auto-locations')
    ->withoutOverlapping()
    ->runInBackground()
    ->description('Process driver auto location updates every 10 seconds');

Schedule::command('bookings:expire-search-timeout --timeout=1.5')
    ->name('expire-search-timeout')
    ->withoutOverlapping()
    ->description('Expire bookings that have been searching for more than 90 seconds without driver acceptance');

Schedule::command('queue:work --tries=3 --timeout=60 --memory=512 --stop-when-empty --max-jobs=10')
    ->everyMinute()
    ->name('process-queue')
    ->withoutOverlapping()
    ->runInBackground()
    ->description('Process queued jobs for booking timeouts');

Schedule::command('queue:failed:clear')
    ->dailyAt('02:00')
    ->name('cleanup-failed-jobs')
    ->description('Clear failed queue jobs daily');

Schedule::command('cache:clear')
    ->dailyAt('03:00')
    ->name('cleanup-cache')
    ->description('Clear application cache daily');

Schedule::command('session:gc')
    ->dailyAt('01:00')
    ->name('cleanup-sessions')
    ->description('Clean up expired sessions daily');

Schedule::command('view:clear')
    ->weeklyOn(0, '04:00')
    ->name('cleanup-views')
    ->description('Clear compiled view files weekly');

Schedule::command('driver:check-document-deadlines')
    ->hourly()
    ->name('check-driver-document-deadlines')
    ->withoutOverlapping()
    ->description('Check driver document upload deadlines and block drivers who have not uploaded required documents');

Schedule::command('referral-bonuses:expire')
    ->hourly()
    ->name('expire-referral-bonuses')
    ->withoutOverlapping()
    ->description('Automatically expire referral bonuses that have passed their expiration date');

Schedule::command('payouts:process-scheduled')
    ->everyMinute()
    ->name('process-scheduled-payouts')
    ->withoutOverlapping()
    ->description('Process scheduled driver payouts that are past their scheduled time');

Schedule::command('bookings:process-auto-arrived --delay=30')
    ->everyTenSeconds()
    ->name('process-auto-arrived')
    ->withoutOverlapping()
    ->description('Process bookings that were auto-accepted and need to be updated to arrived status after 30 seconds');

Schedule::command('bookings:process-auto-started --delay=10')
    ->everyTenSeconds()
    ->name('process-auto-started')
    ->withoutOverlapping()
    ->description('Process bookings that were auto-arrived and need to be updated to started status after 10 seconds');

Schedule::command('bookings:process-auto-completed --delay=20')
    ->everyTenSeconds()
    ->name('process-auto-completed')
    ->withoutOverlapping()
    ->description('Process bookings that were auto-started and need to be updated to completed status after 20 seconds');

Schedule::command('bookings:update-auto-driver-locations --interval=2')
    ->everyMinute()
    ->name('update-auto-driver-locations')
    ->withoutOverlapping(75) // Allow 75 seconds overlap window (command runs for 70 seconds)
    ->runInBackground()
    ->description('Update driver locations for auto-accepted bookings every 2 seconds (stops after arrived)');

Schedule::command('bookings:update-auto-started-locations --interval=2')
    ->everyMinute()
    ->name('update-auto-started-locations')
    ->withoutOverlapping(75) // Allow 75 seconds overlap window (command runs for 70 seconds)
    ->runInBackground()
    ->description('Update driver locations for auto-started bookings every 2 seconds from pickup to destination (broadcast only, no DB save)');
