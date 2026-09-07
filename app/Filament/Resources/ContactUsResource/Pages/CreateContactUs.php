<?php

namespace App\Filament\Resources\ContactUsResource\Pages;

use App\Filament\Resources\ContactUsResource;
use App\Filament\Resources\Pages\CreateRecord;

class CreateContactUs extends CreateRecord
{
    protected static string $resource = ContactUsResource::class;

    protected static bool $canCreateAnother = false;
}
