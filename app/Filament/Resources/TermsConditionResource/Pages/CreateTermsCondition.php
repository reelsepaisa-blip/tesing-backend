<?php

namespace App\Filament\Resources\TermsConditionResource\Pages;

use App\Filament\Resources\TermsConditionResource;
use App\Filament\Resources\Pages\CreateRecord;

class CreateTermsCondition extends CreateRecord
{
    protected static string $resource = TermsConditionResource::class;

    protected static bool $canCreateAnother = false;
}
