<?php

namespace App\Filament\Widgets;

use App\Models\WalletTransaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TransactionTrends extends ChartWidget
{
    protected ?string $heading = 'Transaction Trends';

    protected ?string $pollingInterval = '5m';

    protected ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $days = 14; // Show last 14 days
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        $dates = collect(range(0, $days - 1))
            ->map(fn($i) => $start->copy()->addDays($i)->format('Y-m-d'))
            ->all();

        $transactions = WalletTransaction::query()
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as credits'),
                DB::raw('SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as debits'),
                DB::raw('COUNT(DISTINCT wallet_id) as active_wallets')
            )
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $credits = array_map(
            fn($date) => round((float) ($transactions->get($date)?->credits ?? 0), 2),
            $dates
        );

        $debits = array_map(
            fn($date) => round((float) ($transactions->get($date)?->debits ?? 0), 2),
            $dates
        );

        $activeWallets = array_map(
            fn($date) => (int) ($transactions->get($date)?->active_wallets ?? 0),
            $dates
        );

        return [
            'datasets' => [
                [
                    'label' => 'Credits',
                    'data' => $credits,
                    'borderColor' => 'rgb(34, 197, 94)',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.1)',
                    'fill' => false,
                ],
                [
                    'label' => 'Debits',
                    'data' => $debits,
                    'borderColor' => 'rgb(245, 158, 11)',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => false,
                ],
                [
                    'label' => 'Active Wallets',
                    'data' => $activeWallets,
                    'borderColor' => 'rgb(99, 102, 241)',
                    'backgroundColor' => 'rgba(99, 102, 241, 0.1)',
                    'fill' => false,
                    'yAxisID' => 'wallets',
                ],
            ],
            'labels' => array_map(
                fn($date) => Carbon::parse($date)->format('M j'),
                $dates
            ),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
                'wallets' => [
                    'position' => 'right',
                    'beginAtZero' => true,
                    'grid' => [
                        'drawOnChartArea' => false,
                    ],
                ],
            ],
        ];
    }
}
