<?php

namespace App\Filament\Resources\DriverSearchSettingResource\Pages;

use App\Filament\Resources\DriverSearchSettingResource;
use App\Models\DriverSearchSetting;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Redirect;

class ListDriverSearchSettings extends ListRecords
{
    protected static string $resource = DriverSearchSettingResource::class;

    public function mount(): void
    {

        $setting = DriverSearchSetting::first();
        
        if (!$setting) {

            $setting = DriverSearchSetting::create(DriverSearchSetting::defaults());
        }
        
        $this->redirect(static::getResource()::getUrl('edit', ['record' => $setting]));
    }

    protected function getHeaderActions(): array
    {

        return [];
    }
}

