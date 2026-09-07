<?php

namespace App\Filament\Resources\DriverResource\Pages;

use Filament\Actions\EditAction;
use App\Filament\Resources\DriverResource;
use App\Models\Document;
use App\Models\DocumentList;
use Filament\Actions;
use Filament\Forms;
use App\Filament\Resources\Pages\ViewRecord;
use Filament\Notifications\Notification;

class ViewDriver extends ViewRecord
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
          ];
    }
}
