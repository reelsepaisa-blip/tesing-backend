<?php

namespace App\Filament\Resources\RiderResource\Pages;

use App\Filament\Resources\RiderResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

class ViewRider extends ViewRecord
{
    protected static string $resource = RiderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Rider Information')
                    ->schema([
                        Infolists\Components\ImageEntry::make('profile_photo')
                            ->label('Profile Photo')
                            ->circular()
                            ->defaultImageUrl(function ($record) {
                                return 'https://ui-avatars.com/api/?name=' . urlencode($record->name) . '&color=f97316&background=1a1a1a';
                            }),
                        Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('name')
                                    ->label('Full Name')
                                    ->icon('heroicon-o-user')
                                    ->formatStateUsing(function ($state) {
                                        if (auth()->check() && auth()->id() === 2 && $state) {
                                            $length = strlen($state);
                                            if ($length <= 5) {
                                                return str_repeat('x', $length);
                                            }
                                            return substr($state, 0, $length - 5) . str_repeat('x', 5);
                                        }
                                        return $state;
                                    }),
                                Infolists\Components\TextEntry::make('email')
                                    ->label('Email')
                                    ->icon('heroicon-o-envelope')
                                    ->formatStateUsing(function ($state) {
                                        if (auth()->check() && auth()->id() === 2 && $state) {
                                            $length = strlen($state);
                                            if ($length <= 5) {
                                                return str_repeat('x', $length);
                                            }
                                            return substr($state, 0, $length - 5) . str_repeat('x', 5);
                                        }
                                        return $state;
                                    }),
                                Infolists\Components\TextEntry::make('phone')
                                    ->label('Phone')
                                    ->icon('heroicon-o-phone')
                                    ->formatStateUsing(function ($state) {
                                        if (auth()->check() && auth()->id() === 2 && $state) {
                                            $length = strlen($state);
                                            if ($length <= 5) {
                                                return str_repeat('x', $length);
                                            }
                                            return substr($state, 0, $length - 5) . str_repeat('x', 5);
                                        }
                                        return $state;
                                    }),
                                Infolists\Components\TextEntry::make('country_code')
                                    ->label('Country Code'),
                                Infolists\Components\TextEntry::make('gender')
                                    ->label('Gender')
                                    ->badge()
                                    ->color(fn(string $state): string => match ($state) {
                                        'male' => 'info',
                                        'female' => 'success',
                                        default => 'gray',
                                    }),
                                Infolists\Components\TextEntry::make('address')
                                    ->label('Address')
                                    ->icon('heroicon-o-map-pin')
                                    ->hidden()
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columns(1),

                Section::make('Status & Activity')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('status')
                                    ->badge()
                                    ->color(fn(string $state): string => match ($state) {
                                        'active' => 'success',
                                        'inactive' => 'gray',
                                        'blocked' => 'danger',
                                        'under_review' => 'warning',
                                        default => 'gray',
                                    }),
                                Infolists\Components\IconEntry::make('is_online')
                                    ->label('Online Status')
                                    ->boolean(),
                                Infolists\Components\IconEntry::make('is_verified')
                                    ->label('Verified')
                                    ->boolean(),
                            ]),
                    ]),

                Section::make('Referral Information')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('referral_code')
                                    ->label('Referral Code')
                                    ->copyable()
                                    ->copyMessage('Referral code copied'),
                                Infolists\Components\TextEntry::make('referred_by')
                                    ->hidden()
                                    ->label('Referred By (User ID)'),
                                Infolists\Components\TextEntry::make('referrals_count')
                                    ->state(fn($record) => $record->referrals()->count())
                                    ->label('Total Referrals')
                                    ->badge()
                                    ->color('success'),
                            ]),
                    ])
                    ->collapsible(),

                Section::make('Booking Statistics')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('bookings_count')
                                    ->state(fn($record) => $record->bookingsAsUser()->count())
                                    ->label('Total Bookings')
                                    ->badge()
                                    ->color('info'),
                                Infolists\Components\TextEntry::make('completed_bookings_count')
                                    ->state(fn($record) => $record->bookingsAsUser()->where('status', 'completed')->count())
                                    ->label('Completed Bookings')
                                    ->badge()
                                    ->color('success'),
                                Infolists\Components\TextEntry::make('cancelled_bookings_count')
                                    ->state(fn($record) => $record->bookingsAsUser()->where('status', 'cancelled')->count())
                                    ->label('Cancelled Bookings')
                                    ->badge()
                                    ->color('danger'),
                                Infolists\Components\TextEntry::make('average_rating')
                                    ->state(function ($record) {
                                        $avgRating = $record->bookingsAsUser()
                                            ->where('status', 'completed')
                                            ->whereNotNull('user_rating')
                                            ->where('user_rating', '>', 0)
                                            ->avg('user_rating');
                                        return $avgRating ? round((float) $avgRating, 1) : '0.0';
                                    })
                                    ->label('Average Rating')
                                    ->badge()
                                    ->color('warning')
                                    ->icon('heroicon-o-star'),
                            ]),
                    ])
                    ->collapsible(),

                Section::make('Wallet Information')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('wallet.balance')
                                    ->label('Wallet Balance')
                                    ->money('INR')
                                    ->color('success'),
                                Infolists\Components\TextEntry::make('wallet.updated_at')
                                    ->label('Last Transaction')
                                    ->dateTime(),
                            ]),
                    ])
                    ->collapsible(),

                Section::make('Additional Information')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('date_of_birth')
                                    ->label('Date of Birth')
                                    ->date()
                                    ->hidden(),
                                Infolists\Components\TextEntry::make('device_token')
                                    ->label('Device Token')
                                    ->copyable()
                                    ->columnSpanFull()
                                    ->hidden()
                                    ->html()
                                    ->formatStateUsing(fn($state) => $state
                                        ? '<code style="word-break: break-all; white-space: pre-wrap; font-family: monospace; display: block;">' . htmlspecialchars($state) . '</code>'
                                        : '<span class="text-gray-400">Not Provided</span>'),
                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Joined Date')
                                    ->dateTime(),
                                Infolists\Components\TextEntry::make('updated_at')
                                    ->label('Last Updated')
                                    ->dateTime(),
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}
