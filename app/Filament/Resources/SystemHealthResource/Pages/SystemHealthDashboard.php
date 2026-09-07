<?php

namespace App\Filament\Resources\SystemHealthResource\Pages;

use App\Filament\Resources\SystemHealthResource;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

class SystemHealthDashboard extends Page
{
    protected static string $resource = SystemHealthResource::class;

    protected string $view = 'filament.resources.system-health.pages.dashboard';

    protected static ?string $title = 'System Health Dashboard';

    public function getMaxWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function getTitle(): string|Htmlable
    {
        return 'System Health Dashboard';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh Data')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->action(function () {

                    \Illuminate\Support\Facades\Cache::forget('system_health_metrics');
                    \Illuminate\Support\Facades\Cache::forget('critical_issues_count');

                    Notification::make()
                        ->title('Data Refreshed')
                        ->body('System health data has been refreshed.')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('emergency_actions')
                ->label('Emergency Actions')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->form([
                    Forms\Components\Select::make('action')
                        ->label('Emergency Action')
                        ->options([
                            'cancel_stuck_bookings' => 'Cancel All Stuck Bookings',
                            'retry_failed_payments' => 'Retry Failed Payments (Last Hour)',
                            'clear_failed_jobs' => 'Clear All Failed Jobs',
                            'reset_driver_availability' => 'Reset Driver Availability Status',
                        ])
                        ->required(),
                    Forms\Components\Textarea::make('reason')
                        ->label('Reason for Emergency Action')
                        ->required()
                        ->maxLength(500),
                ])
                ->action(function (array $data) {
                    $this->executeEmergencyAction($data['action'], $data['reason']);
                }),
        ];
    }


    public function getSystemMetrics(): array
    {
        return SystemHealthResource::getSystemHealthMetrics();
    }


    public function getCriticalIssues(): array
    {
        $issues = [];
        $metrics = $this->getSystemMetrics();

        if ($metrics['failed_jobs'] > 0) {
            $issues[] = [
                'type' => 'Failed Jobs',
                'count' => $metrics['failed_jobs'],
                'severity' => 'high',
                'description' => 'Background jobs have failed and need attention.',
                'action_url' => '/admin/failed-jobs',
            ];
        }

        if ($metrics['stuck_bookings'] > 0) {
            $issues[] = [
                'type' => 'Stuck Bookings',
                'count' => $metrics['stuck_bookings'],
                'severity' => 'critical',
                'description' => 'Bookings stuck in searching state for over 10 minutes.',
                'action_url' => '/admin/bookings?tableFilters[status][values][0]=searching',
            ];
        }

        if ($metrics['failed_transactions_today'] > 5) {
            $issues[] = [
                'type' => 'Failed Transactions',
                'count' => $metrics['failed_transactions_today'],
                'severity' => 'high',
                'description' => 'High number of failed transactions today.',
                'action_url' => '/admin/transactions?tableFilters[status][values][0]=failed',
            ];
        }

        if ($metrics['pending_support_tickets'] > 10) {
            $issues[] = [
                'type' => 'Support Backlog',
                'count' => $metrics['pending_support_tickets'],
                'severity' => 'medium',
                'description' => 'High number of pending support tickets.',
                'action_url' => '/admin/support-tickets?tableFilters[status][values][0]=open',
            ];
        }

        return $issues;
    }


    public function getRecentEvents(): array
    {
        $events = [];

        $failedBookings = Booking::where('status', 'cancelled')
            ->where('cancelled_by_type', 'system')
            ->where('created_at', '>', now()->subHours(24))
            ->limit(5)
            ->get();

        foreach ($failedBookings as $booking) {
            $events[] = [
                'type' => 'booking_cancelled',
                'title' => "Booking #{$booking->booking_code} cancelled by system",
                'description' => $booking->cancellation_reason ?? 'No reason provided',
                'timestamp' => $booking->cancelled_at,
                'severity' => 'warning',
            ];
        }

        $failedTransactions = Transaction::where('status', 'failed')
            ->where('created_at', '>', now()->subHours(24))
            ->limit(5)
            ->get();

        foreach ($failedTransactions as $transaction) {
            $events[] = [
                'type' => 'transaction_failed',
                'title' => "Transaction #{$transaction->transaction_id} failed",
                'description' => $transaction->description,
                'timestamp' => $transaction->created_at,
                'severity' => 'error',
            ];
        }

        usort($events, function ($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });

        return array_slice($events, 0, 10);
    }


    protected function executeEmergencyAction(string $action, string $reason): void
    {
        try {
            switch ($action) {
                case 'cancel_stuck_bookings':
                    $this->cancelStuckBookings($reason);
                    break;
                case 'retry_failed_payments':
                    $this->retryFailedPayments($reason);
                    break;
                case 'clear_failed_jobs':
                    $this->clearFailedJobs($reason);
                    break;
                case 'reset_driver_availability':
                    $this->resetDriverAvailability($reason);
                    break;
            }

            Notification::make()
                ->title('Emergency Action Completed')
                ->body("Emergency action '{$action}' has been executed successfully.")
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Emergency Action Failed')
                ->body("Failed to execute emergency action: {$e->getMessage()}")
                ->danger()
                ->send();
        }
    }


    protected function cancelStuckBookings(string $reason): void
    {
        $stuckBookings = Booking::where('status', 'searching')
            ->where('created_at', '<', now()->subMinutes(10))
            ->get();

        foreach ($stuckBookings as $booking) {
            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by_type' => 'admin',
                'cancelled_by_id' => auth()->id(),
                'cancellation_reason' => "Emergency cancellation: {$reason}",
            ]);
        }

    }


    protected function retryFailedPayments(string $reason): void
    {
        $failedTransactions = Transaction::where('status', 'failed')
            ->where('created_at', '>', now()->subHour())
            ->get();

        $paymentService = app(\App\Services\PaymentGatewayService::class);
        $retryCount = 0;

        foreach ($failedTransactions as $transaction) {
            if ($transaction->booking) {
                try {
                    $result = $paymentService->processPayment(
                        $transaction->booking,
                        [
                            'amount' => abs($transaction->amount),
                            'payment_method' => $transaction->payment_method,
                        ]
                    );

                    if ($result['success']) {
                        $transaction->update(['status' => 'completed']);
                        $retryCount++;
                    }
                } catch (\Exception $e) {

                }
            }
        }

    }


    protected function clearFailedJobs(string $reason): void
    {
        $count = DB::table('failed_jobs')->count();
        DB::table('failed_jobs')->delete();

    }


    protected function resetDriverAvailability(string $reason): void
    {
        $count = DB::table('users')
            ->where('role_id', 2) // Assuming 2 is driver role
            ->where('is_online', true)
            ->where('last_location_at', '<', now()->subMinutes(30))
            ->update(['is_online' => false]);

    }
}
