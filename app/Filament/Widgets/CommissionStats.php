<?php

namespace App\Filament\Widgets;

use App\Models\Commission;
use App\Models\DriverPayout;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class CommissionStats extends BaseWidget
{
    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $commissions = Commission::query();
        $payouts = DriverPayout::query();

        $todayCommissions = $commissions->clone()
            ->whereDate('created_at', today())
            ->selectRaw('
                COUNT(*) as total_count,
                SUM(total_fare) as total_fare,
                SUM(commission_amount) as total_commission,
                SUM(tax_amount) as total_tax,
                SUM(driver_amount) as total_driver_amount
            ')
            ->first();

        $days = 7;
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        $dates = collect(range(0, $days - 1))
            ->map(fn($i) => $start->copy()->addDays($i)->format('Y-m-d'))
            ->all();

        $trends = $commissions->clone()
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(commission_amount) as commission'),
                DB::raw('SUM(driver_amount) as earnings')
            )
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $commissionTrend = array_map(
            fn($date) => (float) ($trends->get($date)?->commission ?? 0),
            $dates
        );

        $earningsTrend = array_map(
            fn($date) => (float) ($trends->get($date)?->earnings ?? 0),
            $dates
        );

        $pendingPayouts = $payouts->clone()
            ->where('status', 'pending')
            ->selectRaw('COUNT(*) as count, SUM(amount) as amount')
            ->first();

        return [
            Stat::make("Today's Commission", '₹' . number_format($todayCommissions->total_commission ?? 0, 2))
                ->description('From ' . ($todayCommissions->total_count ?? 0) . ' rides')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->chart($commissionTrend)
                ->color('success'),

            Stat::make("Today's Driver Earnings", '₹' . number_format($todayCommissions->total_driver_amount ?? 0, 2))
                ->description('After commission & tax')
                ->descriptionIcon('heroicon-m-banknotes')
                ->chart($earningsTrend)
                ->color('info'),

            Stat::make('Pending Payouts', '₹' . number_format($pendingPayouts->amount ?? 0, 2))
                ->description(($pendingPayouts->count ?? 0) . ' requests pending')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
        ];
    }
}
