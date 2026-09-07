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

use App\Filament\Resources\WalletResource\Pages;
use App\Models\Wallet;
use App\Services\WalletService;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WalletResource extends BaseResource
{
    protected static ?string $model = Wallet::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-wallet';

    protected static string | \UnitEnum | null $navigationGroup = 'Finance Management';

    protected static ?int $navigationSort = 8;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Group::make()
                    ->schema([
                        Section::make('Wallet Information')
                            ->schema([
                                Forms\Components\Select::make('user_id')
                                    ->label('User')
                                    ->options(function () {
                                        return \App\Models\User::all()->mapWithKeys(function ($user) {
                                            $label = $user->name ?: "User #{$user->id}";
                                            if ($user->email) {
                                                $label .= " ({$user->email})";
                                            }
                                            return [$user->id => $label];
                                        });
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->disabled(fn($record) => $record !== null),

                                Forms\Components\TextInput::make('balance')
                                    ->numeric()
                                    ->prefix('₹')
                                    ->disabled(),

                                Forms\Components\Select::make('status')
                                    ->options([
                                        Wallet::STATUS_ACTIVE => 'Active',
                                        Wallet::STATUS_BLOCKED => 'Blocked',
                                        Wallet::STATUS_SUSPENDED => 'Suspended',
                                    ])
                                    ->required(),
                            ]),

                        Section::make('Transaction Summary')
                            ->schema([
                                Forms\Components\TextInput::make('total_credit')
                                    ->label('Total Credits')
                                    ->numeric()
                                    ->prefix('₹')
                                    ->disabled(),

                                Forms\Components\TextInput::make('total_debit')
                                    ->label('Total Debits')
                                    ->numeric()
                                    ->prefix('₹')
                                    ->disabled(),

                                Forms\Components\DateTimePicker::make('last_transaction_at')
                                    ->label('Last Transaction')
                                    ->disabled(),
                            ]),

                        Section::make('Quick Actions')
                            ->schema([
                                Forms\Components\TextInput::make('adjustment_amount')
                                    ->label('Amount')
                                    ->numeric()
                                    ->prefix('₹')
                                    ->helperText('Leave empty if not making an adjustment'),

                                Forms\Components\Select::make('adjustment_type')
                                    ->label('Type')
                                    ->options([
                                        'credit' => 'Credit (Add)',
                                        'debit' => 'Debit (Subtract)',
                                    ])
                                    ->helperText('Select adjustment type'),

                                Forms\Components\TextInput::make('adjustment_description')
                                    ->label('Description')
                                    ->helperText('Description for this adjustment'),

                                Forms\Components\Textarea::make('adjustment_note')
                                    ->label('Internal Note')
                                    ->rows(2)
                                    ->helperText('Optional internal note for this adjustment'),
                            ])
                            ->collapsed()
                            ->hiddenOn('create'),
                    ])
                    ->columnSpan(['lg' => 2]),

                Group::make()
                    ->schema([
                        Section::make('Recent Transactions')
                            ->schema([
                                Forms\Components\Placeholder::make('transactions')
                                    ->content(
                                        fn(?Wallet $record) => $record
                                            ? view('filament.resources.wallet.recent-transactions', ['wallet' => $record])
                                            : 'No transactions yet. Create the wallet first.'
                                    ),
                            ])
                            ->hiddenOn('create'),

                        Section::make('Status History')
                            ->schema([
                                Forms\Components\ViewField::make('status_history')
                                    ->view('filament.resources.wallet.status-history'),
                            ])
                            ->collapsed()
                            ->hiddenOn('create'),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->formatStateUsing(fn($state) => auth()->id() === 2 ? 'xxx' : $state)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.role_id')
                    ->label('Type')
                    ->formatStateUsing(function($state) {
                        if (auth()->id() === 2) {
                            return 'xxx';
                        }
                        return match ($state) {
                            2 => 'Driver',
                            3 => 'User',
                            default => 'Admin',
                        };
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance')
                    ->formatStateUsing(function($state) {
                        if (auth()->id() === 2) {
                            return 'xxx';
                        }
                        return '₹' . number_format((float)$state, 2);
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_credit')
                    ->label('Credits')
                    ->formatStateUsing(function($state) {
                        if (auth()->id() === 2) {
                            return 'xxx';
                        }
                        return '₹' . number_format((float)$state, 2);
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_debit')
                    ->label('Debits')
                    ->formatStateUsing(function($state) {
                        if (auth()->id() === 2) {
                            return 'xxx';
                        }
                        return '₹' . number_format((float)$state, 2);
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn($state) => auth()->id() === 2 ? 'xxx' : $state)
                    ->color(fn(string $state): string => match ($state) {
                        'active' => 'success',
                        'blocked' => 'danger',
                        'suspended' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_transaction_at')
                    ->label('Last Transaction')
                    ->formatStateUsing(fn($state) => auth()->id() === 2 ? 'xxx' : $state)
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'blocked' => 'Blocked',
                        'suspended' => 'Suspended',
                    ]),

                Tables\Filters\SelectFilter::make('user_type')
                    ->label('User Type')
                    ->options([
                        '2' => 'Drivers',
                        '3' => 'Users',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'],
                            fn(Builder $query, $value): Builder => $query->whereHas(
                                'user',
                                fn(Builder $query) => $query->where('role_id', $value)
                            )
                        );
                    }),

                Tables\Filters\Filter::make('has_balance')
                    ->query(fn(Builder $query): Builder => $query->where('balance', '>', 0)),

                Tables\Filters\Filter::make('no_transactions')
                    ->query(fn(Builder $query): Builder => $query->whereNull('last_transaction_at')),
            ])
            ->actions([
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
                            ->body('The wallet has been deleted.')
                            ->success()
                            ->send();
                    }),
                 Action::make('transactions')
                    ->label('View Transactions')
                    ->icon('heroicon-o-banknotes')
                    ->url(fn(Wallet $record) => \App\Filament\Resources\WalletTransactionResource::getUrl('index') . '?wallet=' . $record->id)
                    ->color('success'),
                 ActionGroup::make([
                     Action::make('block')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Block Reason')
                                ->required(),
                        ])
                        ->action(function (Wallet $record, array $data): void {
                            $record->block($data['reason']);
                        })
                        ->visible(fn(Wallet $record) => $record->isActive())
                        ->color('danger'),

                     Action::make('unblock')
                        ->requiresConfirmation()
                        ->action(function (Wallet $record): void {
                            $record->unblock();
                        })
                        ->visible(fn(Wallet $record) => $record->isBlocked())
                        ->color('success'),

                     Action::make('suspend')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Suspension Reason')
                                ->required(),
                        ])
                        ->action(function (Wallet $record, array $data): void {
                            $record->suspend($data['reason']);
                        })
                        ->visible(fn(Wallet $record) => $record->isActive())
                        ->color('warning'),

                     Action::make('unsuspend')
                        ->requiresConfirmation()
                        ->action(function (Wallet $record): void {
                            $record->unsuspend();
                        })
                        ->visible(fn(Wallet $record) => $record->isSuspended())
                        ->color('success'),
                ]),
            ])
            ->bulkActions([
                 BulkActionGroup::make([
                     BulkAction::make('block')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Block Reason')
                                ->required(),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data): void {
                            foreach ($records as $record) {
                                if ($record->isActive()) {
                                    $record->block($data['reason']);
                                }
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                     BulkAction::make('suspend')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Suspension Reason')
                                ->required(),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data): void {
                            foreach ($records as $record) {
                                if ($record->isActive()) {
                                    $record->suspend($data['reason']);
                                }
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('last_transaction_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWallets::route('/'),
            'edit' => Pages\EditWallet::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::whereIn('status', ['blocked', 'suspended'])->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::whereIn('status', ['blocked', 'suspended'])->exists() ? 'warning' : null;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['id'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['user']);
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        
        $userName = $record->user ? $record->user->name : 'N/A';
        return "Wallet #{$record->id} - {$userName}";
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        
        $details = [];

        if ($record->user) {
            $details['User'] = $record->user->name;
        }

        $details['Balance'] = '₹' . number_format($record->balance ?? 0, 2);
        $details['Status'] = ucfirst($record->status ?? 'active');

        return $details;
    }

    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        parent::applyGlobalSearchAttributeConstraints($query, $search);

        foreach (explode(' ', $search) as $searchWord) {
            $query->orWhere(function (Builder $query) use ($searchWord) {

                $query->whereHas('user', function (Builder $query) use ($searchWord) {
                    $query->where('name', 'like', "%{$searchWord}%")
                        ->orWhere('email', 'like', "%{$searchWord}%")
                        ->orWhere('phone', 'like', "%{$searchWord}%");
                });
            });
        }
    }
}
