<?php

namespace App\Filament\Resources\WalletTransactionResource\Pages;

use App\Filament\Resources\WalletTransactionResource;
use App\Models\WalletTransaction;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Schemas\Schema;
use App\Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\FontWeight;

class ViewWalletTransaction extends ViewRecord
{
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

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

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Transaction Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('id')
                            ->label('Transaction ID')
                            ->weight(FontWeight::Bold),

                        Infolists\Components\TextEntry::make('user_name')
                            ->getStateUsing(fn(WalletTransaction $record) => $record->wallet && $record->wallet->user ? $record->wallet->user->name : 'N/A')
                            ->label('User')
                            ->url(fn(WalletTransaction $record) => $record->wallet && $record->wallet->user ? \App\Filament\Resources\UserResource::getUrl('edit', ['record' => $record->wallet->user]) : null)
                            ->color('primary'),

                        Infolists\Components\TextEntry::make('wallet.id')
                            ->label('Wallet')
                            ->formatStateUsing(fn($state) => "Wallet #{$state}")
                            ->url(fn(WalletTransaction $record) => $record->wallet ? \App\Filament\Resources\WalletResource::getUrl('edit', ['record' => $record->wallet]) : null)
                            ->color('primary'),

                        Infolists\Components\TextEntry::make('type')
                            ->badge()
                            ->formatStateUsing(fn(string $state): string => match ($state) {
                                WalletTransaction::TYPE_BOOKING_PAYMENT => 'Booking Payment',
                                WalletTransaction::TYPE_BOOKING_REFUND => 'Booking Refund',
                                WalletTransaction::TYPE_DRIVER_PAYOUT => 'Driver Payout',
                                WalletTransaction::TYPE_DRIVER_COMMISSION => 'Driver Commission',
                                WalletTransaction::TYPE_WALLET_TOPUP => 'Wallet Top-up',
                                WalletTransaction::TYPE_WALLET_WITHDRAWAL => 'Wallet Withdrawal',
                                WalletTransaction::TYPE_REFERRAL_BONUS => 'Referral Bonus',
                                WalletTransaction::TYPE_PROMO_CREDIT => 'Promo Credit',
                                WalletTransaction::TYPE_ADJUSTMENT => 'Adjustment',
                                default => ucfirst(str_replace('_', ' ', $state)),
                            })
                            ->color(fn(string $state): string => match ($state) {
                                WalletTransaction::TYPE_BOOKING_PAYMENT => 'danger',
                                WalletTransaction::TYPE_BOOKING_REFUND => 'success',
                                WalletTransaction::TYPE_DRIVER_PAYOUT => 'danger',
                                WalletTransaction::TYPE_DRIVER_COMMISSION => 'warning',
                                WalletTransaction::TYPE_WALLET_TOPUP => 'success',
                                WalletTransaction::TYPE_WALLET_WITHDRAWAL => 'danger',
                                WalletTransaction::TYPE_REFERRAL_BONUS => 'success',
                                WalletTransaction::TYPE_PROMO_CREDIT => 'success',
                                WalletTransaction::TYPE_ADJUSTMENT => 'info',
                                default => 'gray',
                            }),

                        Infolists\Components\TextEntry::make('amount')
                            ->money('INR')
                            ->color(fn($state) => $state >= 0 ? 'success' : 'danger')
                            ->icon(fn($state) => $state >= 0 ? 'heroicon-o-arrow-up' : 'heroicon-o-arrow-down')
                            ->weight(FontWeight::Bold),

                        Infolists\Components\TextEntry::make('balance')
                            ->money('INR')
                            ->label('Balance After Transaction'),

                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn(string $state): string => match ($state) {
                                WalletTransaction::STATUS_PENDING => 'warning',
                                WalletTransaction::STATUS_COMPLETED => 'success',
                                WalletTransaction::STATUS_FAILED => 'danger',
                                WalletTransaction::STATUS_REVERSED => 'info',
                                default => 'gray',
                            }),
                    ])
                    ->columns(2),

                \Filament\Schemas\Components\Section::make('Description')
                    ->schema([
                        Infolists\Components\TextEntry::make('description')
                            ->prose()
                            ->hiddenLabel(),
                    ]),

                \Filament\Schemas\Components\Section::make('Reference Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('reference_type')
                            ->label('Reference Type')
                            ->formatStateUsing(fn($state) => $state ? class_basename($state) : 'N/A'),

                        Infolists\Components\TextEntry::make('reference_id')
                            ->label('Reference ID'),
                    ])
                    ->columns(2)
                    ->visible(fn(WalletTransaction $record) => $record->reference_type || $record->reference_id),

                \Filament\Schemas\Components\Section::make('Metadata')
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('meta_data')
                            ->hiddenLabel(),
                    ])
                    ->visible(fn(WalletTransaction $record) => !empty($record->meta_data))
                    ->collapsed(),

                \Filament\Schemas\Components\Section::make('Timestamps')
                    ->schema([
                        Infolists\Components\TextEntry::make('created_at')
                            ->dateTime()
                            ->label('Created'),

                        Infolists\Components\TextEntry::make('updated_at')
                            ->dateTime()
                            ->label('Last Updated'),

                        Infolists\Components\TextEntry::make('deleted_at')
                            ->dateTime()
                            ->label('Deleted')
                            ->visible(fn(WalletTransaction $record) => $record->deleted_at),
                    ])
                    ->columns(3)
                    ->collapsed(),
            ]);
    }
}
