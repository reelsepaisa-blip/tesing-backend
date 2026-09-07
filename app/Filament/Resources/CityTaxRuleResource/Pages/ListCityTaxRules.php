<?php

namespace App\Filament\Resources\CityTaxRuleResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\CityTaxRuleResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListCityTaxRules extends ListRecords
{
    protected static string $resource = CityTaxRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
