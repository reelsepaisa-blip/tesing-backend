<?php

namespace App\Filament\Resources\DriverDocumentResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\DriverDocumentResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListDriverDocuments extends ListRecords
{
    protected static string $resource = DriverDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}










