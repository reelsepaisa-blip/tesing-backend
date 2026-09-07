<?php

namespace App\Filament\Resources\RiderResource\Pages;

use App\Filament\Resources\RiderResource;
use App\Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRider extends CreateRecord
{
    protected static string $resource = RiderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {

        $data['role_id'] = 3;

        if (!isset($data['status'])) {
            $data['status'] = 'active';
        }

        if (!isset($data['is_online'])) {
            $data['is_online'] = false;
        }

        if (!isset($data['is_verified'])) {
            $data['is_verified'] = false;
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $record = static::getModel()::create($data);

        $record->assignRole('user');

        if (empty($record->referral_code)) {
            $record->generateReferralCode();
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}




