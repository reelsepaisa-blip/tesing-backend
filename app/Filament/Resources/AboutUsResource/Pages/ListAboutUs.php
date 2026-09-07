<?php

namespace App\Filament\Resources\AboutUsResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\AboutUsResource;
use App\Models\AboutUs;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListAboutUs extends ListRecords
{
    protected static string $resource = AboutUsResource::class;

    protected function getHeaderActions(): array
    {

        if (AboutUs::exists()) {
            return [];
        }

        return [
            CreateAction::make(),
        ];
    }
}
