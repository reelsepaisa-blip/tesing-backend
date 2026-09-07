<?php

namespace App\Filament\Resources\PrivacyPolicyResource\Pages;

use App\Filament\Resources\PrivacyPolicyResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

class EditPrivacyPolicy extends EditRecord
{
    protected static string $resource = PrivacyPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->action(function ($record) {
                    // Block deletion for restricted users (ID 2)
                    $userId = auth()->id();
                    if ($userId === 2) {
                        \Filament\Notifications\Notification::make()
                            ->title('Access Restricted')
                            ->body('In demo mode you are not deleting data...')
                            ->danger()
                            ->persistent()
                            ->send();
                        return false;
                    }

                    // Proceed with normal deletion
                    $record->delete();

                    \Filament\Notifications\Notification::make()
                        ->title('Deleted')
                        ->body('The privacy policy has been deleted.')
                        ->success()
                        ->send();

                    return redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }
}
