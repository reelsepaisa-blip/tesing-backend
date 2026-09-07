<?php

namespace App\Filament\Resources\CityResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use App\Filament\Resources\CityResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditCity extends EditRecord
{
    protected static string $resource = CityResource::class;

    protected function authorizeAccess(): void
    {
        $user = Auth::user();
        $record = $this->getRecord();


        if ($user && $user->id === 2 && $record->id === 1) {
            abort(403, 'You do not have permission to edit this city.');
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = Auth::user();
        $record = $this->getRecord();

        if ($user && $user->id === 2 && $record->id === 1) {
            $this->form->disabled();
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->action(function ($record) {
                    $userId = auth()->id();
                    if ($userId === 2) {
                        Notification::make()
                            ->title('Access Restricted')
                            ->body('In demo mode you are not deleting data...')
                            ->danger()
                            ->send();
                        return;
                    }

                    $record->delete();

                    Notification::make()
                        ->title('Deleted')
                        ->body('The city has been deleted.')
                        ->success()
                        ->send();

                    return redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }
}
