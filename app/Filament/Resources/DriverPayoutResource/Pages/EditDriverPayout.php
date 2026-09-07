<?php

namespace App\Filament\Resources\DriverPayoutResource\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\DriverPayoutResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

class EditDriverPayout extends EditRecord
{
    protected static string $resource = DriverPayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}








