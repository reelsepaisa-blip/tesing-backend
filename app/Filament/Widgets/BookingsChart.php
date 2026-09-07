<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class BookingsChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Bookings Overview';

    protected function getData(): array
    {
        $days = 7;
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        $dates = collect(range(0, $days - 1))
            ->map(fn($i) => $start->copy()->addDays($i)->format('Y-m-d'))
            ->all();

        $bookings = Booking::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $data = array_map(
            fn($date) => (int) ($bookings->get($date)?->count ?? 0),
            $dates
        );

        $labels = array_map(
            fn($date) => Carbon::parse($date)->format('M d'),
            $dates
        );

        return [
            'datasets' => [
                [
                    'label' => 'Bookings',
                    'data' => $data,
                    'fill' => 'start',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.5)',
                    'borderColor' => 'rgb(59, 130, 246)',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
