<?php

namespace App\Filament\Resources\DriverPayoutResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\DriverPayoutResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListDriverPayouts extends ListRecords
{
    protected static string $resource = DriverPayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}








