<?php

namespace App\Filament\Widgets;

use App\Models\SupportTicket;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class TicketTrends extends ChartWidget
{
    protected ?string $heading = 'Ticket Trends';

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

        $tickets = SupportTicket::query()
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = "resolved" THEN 1 ELSE 0 END) as resolved')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $created = array_map(
            fn($date) => (int) ($tickets->get($date)?->total ?? 0),
            $dates
        );

        $resolved = array_map(
            fn($date) => (int) ($tickets->get($date)?->resolved ?? 0),
            $dates
        );

        return [
            'datasets' => [
                [
                    'label' => 'New Tickets',
                    'data' => $created,
                    'borderColor' => 'rgb(245, 158, 11)',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => false,
                ],
                [
                    'label' => 'Resolved Tickets',
                    'data' => $resolved,
                    'borderColor' => 'rgb(34, 197, 94)',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.1)',
                    'fill' => false,
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
}



