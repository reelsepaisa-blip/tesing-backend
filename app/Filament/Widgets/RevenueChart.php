<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class RevenueChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Revenue Overview';

    protected function getData(): array
    {
        $days = 7;
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        $dates = collect(range(0, $days - 1))
            ->map(fn($i) => $start->copy()->addDays($i)->format('Y-m-d'))
            ->all();

        $revenue = Booking::selectRaw('DATE(created_at) as date, SUM(total_amount) as total')
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $data = array_map(
            fn($date) => (float) ($revenue->get($date)?->total ?? 0),
            $dates
        );

        $labels = array_map(
            fn($date) => Carbon::parse($date)->format('M d'),
            $dates
        );

        return [
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'data' => $data,
                    'fill' => 'start',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.5)',
                    'borderColor' => 'rgb(34, 197, 94)',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
