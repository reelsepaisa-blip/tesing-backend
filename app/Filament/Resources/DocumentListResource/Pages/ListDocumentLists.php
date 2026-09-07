<?php

namespace App\Filament\Resources\DocumentListResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\DocumentListResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListDocumentLists extends ListRecords
{
    protected static string $resource = DocumentListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
