<?php

namespace App\Filament\Resources\DriverMatchingSettingResource\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\DriverMatchingSettingResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

class EditDriverMatchingSetting extends EditRecord
{
    protected static string $resource = DriverMatchingSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
