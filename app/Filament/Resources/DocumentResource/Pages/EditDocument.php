<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\DocumentResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
