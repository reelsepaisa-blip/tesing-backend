<?php

namespace App\Filament\Resources\TermsConditionResource\Pages;

use App\Filament\Resources\TermsConditionResource;
use App\Models\TermsCondition;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListTermsConditions extends ListRecords
{
    protected static string $resource = TermsConditionResource::class;

    protected function getHeaderActions(): array
    {

        if (TermsCondition::exists()) {
            return [];
        }

        return [
            Actions\CreateAction::make(),
        ];
    }
}
