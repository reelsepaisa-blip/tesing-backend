<?php

namespace App\Filament\Resources\DriverIncentiveResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\DriverIncentiveResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListDriverIncentives extends ListRecords
{
    protected static string $resource = DriverIncentiveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

