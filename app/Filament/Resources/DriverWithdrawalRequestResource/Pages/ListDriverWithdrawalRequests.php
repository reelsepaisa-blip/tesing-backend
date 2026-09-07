<?php

namespace App\Filament\Resources\DriverWithdrawalRequestResource\Pages;

use App\Filament\Resources\DriverWithdrawalRequestResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListDriverWithdrawalRequests extends ListRecords
{
    protected static string $resource = DriverWithdrawalRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
