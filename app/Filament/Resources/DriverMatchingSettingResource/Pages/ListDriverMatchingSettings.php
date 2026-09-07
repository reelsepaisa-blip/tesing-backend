<?php

namespace App\Filament\Resources\DriverMatchingSettingResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\DriverMatchingSettingResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListDriverMatchingSettings extends ListRecords
{
    protected static string $resource = DriverMatchingSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

