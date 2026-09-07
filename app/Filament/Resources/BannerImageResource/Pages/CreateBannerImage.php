<?php

namespace App\Filament\Resources\BannerImageResource\Pages;

use App\Filament\Resources\BannerImageResource;
use Filament\Notifications\Notification;
use App\Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateBannerImage extends CreateRecord
{
    protected static string $resource = BannerImageResource::class;

    protected int $createdCitiesCount = 1;

    protected function handleRecordCreation(array $data): Model
    {
        $cityIds = $data['city_ids'] ?? [];
        $cityIds = is_array($cityIds) ? $cityIds : [$cityIds];
        $cityIds = array_values(array_filter(array_unique($cityIds)));

        if (empty($cityIds)) {
            throw ValidationException::withMessages([
                'city_ids' => 'Please select at least one city.',
            ]);
        }

        unset($data['city_ids']);

        $this->createdCitiesCount = count($cityIds);
        $firstCityId = array_shift($cityIds);

        $data['city_id'] = $firstCityId;
        $record = static::getModel()::create($data);

        foreach ($cityIds as $cityId) {
            $duplicateData = $data;
            $duplicateData['city_id'] = $cityId;

            static::getModel()::create($duplicateData);
        }

        return $record;
    }

    protected function getCreatedNotification(): ?Notification
    {
        $target = $this->createdCitiesCount > 1
            ? "{$this->createdCitiesCount} cities"
            : 'the selected city';

        return Notification::make()
            ->success()
            ->title('Banner Image Created')
            ->body("Banner image has been created for {$target}.");
    }
}
