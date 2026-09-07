<?php

namespace App\Filament\Resources\WalletTransactionResource\Pages;

use App\Filament\Resources\WalletTransactionResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListWalletTransactions extends ListRecords
{
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [

        ];
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();

        if (request()->has('wallet')) {
            $walletId = request()->get('wallet');
            $query->where('wallet_id', $walletId);
        }

        return $query->with(['wallet.user']);
    }

    public function getTitle(): string
    {
        if (request()->has('wallet')) {
            $walletId = request()->get('wallet');
            return "Wallet #{$walletId} Transactions";
        }

        return 'Wallet Transactions';
    }

    protected function getHeaderWidgets(): array
    {
        return [

        ];
    }
}
