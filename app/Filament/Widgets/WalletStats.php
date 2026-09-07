<?php

namespace App\Filament\Widgets;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class WalletStats extends BaseWidget
{
    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $wallets = Wallet::query();
        $transactions = WalletTransaction::query();

        $totalBalance = $wallets->sum('balance');
        $blockedBalance = $wallets->where('status', '!=', 'active')->sum('balance');

        $todayCredits = $transactions->clone()
            ->whereDate('created_at', today())
            ->where('amount', '>', 0)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->sum('amount');

        $todayDebits = (float) ($transactions->clone()
            ->whereDate('created_at', today())
            ->where('amount', '<', 0)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->selectRaw('COALESCE(SUM(ABS(amount)), 0) as total')
            ->value('total') ?? 0);

        $days = 7;
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        $dates = collect(range(0, $days - 1))
            ->map(fn($i) => $start->copy()->addDays($i)->format('Y-m-d'))
            ->all();

        $trends = $transactions->clone()
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(CASE WHEN amount > 0 AND status = "' . WalletTransaction::STATUS_COMPLETED . '" THEN amount ELSE 0 END) as credits'),
                DB::raw('SUM(CASE WHEN amount < 0 AND status = "' . WalletTransaction::STATUS_COMPLETED . '" THEN ABS(amount) ELSE 0 END) as debits')
            )
            ->whereBetween('created_at', [$start, $end])
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $creditTrend = array_map(
            fn($date) => (float) ($trends->get($date)?->credits ?? 0),
            $dates
        );

        $debitTrend = array_map(
            fn($date) => (float) ($trends->get($date)?->debits ?? 0),
            $dates
        );

        return [
            Stat::make('Total Balance', '₹' . number_format((float) $totalBalance, 2))
                ->description($blockedBalance > 0 ? '₹' . number_format((float) $blockedBalance, 2) . ' blocked' : '')
                ->descriptionIcon($blockedBalance > 0 ? 'heroicon-m-lock-closed' : null)
                ->chart($creditTrend)
                ->color('success'),

            Stat::make("Today's Credits", '₹' . number_format((float) $todayCredits, 2))
                ->description('From ' . $transactions->clone()
                    ->whereDate('created_at', today())
                    ->where('amount', '>', 0)
                    ->where('status', WalletTransaction::STATUS_COMPLETED)
                    ->count() . ' transactions')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart($creditTrend)
                ->color('info'),

            Stat::make("Today's Debits", '₹' . number_format((float) $todayDebits, 2))
                ->description('From ' . $transactions->clone()
                    ->whereDate('created_at', today())
                    ->where('amount', '<', 0)
                    ->where('status', WalletTransaction::STATUS_COMPLETED)
                    ->count() . ' transactions')
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->chart($debitTrend)
                ->color('warning'),
        ];
    }
}
