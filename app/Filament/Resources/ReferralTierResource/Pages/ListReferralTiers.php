<?php

namespace App\Filament\Resources\ReferralTierResource\Pages;

use App\Filament\Resources\ReferralTierResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListReferralTiers extends ListRecords
{
    protected static string $resource = ReferralTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
