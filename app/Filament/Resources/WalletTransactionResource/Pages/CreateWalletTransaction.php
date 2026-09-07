<?php

namespace App\Filament\Resources\WalletTransactionResource\Pages;

use App\Filament\Resources\WalletTransactionResource;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use App\Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateWalletTransaction extends CreateRecord
{
    protected static string $resource = WalletTransactionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {

        if (!isset($data['balance']) || $data['balance'] === null) {
            $wallet = \App\Models\Wallet::find($data['wallet_id']);
            if ($wallet) {
                $data['balance'] = $wallet->balance + $data['amount'];
            }
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $wallet = \App\Models\Wallet::find($data['wallet_id']);

            if (!$wallet) {
                throw new \Exception('Wallet not found');
            }

            $transaction = static::getModel()::create($data);

            if ($transaction->status === WalletTransaction::STATUS_COMPLETED) {
                $wallet->increment('balance', $transaction->amount);
                $wallet->update(['last_transaction_at' => now()]);

                if ($transaction->amount > 0) {
                    $wallet->increment('total_credit', $transaction->amount);
                } else {
                    $wallet->increment('total_debit', abs($transaction->amount));
                }
            }

            return $transaction;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
