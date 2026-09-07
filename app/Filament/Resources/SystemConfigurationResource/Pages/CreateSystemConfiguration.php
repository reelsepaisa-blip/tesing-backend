<?php

namespace App\Filament\Resources\SystemConfigurationResource\Pages;

use App\Filament\Resources\SystemConfigurationResource;
use App\Filament\Resources\Pages\CreateRecord;

class CreateSystemConfiguration extends CreateRecord
{
    protected static string $resource = SystemConfigurationResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Configuration created successfully';
    }
}
