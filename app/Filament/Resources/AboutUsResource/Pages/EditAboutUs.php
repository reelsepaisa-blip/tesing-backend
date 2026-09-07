<?php

namespace App\Filament\Resources\AboutUsResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use App\Filament\Resources\AboutUsResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

class EditAboutUs extends EditRecord
{
    protected static string $resource = AboutUsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->action(function ($record) {
                    // Block deletion for restricted users (ID 2)
                    $userId = auth()->id();
                    if ($userId === 2) {
                        Notification::make()
                            ->title('Access Restricted')
                            ->body('In demo mode you are not deleting data...')
                            ->danger()
                            ->persistent()
                            ->send();
                        return false;
                    }

                    // Proceed with normal deletion
                    $record->delete();

                    Notification::make()
                        ->title('Deleted')
                        ->body('The about us content has been deleted.')
                        ->success()
                        ->send();

                    return redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }
}
