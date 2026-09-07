<?php

namespace App\Filament\Resources\PromoCodeResource\Pages;

use App\Filament\Resources\PromoCodeResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

class EditPromoCode extends EditRecord
{
    protected static string $resource = PromoCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->before(function ($record) {
                    // Block deletion for restricted users (ID 2) - check before confirmation
                    $userId = auth()->id();
                    if ($userId === 2) {
                        \Filament\Notifications\Notification::make()
                            ->title('Access Restricted')
                            ->body('In demo mode you are not deleting data...')
                            ->danger()
                            ->persistent()
                            ->send();
                        
                        // Stop the action
                        throw new \Illuminate\Validation\ValidationException(
                            \Illuminate\Support\Facades\Validator::make([], []),
                            ['demo_mode' => ['In demo mode you are not deleting data...']]
                        );
                    }
                })
                ->action(function ($record) {
                    // Proceed with normal deletion
                    $record->delete();

                    \Filament\Notifications\Notification::make()
                        ->title('Deleted')
                        ->body('The promo code has been deleted.')
                        ->success()
                        ->send();

                    return redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }
}








