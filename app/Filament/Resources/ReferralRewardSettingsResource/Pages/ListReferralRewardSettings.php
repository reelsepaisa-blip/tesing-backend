<?php

namespace App\Filament\Resources\ReferralRewardSettingsResource\Pages;

use App\Filament\Resources\ReferralRewardSettingsResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListReferralRewardSettings extends ListRecords
{
    protected static string $resource = ReferralRewardSettingsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
