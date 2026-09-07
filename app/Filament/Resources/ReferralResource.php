<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Section;

use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;

use App\Filament\Resources\ReferralResource\Pages;
use App\Filament\Resources\ReferralResource\RelationManagers;
use App\Models\User;
use App\Models\WalletTransaction;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ReferralResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-group';

    protected static string | \UnitEnum | null $navigationGroup = 'Marketing';

    protected static ?string $navigationLabel = 'Referral Network';

    protected static ?int $navigationSort = 6;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(function ($query) {
                $query->whereNotNull('referral_code')
                    ->orWhereNotNull('referred_by');
            });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Referral Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->required()
                            ->maxLength(20),
                        Forms\Components\TextInput::make('referral_code')
                            ->maxLength(255),
                        Forms\Components\Select::make('referred_by')
                            ->relationship('referrer', 'name')
                            ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                            ->searchable()
                            ->preload(),
                    ])->columns(2),

                Section::make('Referral Statistics')
                    ->schema([
                        Forms\Components\Placeholder::make('total_referrals')
                            ->label('Total Referrals')
                            ->content(fn($record) => $record->referrals()->count()),
                        Forms\Components\Placeholder::make('successful_referrals')
                            ->label('Successful Referrals')
                            ->content(fn($record) => $record->referrals()->whereNotNull('phone_verified_at')->count()),
                        Forms\Components\Placeholder::make('total_earnings')
                            ->label('Total Earnings')
                            ->content(fn($record) => '₹' . number_format($record->transactions()
                                ->where('type', WalletTransaction::TYPE_REFERRAL_BONUS)
                                ->sum('amount'), 2)),
                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('role_id')
                    ->label('User Type')
                    ->formatStateUsing(fn($state): string => match($state) {
                        2 => 'Driver',
                        3 => 'User',
                        default => 'Unknown',
                    })
                    ->badge()
                    ->color(fn($state): string => match($state) {
                        2 => 'warning', // Driver
                        3 => 'success', // User
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('referral_code')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Referral code copied'),
                Tables\Columns\TextColumn::make('referrer.name')
                    ->label('Referred By')
                    ->searchable(),
                Tables\Columns\TextColumn::make('referrals_count')
                    ->counts('referrals')
                    ->label('Total Referrals')
                    ->sortable(),
                Tables\Columns\TextColumn::make('active_referrals_count')
                    ->counts('referrals', fn(Builder $query) => $query->whereNotNull('phone_verified_at'))
                    ->label('Active Referrals')
                    ->hidden()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role_id')
                    ->label('User Type')
                    ->options([
                        2 => 'Driver',
                        3 => 'User',
                    ]),
                Tables\Filters\Filter::make('has_referrals')
                    ->query(fn(Builder $query): Builder => $query->has('referrals')),
                Tables\Filters\Filter::make('is_referred')
                    ->query(fn(Builder $query): Builder => $query->whereNotNull('referred_by')),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                 ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ReferralsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferrals::route('/'),
            'view' => Pages\ViewReferral::route('/{record}'),
        ];
    }
}
