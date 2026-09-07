<?php

namespace App\Filament\Resources\CancellationPolicyResource\Pages;

use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use App\Filament\Resources\CancellationPolicyResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class EditCancellationPolicy extends EditRecord
{
    protected static string $resource = CancellationPolicyResource::class;

    protected function authorizeAccess(): void
    {
        $user = Auth::user();
        $record = $this->getRecord();
        
        // Prevent user id 2 from editing cancellation policies with id 1,2,3,4,5,6,7
        if ($user && $user->id === 2 && in_array($record->id, [1, 2, 3, 4, 5, 6, 7])) {
            abort(403, 'You do not have permission to edit this cancellation policy.');
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = Auth::user();
        $record = $this->getRecord();
        
        // Disable form if user id 2 is editing restricted records
        if ($user && $user->id === 2 && in_array($record->id, [1, 2, 3, 4, 5, 6, 7])) {
            $this->form->disabled();
        }
        
        return $data;
    }

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
                        ->body('The cancellation policy has been deleted.')
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
            ->title('Cancellation Policy Updated')
            ->body('The cancellation policy has been updated successfully.');
    }
}
