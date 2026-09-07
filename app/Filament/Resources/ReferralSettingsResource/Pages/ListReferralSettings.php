<?php

namespace App\Filament\Resources\ReferralSettingsResource\Pages;

use App\Filament\Resources\ReferralSettingsResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListReferralSettings extends ListRecords
{
    protected static string $resource = ReferralSettingsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
