<?php

namespace App\Filament\Resources\WalletTransactionResource\Pages;

use App\Filament\Resources\WalletTransactionResource;
use App\Models\WalletTransaction;
use Filament\Actions;
use Filament\Forms;
use App\Filament\Resources\Pages\EditRecord;

class EditWalletTransaction extends EditRecord
{
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
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
                        ->body('The wallet transaction has been deleted.')
                        ->success()
                        ->send();

                    return redirect($this->getResource()::getUrl('index'));
                }),

            Actions\Action::make('complete')
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->getRecord()->complete();
                    $this->refreshFormData([]);
                })
                ->visible(fn(): bool => $this->getRecord()->isPending())
                ->color('success')
                ->icon('heroicon-o-check-circle'),

            Actions\Action::make('fail')
                ->requiresConfirmation()
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Failure Reason')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->getRecord()->fail($data['reason']);
                    $this->refreshFormData([]);
                })
                ->visible(fn(): bool => $this->getRecord()->isPending())
                ->color('danger')
                ->icon('heroicon-o-x-circle'),

            Actions\Action::make('reverse')
                ->requiresConfirmation()
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Reversal Reason')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->getRecord()->reverse($data['reason']);
                    $this->refreshFormData([]);
                })
                ->visible(fn(): bool => $this->getRecord()->isCompleted())
                ->color('warning')
                ->icon('heroicon-o-arrow-path'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $originalRecord = $this->getRecord();

        if (
            isset($data['amount']) &&
            $data['amount'] != $originalRecord->amount &&
            $originalRecord->status === WalletTransaction::STATUS_COMPLETED
        ) {

            $wallet = $originalRecord->wallet;
            if ($wallet) {

                $difference = $data['amount'] - $originalRecord->amount;
                $wallet->increment('balance', $difference);

                $data['balance'] = $wallet->fresh()->balance;
            }
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {

        $record = $this->getRecord();
        if ($record->wallet) {
            $data['balance'] = $record->wallet->balance;
        }

        return $data;
    }
}
