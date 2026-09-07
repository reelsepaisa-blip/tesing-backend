<?php

namespace App\Filament\Resources\DriverIncentiveResource\Pages;

use Filament\Actions\EditAction;
use App\Filament\Resources\DriverIncentiveResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ViewRecord;

class ViewDriverIncentive extends ViewRecord
{
    protected static string $resource = DriverIncentiveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
