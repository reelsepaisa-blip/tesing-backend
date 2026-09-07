<?php

namespace App\Filament\Resources\CancellationPolicyResource\Pages;

use App\Filament\Resources\CancellationPolicyResource;
use Filament\Actions;
use App\Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;

class CreateCancellationPolicy extends CreateRecord
{
    protected static string $resource = CancellationPolicyResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Cancellation Policy Created')
            ->body('The cancellation policy has been created successfully.');
    }
}
