<?php

namespace App\Filament\Resources\DriverSearchSettingResource\Pages;

use Illuminate\Validation\ValidationException;
use App\Filament\Resources\DriverSearchSettingResource;
use App\Models\DriverSearchSetting;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

class EditDriverSearchSetting extends EditRecord
{
    protected static string $resource = DriverSearchSettingResource::class;

    public function mount(int | string $record): void
    {
        parent::mount($record);
    }

    protected function getHeaderActions(): array
    {

        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {

        $round1 = (float) ($data['round1_radius_km'] ?? 0);
        $round2 = (float) ($data['round2_radius_km'] ?? 0);
        $round3 = (float) ($data['round3_radius_km'] ?? 0);

        if ($round1 >= $round2 || $round2 >= $round3) {
            throw new ValidationException(
                validator([], []),
                [
                    'round1_radius_km' => $round1 >= $round2 ? 'Round 1 must be less than Round 2.' : null,
                    'round2_radius_km' => $round2 >= $round3 ? 'Round 2 must be less than Round 3.' : ($round2 <= $round1 ? 'Round 2 must be greater than Round 1.' : null),
                    'round3_radius_km' => $round3 <= $round2 ? 'Round 3 must be greater than Round 2.' : null,
                ]
            );
        }

        return $data;
    }
}

