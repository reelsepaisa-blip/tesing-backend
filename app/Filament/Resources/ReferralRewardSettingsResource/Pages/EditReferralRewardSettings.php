<?php

namespace App\Filament\Resources\ReferralRewardSettingsResource\Pages;

use App\Filament\Resources\ReferralRewardSettingsResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

class EditReferralRewardSettings extends EditRecord
{
    protected static string $resource = ReferralRewardSettingsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
