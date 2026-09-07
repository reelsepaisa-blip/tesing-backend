<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Group;

use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;

use App\Filament\Resources\WalletTransactionResource\Pages;
use App\Models\WalletTransaction;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WalletTransactionResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';

    protected static string | \UnitEnum | null $navigationGroup = 'Finance Management';

    protected static ?int $navigationSort = 8;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    protected static ?string $navigationLabel = 'Wallet Transactions';

    protected static ?string $pluralModelLabel = 'Wallet Transactions';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Transaction Information')
                    ->schema([
                        Forms\Components\Select::make('wallet_id')
                            ->relationship('wallet', 'id', fn(Builder $query) => $query->with('user')->whereHas('user'))
                            ->getOptionLabelFromRecordUsing(fn($record) => "Wallet #{$record->id} - " . ($record->user?->name ?? 'No User'))
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\Select::make('type')
                            ->options([
                                WalletTransaction::TYPE_BOOKING_PAYMENT => 'Booking Payment',
                                WalletTransaction::TYPE_BOOKING_REFUND => 'Booking Refund',
                                WalletTransaction::TYPE_DRIVER_PAYOUT => 'Driver Payout',
                                WalletTransaction::TYPE_DRIVER_COMMISSION => 'Driver Commission',
                                WalletTransaction::TYPE_WALLET_TOPUP => 'Wallet Top-up',
                                WalletTransaction::TYPE_WALLET_WITHDRAWAL => 'Wallet Withdrawal',
                                WalletTransaction::TYPE_REFERRAL_BONUS => 'Referral Bonus',
                                WalletTransaction::TYPE_PROMO_CREDIT => 'Promo Credit',
                                WalletTransaction::TYPE_ADJUSTMENT => 'Adjustment',
                            ])
                            ->required(),

                        Forms\Components\TextInput::make('amount')
                            ->numeric()
                            ->prefix('₹')
                            ->required()
                            ->step(0.01),


                        Forms\Components\TextInput::make('balance')
                            ->numeric()
                            ->prefix('₹')
                            ->disabled()
                            ->helperText('Wallet balance after this transaction'),

                        Forms\Components\Select::make('status')
                            ->options([
                                WalletTransaction::STATUS_PENDING => 'Pending',
                                WalletTransaction::STATUS_COMPLETED => 'Completed',
                                WalletTransaction::STATUS_FAILED => 'Failed',
                                WalletTransaction::STATUS_REVERSED => 'Reversed',
                            ])
                            ->required()
                            ->default(WalletTransaction::STATUS_COMPLETED),

                        Forms\Components\Textarea::make('description')
                            ->required()
                            ->maxLength(65535)
                            ->rows(3),
                    ])
                    ->columns(2),

                Section::make('Reference Information')
                    ->schema([
                        Forms\Components\TextInput::make('reference_type')
                            ->maxLength(255)
                            ->placeholder('e.g., App\Models\Booking')
                            ->helperText('The model class that this transaction references'),

                        Forms\Components\TextInput::make('reference_id')
                            ->numeric()
                            ->placeholder('e.g., 123')
                            ->helperText('The ID of the referenced model'),
                    ])
                    ->columns(2)
                    ->collapsed(),

                Section::make('Additional Information')
                    ->schema([
                        Forms\Components\KeyValue::make('meta_data')
                            ->keyLabel('Field')
                            ->valueLabel('Value')
                            ->addable()
                            ->deletable()
                            ->reorderable(),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('user_name')
                    ->label('User')
                    ->getStateUsing(fn(WalletTransaction $record) => $record->wallet && $record->wallet->user ? $record->wallet->user->name : 'N/A')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('wallet.user', function (Builder $query) use ($search) {
                            $query->where('name', 'like', "%{$search}%");
                        });
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->join('wallets', 'wallet_transactions.wallet_id', '=', 'wallets.id')
                            ->join('users', 'wallets.user_id', '=', 'users.id')
                            ->orderBy('users.name', $direction);
                    })
                    ->url(fn(WalletTransaction $record) => $record->wallet && $record->wallet->user ? \App\Filament\Resources\UserResource::getUrl('edit', ['record' => $record->wallet->user]) : null)
                    ->color('primary'),

                Tables\Columns\TextColumn::make('wallet.id')
                    ->label('Wallet')
                    ->formatStateUsing(fn($state) => "Wallet #{$state}")
                    ->url(fn(WalletTransaction $record) => $record->wallet ? \App\Filament\Resources\WalletResource::getUrl('edit', ['record' => $record->wallet]) : null)
                    ->color('primary'),

                Tables\Columns\TextColumn::make('type')
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

                Tables\Columns\TextColumn::make('amount')
                    ->money('INR')
                    ->sortable()
                    ->color(fn($state) => $state >= 0 ? 'success' : 'danger')
                    ->icon(fn($state) => $state >= 0 ? 'heroicon-o-arrow-up' : 'heroicon-o-arrow-down'),

                Tables\Columns\TextColumn::make('balance')
                    ->money('INR')
                    ->sortable()
                    ->label('Balance After'),

                Tables\Columns\TextColumn::make('description')
                    ->searchable()
                    ->wrap()
                    ->limit(50)
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        $state = $column->getState();
                        return strlen($state) > 50 ? $state : null;
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        WalletTransaction::STATUS_PENDING => 'warning',
                        WalletTransaction::STATUS_COMPLETED => 'success',
                        WalletTransaction::STATUS_FAILED => 'danger',
                        WalletTransaction::STATUS_REVERSED => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('reference_type')
                    ->label('Reference')
                    ->formatStateUsing(fn($state) => $state ? class_basename($state) : 'N/A')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('wallet')
                    ->relationship('wallet', 'id', fn(Builder $query) => $query->with('user')->whereHas('user'))
                    ->getOptionLabelFromRecordUsing(fn($record) => "Wallet #{$record->id} - " . ($record->user?->name ?? 'No User'))
                    ->searchable()
                    ->preload()
                    ->multiple(),

                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        WalletTransaction::TYPE_BOOKING_PAYMENT => 'Booking Payment',
                        WalletTransaction::TYPE_BOOKING_REFUND => 'Booking Refund',
                        WalletTransaction::TYPE_DRIVER_PAYOUT => 'Driver Payout',
                        WalletTransaction::TYPE_DRIVER_COMMISSION => 'Driver Commission',
                        WalletTransaction::TYPE_WALLET_TOPUP => 'Wallet Top-up',
                        WalletTransaction::TYPE_WALLET_WITHDRAWAL => 'Wallet Withdrawal',
                        WalletTransaction::TYPE_REFERRAL_BONUS => 'Referral Bonus',
                        WalletTransaction::TYPE_PROMO_CREDIT => 'Promo Credit',
                        WalletTransaction::TYPE_ADJUSTMENT => 'Adjustment',
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        WalletTransaction::STATUS_PENDING => 'Pending',
                        WalletTransaction::STATUS_COMPLETED => 'Completed',
                        WalletTransaction::STATUS_FAILED => 'Failed',
                        WalletTransaction::STATUS_REVERSED => 'Reversed',
                    ])
                    ->multiple(),

                Tables\Filters\Filter::make('amount_range')
                    ->form([
                        Forms\Components\TextInput::make('amount_from')
                            ->label('Amount From')
                            ->numeric()
                            ->prefix('₹'),
                        Forms\Components\TextInput::make('amount_to')
                            ->label('Amount To')
                            ->numeric()
                            ->prefix('₹'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['amount_from'],
                                fn(Builder $query, $amount): Builder => $query->where('amount', '>=', $amount),
                            )
                            ->when(
                                $data['amount_to'],
                                fn(Builder $query, $amount): Builder => $query->where('amount', '<=', $amount),
                            );
                    }),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('From Date'),
                        Forms\Components\DatePicker::make('until')
                            ->label('Until Date'),
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
                 EditAction::make(),
                 DeleteAction::make()
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
                    }),
                 ActionGroup::make([
                     Action::make('complete')
                        ->requiresConfirmation()
                        ->action(function (WalletTransaction $record): void {
                            $record->complete();
                        })
                        ->visible(fn(WalletTransaction $record) => $record->isPending())
                        ->color('success')
                        ->icon('heroicon-o-check-circle'),

                     Action::make('fail')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Failure Reason')
                                ->required(),
                        ])
                        ->action(function (WalletTransaction $record, array $data): void {
                            $record->fail($data['reason']);
                        })
                        ->visible(fn(WalletTransaction $record) => $record->isPending())
                        ->color('danger')
                        ->icon('heroicon-o-x-circle'),

                     Action::make('reverse')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Reversal Reason')
                                ->required(),
                        ])
                        ->action(function (WalletTransaction $record, array $data): void {
                            $record->reverse($data['reason']);
                        })
                        ->visible(fn(WalletTransaction $record) => $record->isCompleted())
                        ->color('warning')
                        ->icon('heroicon-o-arrow-path'),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->size('sm')
                    ->color('gray'),
            ])
            ->bulkActions([
                 BulkActionGroup::make([
                     DeleteBulkAction::make()
                        ->action(function ($records) {
                            // Block deletion for restricted users (ID 2)
                            $userId = auth()->id();
                            if ($userId === 2) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Access Restricted')
                                    ->body('In demo mode you are not deleting data...')
                                    ->danger()
                                    ->persistent()
                                    ->send();
                                return;
                            }
                            
                            // Default bulk delete behavior
                            foreach ($records as $record) {
                                $record->delete();
                            }
                            
                            \Filament\Notifications\Notification::make()
                                ->title('Deleted')
                                ->body(count($records) . ' wallet transaction(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWalletTransactions::route('/'),
            'create' => Pages\CreateWalletTransaction::route('/create'),
            'view' => Pages\ViewWalletTransaction::route('/{record}'),
            'edit' => Pages\EditWalletTransaction::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['wallet.user']);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'description', 'type'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        
        $userName = $record->wallet && $record->wallet->user ? $record->wallet->user->name : 'N/A';
        $type = match ($record->type) {
            WalletTransaction::TYPE_BOOKING_PAYMENT => 'Booking Payment',
            WalletTransaction::TYPE_BOOKING_REFUND => 'Booking Refund',
            WalletTransaction::TYPE_DRIVER_PAYOUT => 'Driver Payout',
            WalletTransaction::TYPE_DRIVER_COMMISSION => 'Driver Commission',
            WalletTransaction::TYPE_WALLET_TOPUP => 'Wallet Top-up',
            WalletTransaction::TYPE_WALLET_WITHDRAWAL => 'Wallet Withdrawal',
            WalletTransaction::TYPE_REFERRAL_BONUS => 'Referral Bonus',
            WalletTransaction::TYPE_PROMO_CREDIT => 'Promo Credit',
            WalletTransaction::TYPE_ADJUSTMENT => 'Adjustment',
            default => ucfirst(str_replace('_', ' ', $record->type)),
        };

        return "Transaction #{$record->id} - {$type} - {$userName}";
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        
        $details = [];

        if ($record->description) {
            $details['Description'] = Str::limit($record->description, 60);
        }

        if ($record->wallet && $record->wallet->user) {
            $details['User'] = $record->wallet->user->name;
        }

        $details['Amount'] = '₹' . number_format($record->amount, 2);
        $details['Status'] = ucfirst($record->status);

        return $details;
    }

    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        
        $databaseConnection = $query->getConnection();

        $search = \Filament\Support\generate_search_term_expression($search, static::isGlobalSearchForcedCaseInsensitive(), $databaseConnection);

        foreach (explode(' ', $search) as $searchWord) {
            $query->where(function (Builder $query) use ($searchWord) {
                $isFirst = true;

                foreach (static::getGloballySearchableAttributes() as $attributes) {
                    parent::applyGlobalSearchAttributeConstraint(
                        query: $query,
                        search: $searchWord,
                        searchAttributes: \Illuminate\Support\Arr::wrap($attributes),
                        isFirst: $isFirst,
                    );
                    $isFirst = false;
                }


                $query->orWhere('wallet_transactions.wallet_id', 'like', "%{$searchWord}%")

                    ->orWhereHas('wallet.user', function (Builder $query) use ($searchWord) {
                        $query->where('name', 'like', "%{$searchWord}%")
                            ->orWhere('email', 'like', "%{$searchWord}%");
                    });
            });
        }
    }
}
