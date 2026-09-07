<?php

namespace App\Filament\Resources\DriverDocumentResource\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\DriverDocumentResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

class EditDriverDocument extends EditRecord
{
    protected static string $resource = DriverDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}























