<?php

namespace App\Filament\Resources\ZoneResource\Pages;

use App\Filament\Resources\ZoneResource;
use App\Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class MapZone extends ViewRecord
{
    protected static string $resource = ZoneResource::class;

    protected string $view = 'filament.resources.zone-resource.pages.zone-map';

    public function getViewData(): array
    {
        return [
            'record' => $this->record,
        ];
    }
}
