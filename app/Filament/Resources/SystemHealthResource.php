<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;

use App\Filament\Resources\SystemHealthResource\Pages;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\User;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;

class SystemHealthResource extends Resource
{
    protected static ?string $model = null;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-heart';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static ?string $navigationLabel = 'System Health';

    protected static ?string $pluralModelLabel = 'System Health Monitor';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(

                \App\Models\User::query()->whereRaw('1 = 0') // Empty result set
            )
            ->columns([
                Tables\Columns\TextColumn::make('metric')
                    ->label('System Metric')
                    ->state('System Status'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state('Operational')
                    ->color('success'),
                Tables\Columns\TextColumn::make('value')
                    ->label('Current Value')
                    ->state('All systems operational'),
            ])
            ->headerActions([
                 Action::make('refresh_system_status')
                    ->label('Refresh Status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->action(function () {
                        Cache::forget('system_health_metrics');
                        return redirect()->back();
                    }),

                 Action::make('clear_failed_jobs')
                    ->label('Clear Failed Jobs')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Clear All Failed Jobs')
                    ->modalDescription('This will permanently remove all failed job records.')
                    ->action(function () {
                        DB::table('failed_jobs')->delete();
                        \Filament\Notifications\Notification::make()
                            ->title('Failed Jobs Cleared')
                            ->body('All failed job records have been cleared.')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                 ActionGroup::make([
                     Action::make('view_failed_jobs')
                        ->label('View Failed Jobs')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->color('danger')
                        ->url(fn() => route('filament.admin.resources.failed-jobs.index')),

                     Action::make('view_stuck_bookings')
                        ->label('View Stuck Bookings')
                        ->icon('heroicon-o-clock')
                        ->color('warning')
                        ->action(function () {
                            return redirect()->to('/admin/bookings?tableFilters[status][values][0]=searching&tableFilters[status][values][1]=pending');
                        }),

                     Action::make('view_failed_payments')
                        ->label('View Failed Payments')
                        ->icon('heroicon-o-credit-card')
                        ->color('danger')
                        ->action(function () {
                            return redirect()->to('/admin/transactions?tableFilters[status][values][0]=failed');
                        }),
                ])->label('Quick Actions'),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSystemHealth::route('/'),
            'dashboard' => Pages\SystemHealthDashboard::route('/dashboard'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $criticalIssues = static::getCriticalIssuesCount();
        return $criticalIssues > 0 ? (string) $criticalIssues : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $criticalIssues = static::getCriticalIssuesCount();
        return $criticalIssues > 0 ? 'danger' : null;
    }

    
    protected static function getCriticalIssuesCount(): int
    {
        return Cache::remember('critical_issues_count', 300, function () {
            $issues = 0;

            $failedJobs = DB::table('failed_jobs')->count();
            if ($failedJobs > 0) $issues++;

            $stuckBookings = Booking::where('status', 'searching')
                ->where('created_at', '<', now()->subMinutes(10))
                ->count();
            if ($stuckBookings > 0) $issues++;

            $failedTransactions = Transaction::where('status', 'failed')
                ->where('created_at', '>', now()->subDay())
                ->count();
            if ($failedTransactions > 5) $issues++;

            $expectedOnlineDrivers = User::drivers()
                ->where('is_online', true)
                ->where('last_location_at', '<', now()->subMinutes(15))
                ->count();
            if ($expectedOnlineDrivers > 10) $issues++;

            return $issues;
        });
    }

    
    public static function getSystemHealthMetrics(): array
    {
        return Cache::remember('system_health_metrics', 300, function () {
            return [
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'stuck_bookings' => Booking::where('status', 'searching')
                    ->where('created_at', '<', now()->subMinutes(10))
                    ->count(),
                'failed_transactions_today' => Transaction::where('status', 'failed')
                    ->whereDate('created_at', today())
                    ->count(),
                'offline_drivers' => User::drivers()
                    ->where('last_location_at', '<', now()->subMinutes(15))
                    ->count(),
                'pending_support_tickets' => \App\Models\SupportTicket::where('status', 'open')
                    ->count(),
                'active_bookings' => Booking::whereIn('status', ['accepted', 'arrived', 'started'])
                    ->count(),
                'online_drivers' => User::drivers()
                    ->where('is_online', true)
                    ->where('last_location_at', '>', now()->subMinutes(5))
                    ->count(),
                'queue_size' => Queue::size(),
            ];
        });
    }
}
