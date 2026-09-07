<?php

namespace App\Filament\Resources\ReferralBonusResource\Pages;

use App\Filament\Resources\ReferralBonusResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListReferralBonuses extends ListRecords
{
    protected static string $resource = ReferralBonusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}








