<?php

namespace App\Filament\Resources\AboutUsResource\Pages;

use App\Filament\Resources\AboutUsResource;
use App\Filament\Resources\Pages\CreateRecord;

class CreateAboutUs extends CreateRecord
{
    protected static string $resource = AboutUsResource::class;

    protected static bool $canCreateAnother = false;
}
