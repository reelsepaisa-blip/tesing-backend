<?php

namespace App\Filament\Resources\SupportChatResource\Pages;

use App\Filament\Resources\SupportChatResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

class EditSupportChat extends EditRecord
{
    protected static string $resource = SupportChatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->action(function ($record) {
                    // Block deletion for restricted users (ID 2)
                    $userId = auth()->id();
                    if ($userId === 2) {
                        \Filament\Notifications\Notification::make()
                            ->title('Access Restricted')
                            ->body('In demo mode you are not deleting data...')
                            ->danger()
                            ->send();
                        return;
                    }
                    
                    // Proceed with normal deletion
                    $record->delete();
                    
                    \Filament\Notifications\Notification::make()
                        ->title('Deleted')
                        ->body('The record has been deleted.')
                        ->success()
                        ->send();
                    
                    return redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }
}
