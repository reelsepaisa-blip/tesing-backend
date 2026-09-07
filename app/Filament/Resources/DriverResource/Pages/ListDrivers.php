<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListDrivers extends ListRecords
{
    protected static string $resource = DriverResource::class;

    protected static ?string $title = 'Drivers';

    public function getTitle(): string
    {
        return 'Drivers';
    }

    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
