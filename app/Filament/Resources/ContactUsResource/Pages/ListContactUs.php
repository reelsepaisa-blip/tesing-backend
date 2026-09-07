<?php

namespace App\Filament\Resources\ContactUsResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\ContactUsResource;
use App\Models\ContactUs;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListContactUs extends ListRecords
{
    protected static string $resource = ContactUsResource::class;

    protected function getHeaderActions(): array
    {

        if (ContactUs::exists()) {
            return [];
        }

        return [
            CreateAction::make(),
        ];
    }
}
