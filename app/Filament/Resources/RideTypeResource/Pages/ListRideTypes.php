<?php

namespace App\Filament\Resources\RideTypeResource\Pages;

use App\Filament\Resources\RideTypeResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListRideTypes extends ListRecords
{
    protected static string $resource = RideTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
