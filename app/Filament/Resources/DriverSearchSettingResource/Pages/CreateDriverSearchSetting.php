<?php

namespace App\Filament\Resources\DriverSearchSettingResource\Pages;

use App\Filament\Resources\DriverSearchSettingResource;
use App\Models\DriverSearchSetting;
use App\Filament\Resources\Pages\CreateRecord;

class CreateDriverSearchSetting extends CreateRecord
{
    protected static string $resource = DriverSearchSettingResource::class;

    public function mount(): void
    {

        $existing = DriverSearchSetting::first();
        if ($existing) {
            $this->redirect(static::getResource()::getUrl('edit', ['record' => $existing]));
        }
    }
    
    protected function mutateFormDataBeforeCreate(array $data): array
    {

        DriverSearchSetting::query()->delete();
        
        return $data;
    }
}

