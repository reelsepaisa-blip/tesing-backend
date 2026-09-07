<?php

namespace App\Filament\Resources\WalletResource\Pages;

use App\Filament\Resources\WalletResource;
use App\Models\WalletTransaction;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;

class EditWallet extends EditRecord
{
    protected static string $resource = WalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
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
                        ->body('The wallet has been deleted.')
                        ->success()
                        ->send();

                    return redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {

        $hasAdjustment = !empty($data['adjustment_amount']) || !empty($data['adjustment_type']) || !empty($data['adjustment_description']);
        
        if ($hasAdjustment) {

            if (empty($data['adjustment_amount']) || empty($data['adjustment_type']) || empty($data['adjustment_description'])) {
                Notification::make()
                    ->title('Validation Error')
                    ->body('Please fill all required fields (Amount, Type, and Description) for the adjustment.')
                    ->danger()
                    ->send();
                
                throw new \Exception('All adjustment fields are required');
            }

            $wallet = $this->record;

            if (!$wallet->isActive()) {
                Notification::make()
                    ->title('Wallet Adjustment Failed')
                    ->body('Cannot adjust wallet balance. Wallet is not active.')
                    ->danger()
                    ->send();
                
                throw new \Exception('Wallet is not active');
            }

            $amount = (float) $data['adjustment_amount'];

            if ($amount <= 0) {
                Notification::make()
                    ->title('Invalid Amount')
                    ->body('Adjustment amount must be greater than zero.')
                    ->danger()
                    ->send();
                
                throw new \Exception('Invalid adjustment amount');
            }

            $type = $data['adjustment_type'];
            $description = $data['adjustment_description'];
            $note = $data['adjustment_note'] ?? null;

            $meta = [
                'admin_adjustment' => true,
                'adjusted_by' => auth()->id(),
                'adjusted_at' => now()->toDateTimeString(),
            ];
            
            if ($note) {
                $meta['internal_note'] = $note;
            }

            try {

                if ($type === 'credit') {
                    $wallet->credit($amount, WalletTransaction::TYPE_ADJUSTMENT, $description, $meta);
                } else {

                    if (!$wallet->hasBalance($amount)) {
                        Notification::make()
                            ->title('Insufficient Balance')
                            ->body('Cannot debit amount. Wallet balance is insufficient.')
                            ->danger()
                            ->send();
                        
                        throw new \Exception('Insufficient wallet balance');
                    }
                    $wallet->debit($amount, WalletTransaction::TYPE_ADJUSTMENT, $description, $meta);
                }

                $this->record->refresh();

                Notification::make()
                    ->title('Wallet Adjusted')
                    ->body("Wallet balance has been {$type}ed successfully.")
                    ->success()
                    ->send();
            } catch (\Exception $e) {
                Notification::make()
                    ->title('Adjustment Failed')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();
                
                throw $e;
            }
        }

        unset(
            $data['adjustment_amount'],
            $data['adjustment_type'],
            $data['adjustment_description'],
            $data['adjustment_note']
        );

        // Prevent user_id from being updated (unique constraint)
        unset($data['user_id']);

        return $data;
    }
}


