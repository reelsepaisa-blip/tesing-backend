<?php

namespace App\Filament\Pages;

use App\Models\City;
use App\Models\RideType;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class DriverTracking extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-map';
    protected static string | \UnitEnum | null $navigationGroup = 'Driver Management';
    protected string $view = 'filament.pages.driver-tracking';
    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {

        if (Auth::id() === 1) {
            return true;
        }

        if (!Auth::check()) {
            return false;
        }

        $user = Auth::user();

        if (isset($user->role_id) && $user->role_id === 1) {
            return true;
        }



        return true;
    }

    public function getViewData(): array
    {
        return [
            'cities' => City::pluck('name', 'id'),
            'services' => RideType::pluck('name', 'id'),
            'statuses' => [
                'available' => 'Available',
                'on_job' => 'On Job',
                'offline' => 'Offline',
            ],
            'mapKey' => 'AIzaSyDTSKbF3tjgvdx4oCPcJ7Fc-PhwCugkZm4',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getScripts(): array
    {
        return [
            'https://maps.googleapis.com/maps/api/js?key=AIzaSyDTSKbF3tjgvdx4oCPcJ7Fc-PhwCugkZm4&libraries=places,drawing,geometry',
        ];
    }

    public function getHeading(): string
    {
        return 'Driver Live Tracking';
    }
}
