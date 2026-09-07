<?php

namespace App\Filament\Resources\ReferralResource\Pages;

use App\Filament\Resources\ReferralResource;
use App\Models\WalletTransaction;
use Filament\Actions;
use App\Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;

class ViewReferral extends ViewRecord
{
    protected static string $resource = ReferralResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Referral Information')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('name')
                                    ->label('Name')
                                    ->icon('heroicon-o-user'),
                                Infolists\Components\TextEntry::make('email')
                                    ->label('Email')
                                    ->icon('heroicon-o-envelope')
                                    ->copyable(),
                                Infolists\Components\TextEntry::make('phone')
                                    ->label('Phone')
                                    ->icon('heroicon-o-phone')
                                    ->copyable(),
                                Infolists\Components\TextEntry::make('referral_code')
                                    ->label('Referral Code')
                                    ->copyable()
                                    ->copyMessage('Referral code copied'),
                                Infolists\Components\TextEntry::make('referrer.name')
                                    ->label('Referrer')
                                    ->icon('heroicon-o-user-group')
                                    ->placeholder('Not referred by anyone'),
                            ]),
                    ]),

                Section::make('Referral Statistics')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('total_referrals')
                                    ->label('Total Referrals')
                                    ->state(fn($record) => $record->referrals()->count())
                                    ->badge()
                                    ->color('info'),
                                Infolists\Components\TextEntry::make('successful_referrals')
                                    ->label('Successful Referrals')
                                    ->state(fn($record) => $record->referrals()->whereNotNull('phone_verified_at')->count())
                                    ->badge()
                                    ->color('success'),
                                Infolists\Components\TextEntry::make('total_earnings')
                                    ->label('Total Earnings')
                                    ->state(fn($record) => '₹' . number_format($record->transactions()
                                        ->where('type', WalletTransaction::TYPE_REFERRAL_BONUS)
                                        ->sum('amount'), 2))
                                    ->badge()
                                    ->color('success'),
                            ]),
                    ]),
            ]);
    }
}
