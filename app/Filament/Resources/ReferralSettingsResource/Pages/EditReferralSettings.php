<?php

namespace App\Filament\Resources\ReferralSettingsResource\Pages;

use App\Filament\Resources\ReferralSettingsResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

class EditReferralSettings extends EditRecord
{
    protected static string $resource = ReferralSettingsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
