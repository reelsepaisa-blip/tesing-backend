<?php

namespace App\Filament\Resources\CashCollectionPointResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\CashCollectionPointResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListCashCollectionPoints extends ListRecords
{
    protected static string $resource = CashCollectionPointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
