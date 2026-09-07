<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Models\Booking;
use App\Models\Vehicle;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('Total Users', User::where('role_id', 3)->count())
                ->description('Active users in the platform')
                ->descriptionIcon('heroicon-m-user')
                ->chart([7, 3, 4, 5, 6, 3, 5, 3])
                ->color('success'),

            Stat::make('Active Drivers', User::where('role_id', 2)->where('status', 'active')->count())
                ->description('Currently available drivers')
                ->descriptionIcon('heroicon-m-truck')
                ->chart([3, 5, 7, 4, 5, 3, 5, 4])
                ->color('warning'),

            Stat::make('Today\'s Bookings', Booking::whereDate('created_at', today())->count())
                ->description('Bookings made today')
                ->descriptionIcon('heroicon-m-calendar')
                ->chart([2, 4, 6, 8, 5, 3, 5, 4])
                ->color('primary'),

            Stat::make('Active Vehicles', Vehicle::where('status', 'active')->count())
                ->description('Vehicles in service')
                ->descriptionIcon('heroicon-m-truck')
                ->chart([4, 6, 8, 7, 5, 3, 5, 4])
                ->color('info'),
        ];
    }
}
