<?php

namespace App\Filament\Resources\CashCollectionPointResource\Pages;

use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use App\Filament\Resources\CashCollectionPointResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;

class EditCashCollectionPoint extends EditRecord
{
    protected static string $resource = CashCollectionPointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->action(function ($record) {
                    // Block deletion for restricted users (ID 2)
                    $userId = auth()->id();
                    if ($userId === 2) {
                        Notification::make()
                            ->title('Access Restricted')
                            ->body('In demo mode you are not deleting data...')
                            ->danger()
                            ->send();
                        return;
                    }

                    // Proceed with normal deletion
                    $record->delete();

                    Notification::make()
                        ->title('Deleted')
                        ->body('The cash collection point has been deleted.')
                        ->success()
                        ->send();

                    return redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Cash Collection Point Updated')
            ->body('The cash collection point has been updated successfully.');
    }
}
