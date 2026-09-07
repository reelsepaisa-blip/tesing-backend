<?php

namespace App\Filament\Resources\ReferralBonusResource\Pages;

use App\Filament\Resources\ReferralBonusResource;
use App\Models\ReferralBonus;
use App\Models\WalletTransaction;
use App\Services\PromoService;
use App\Services\WalletService;
use App\Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateReferralBonus extends CreateRecord
{
    protected static string $resource = ReferralBonusResource::class;

    protected function afterCreate(): void
    {
        $record = $this->record;

        if ($record->status === ReferralBonus::STATUS_CREDITED) {
            $this->creditBonusToWallet($record);
        }
    }

    protected function creditBonusToWallet(ReferralBonus $bonus): void
    {

        if ($bonus->transaction) {
            return;
        }

        if (!$bonus->credited_at) {
            $bonus->update(['credited_at' => now()]);
        }

        DB::transaction(function () use ($bonus) {

            $recipient = match ($bonus->type) {
                'referrer_bonus' => $bonus->referrer,
                'referred_bonus' => $bonus->referred,
            };

            if (!$recipient) {
                return;
            }

            $walletService = app(WalletService::class);
            $wallet = $walletService->ensureWallet($recipient);

            if (!$wallet->isActive()) {
                return;
            }

            $transaction = $wallet->credit(
                (float) $bonus->amount,
                WalletTransaction::TYPE_REFERRAL_BONUS,
                "Referral bonus - {$bonus->type}",
                [
                    'referral_bonus_id' => $bonus->id,
                    'credited_at' => now()->toDateTimeString(),
                ]
            );

            $wallet->refresh();
            $transaction->update([
                'reference_type' => get_class($bonus),
                'reference_id' => $bonus->id,
                'balance' => $wallet->balance, // Ensure balance is current
            ]);

            if ($bonus->type === 'referrer_bonus' && $bonus->referrer) {
                $promoService = app(PromoService::class);

                $reflection = new \ReflectionClass($promoService);
                $method = $reflection->getMethod('maybeAwardTierBonus');
                $method->setAccessible(true);
                $method->invoke($promoService, $bonus->referrer);
            }
        });
    }
}








