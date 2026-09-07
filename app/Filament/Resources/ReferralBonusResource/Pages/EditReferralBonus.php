<?php

namespace App\Filament\Resources\ReferralBonusResource\Pages;

use App\Filament\Resources\ReferralBonusResource;
use App\Models\ReferralBonus;
use App\Models\WalletTransaction;
use App\Services\PromoService;
use App\Services\WalletService;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;


class EditReferralBonus extends EditRecord
{
    protected static string $resource = ReferralBonusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $record = $this->record;
        $originalStatus = $record->getOriginal('status');
        $newStatus = $record->status;

        if ($originalStatus === $newStatus) {
            return;
        }

        if ($newStatus === ReferralBonus::STATUS_CREDITED) {

            if ($originalStatus !== ReferralBonus::STATUS_CREDITED) {
                $this->creditBonusToWallet($record);
            }
        } elseif (in_array($newStatus, [ReferralBonus::STATUS_EXPIRED, ReferralBonus::STATUS_CANCELLED])) {

            if ($originalStatus === ReferralBonus::STATUS_CREDITED && $record->transaction) {
                $this->reverseBonusTransaction($record, $newStatus);
            }
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

    protected function reverseBonusTransaction(ReferralBonus $bonus, string $newStatus): void
    {
        $transaction = $bonus->transaction;

        if (!$transaction) {
            return;
        }

        if (!$transaction->isCompleted()) {
            return;
        }

        $reason = match ($newStatus) {
            ReferralBonus::STATUS_EXPIRED => 'Referral bonus expired',
            ReferralBonus::STATUS_CANCELLED => $bonus->cancelled_reason ?? 'Referral bonus cancelled',
            default => 'Referral bonus status changed',
        };

        DB::transaction(function () use ($transaction, $reason) {
            try {

                $transaction->reverse($reason);
            } catch (\Exception $e) {
                // Error handling
            }
        });
    }
}
