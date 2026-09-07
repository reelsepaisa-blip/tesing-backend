<?php

namespace App\Filament\Resources\PrivacyPolicyResource\Pages;

use App\Filament\Resources\PrivacyPolicyResource;
use App\Models\PrivacyPolicy;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListPrivacyPolicies extends ListRecords
{
    protected static string $resource = PrivacyPolicyResource::class;

    protected function getHeaderActions(): array
    {

        if (PrivacyPolicy::exists()) {
            return [];
        }

        return [
            Actions\CreateAction::make(),
        ];
    }
}
