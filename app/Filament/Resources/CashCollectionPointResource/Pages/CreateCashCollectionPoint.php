<?php

namespace App\Filament\Resources\CashCollectionPointResource\Pages;

use App\Filament\Resources\CashCollectionPointResource;
use Filament\Actions;
use App\Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;

class CreateCashCollectionPoint extends CreateRecord
{
    protected static string $resource = CashCollectionPointResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Cash Collection Point Created')
            ->body('The cash collection point has been created successfully.');
    }
}
