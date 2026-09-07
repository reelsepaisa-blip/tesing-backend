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

use App\Filament\Resources\TransactionResource\Pages;
use App\Filament\Resources\TransactionResource\RelationManagers;
use App\Models\Transaction;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;


class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-currency-rupee';

    protected static string | \UnitEnum | null $navigationGroup = 'Finance Management';

    protected static ?int $navigationSort = 8;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Basic Information')
                    ->schema([
                        Forms\Components\TextInput::make('transaction_id')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->disabled(),
                        Forms\Components\Select::make('wallet_id')
                            ->relationship('wallet', 'id')
                            ->label('Wallet')
                            ->disabled(fn($get) => $get('payment_method') === 'cash')
                            ->dehydrated(fn($get) => $get('payment_method') !== 'cash')
                            ->helperText(fn($get) => $get('payment_method') === 'cash' ? 'This is a cash payment. Wallet is not applicable.' : null)
                            ->required(fn($get) => $get('payment_method') !== 'cash')
                            ->nullable(),
                        Forms\Components\Select::make('user_id')
                            ->relationship('user', 'name', function ($query) {
                                return $query->whereNotNull('name')->where('name', '!=', '');
                            })
                            ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?: 'Unknown User')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('booking_id')
                            ->relationship('booking', 'booking_code', function ($query) {
                                return $query->whereNotNull('booking_code')->where('booking_code', '!=', '');
                            })
                            ->getOptionLabelFromRecordUsing(fn($record) => $record->booking_code ?: 'Unknown Booking')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                    ])->columns(2),

                Section::make('Transaction Details')
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->options([
                                'credit' => 'Credit',
                                'debit' => 'Debit',
                                'hold' => 'Hold',
                                'release' => 'Release',
                                'refund' => 'Refund',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('amount')
                            ->required()
                            ->numeric()
                            ->prefix('₹'),
                        Forms\Components\TextInput::make('balance')
                            ->required()
                            ->numeric()
                            ->prefix('₹'),
                        Forms\Components\Textarea::make('description')
                            ->required()
                            ->maxLength(65535),
                    ])->columns(2),

                Section::make('Payment Information')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'completed' => 'Completed',
                                'failed' => 'Failed',
                                'reversed' => 'Reversed',
                            ])
                            ->required(),
                        Forms\Components\Select::make('payment_method')
                            ->options([
                                'cash' => 'Cash',
                                'wallet' => 'Wallet',
                                'card' => 'Card',
                                'upi' => 'UPI',
                            ])
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function ($state, $set) {
                                if ($state === 'cash') {
                                    $set('wallet_id', null);
                                }
                            }),
                        Forms\Components\KeyValue::make('payment_details')
                            ->keyLabel('Field')
                            ->valueLabel('Value')
                            ->addable()
                            ->hidden()
                            ->deletable(),
                    ])->columns(2),

                Section::make('Reference Information')
                    ->schema([
                        Forms\Components\TextInput::make('reference_id')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('reference_type')
                            ->maxLength(255),
                    ])->columns(2),









            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('transaction_id')
                    ->formatStateUsing(fn($state) => auth()->id() === 2 ? 'xxx' : $state)
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->formatStateUsing(fn($state) => auth()->id() === 2 ? 'xxx' : $state)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('booking.booking_code')
                    ->formatStateUsing(fn($state) => auth()->id() === 2 ? 'xxx' : $state)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn($state) => auth()->id() === 2 ? 'xxx' : $state)
                    ->color(fn(string $state): string => match ($state) {
                        'credit' => 'success',
                        'debit' => 'danger',
                        'hold' => 'warning',
                        'release' => 'info',
                        'refund' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('amount')
                    ->formatStateUsing(function($state) {
                        if (auth()->id() === 2) {
                            return 'xxx';
                        }
                        return '₹' . number_format((float)$state, 2);
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
                Tables\Columns\TextColumn::make('description')
                    ->formatStateUsing(fn($state) => auth()->id() === 2 ? 'xxx' : $state)
                    ->searchable()
                    ->wrap()
                    ->limit(50),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn($state) => auth()->id() === 2 ? 'xxx' : $state)
                    ->color(fn(string $state): string => match ($state) {
                        'pending' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        'reversed' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('payment_method')
                    ->formatStateUsing(fn($state) => auth()->id() === 2 ? 'xxx' : $state)
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->formatStateUsing(fn($state) => auth()->id() === 2 ? 'xxx' : $state)
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user')
                    ->relationship('user', 'name', function ($query) {
                        return $query->whereNotNull('name')->where('name', '!=', '');
                    })
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?: 'Unknown User')
                    ->searchable()
                    ->preload()
                    ->multiple(),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'credit' => 'Credit',
                        'debit' => 'Debit',
                        'hold' => 'Hold',
                        'release' => 'Release',
                        'refund' => 'Refund',
                    ])
                    ->multiple(),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                        'reversed' => 'Reversed',
                    ])
                    ->multiple(),
                Tables\Filters\SelectFilter::make('payment_method')
                    ->options([
                        'cash' => 'Cash',
                        'wallet' => 'Wallet',
                        'card' => 'Card',
                        'upi' => 'UPI',
                    ])
                    ->multiple(),
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
                 EditAction::make(),
                 DeleteAction::make()
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        // Delete restrictions removed
                        $record->delete();
                        
                        Notification::make()
                            ->title('Deleted')
                            ->body('The transaction has been deleted.')
                            ->success()
                            ->send();
                    }),

                 ActionGroup::make([

                     Action::make('reverse_transaction')
                        ->label('Reverse')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Reverse Transaction')
                        ->modalDescription('This will create a reversal transaction to undo this transaction.')
                        ->form([
                            Forms\Components\Textarea::make('reversal_reason')
                                ->label('Reversal Reason')
                                ->required()
                                ->maxLength(500),
                        ])
                        ->action(function (Transaction $record, array $data) {
                            return static::reverseTransaction($record, $data);
                        })
                        ->visible(fn(Transaction $record): bool => $record->status === 'completed'),

                     Action::make('retry_transaction')
                        ->label('Retry')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Retry Transaction')
                        ->modalDescription('This will attempt to process the failed transaction again.')
                        ->action(function (Transaction $record) {
                            return static::retryTransaction($record);
                        })
                        ->visible(fn(Transaction $record): bool => $record->status === 'failed'),

                     Action::make('mark_completed')
                        ->label('Mark Completed')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Mark Transaction as Completed')
                        ->form([
                            Forms\Components\Textarea::make('completion_reason')
                                ->label('Completion Reason')
                                ->required()
                                ->maxLength(500),
                            Forms\Components\KeyValue::make('completion_details')
                                ->label('Additional Details')
                                ->keyLabel('Field')
                                ->valueLabel('Value'),
                        ])
                        ->action(function (Transaction $record, array $data) {
                            return static::markTransactionCompleted($record, $data);
                        })
                        ->visible(fn(Transaction $record): bool => $record->status === 'pending'),

                     Action::make('create_adjustment')
                        ->label('Create Adjustment')
                        ->icon('heroicon-o-adjustments-horizontal')
                        ->color('info')
                        ->form([
                            Forms\Components\TextInput::make('adjustment_amount')
                                ->label('Adjustment Amount')
                                ->numeric()
                                ->prefix('₹')
                                ->required()
                                ->helperText('Use positive for credit, negative for debit'),
                            Forms\Components\Textarea::make('adjustment_reason')
                                ->label('Adjustment Reason')
                                ->required()
                                ->maxLength(500),
                        ])
                        ->action(function (Transaction $record, array $data) {
                            return static::createAdjustmentTransaction($record, $data);
                        }),
                ])->label('Actions'),
            ])
            ->bulkActions([
                 BulkActionGroup::make([
                     DeleteBulkAction::make()
                        ->action(function ($records) {
                            // Delete restrictions removed
                            foreach ($records as $record) {
                                $record->delete();
                            }
                            
                            Notification::make()
                                ->title('Deleted')
                                ->body(count($records) . ' transaction(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),


            'edit' => Pages\EditTransaction::route('/{record}/edit'),
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

    
    protected static function reverseTransaction(Transaction $record, array $data): void
    {
        try {
            $reversalReason = $data['reversal_reason'] ?? '';
            DB::transaction(function () use ($record, $data, $reversalReason) {

                $wallet = null;
                $walletId = null;

                if ($record->wallet_id) {

                    if (!$record->relationLoaded('wallet')) {
                        $record->load('wallet');
                    }
                    $wallet = $record->wallet;
                    $walletId = $record->wallet_id;
                } else {

                    if (!$record->relationLoaded('user')) {
                        $record->load('user');
                    }

                    if (!$record->user) {
                        throw new \Exception('User not found for this transaction.');
                    }

                    if (!$record->user->relationLoaded('wallet')) {
                        $record->user->load('wallet');
                    }

                    $wallet = $record->user->wallet;

                    if (!$wallet) {
                        throw new \Exception('Wallet not found for this user.');
                    }

                    $walletId = $wallet->id;
                }

                if (!$wallet) {
                    throw new \Exception('Wallet not found for this transaction.');
                }















                $reversalAmount = -$record->amount; // Opposite sign
                $newBalance = $wallet->balance + $reversalAmount; // Add the reversal amount (which is opposite)

                $reversalTransaction = Transaction::create([
                    'transaction_id' => 'REV_' . $record->transaction_id,
                    'wallet_id' => $walletId,
                    'user_id' => $record->user_id,
                    'booking_id' => $record->booking_id,
                    'type' => 'reversal',
                    'amount' => $reversalAmount, // Opposite amount
                    'balance' => $newBalance,
                    'description' => 'Reversal of transaction #' . $record->transaction_id . ': ' . $reversalReason,
                    'status' => 'completed',
                    'payment_method' => $record->payment_method,
                    'reference_id' => $record->id,
                    'reference_type' => Transaction::class,
                    'meta_data' => [
                        'original_transaction_id' => $record->id,
                        'original_type' => $record->type,
                        'original_amount' => $record->amount,
                        'reversal_reason' => $reversalReason,
                        'admin_id' => auth()->user()?->id,
                        'reversed_at' => now(),
                    ],
                ]);

                $record->update(['status' => 'reversed']);









                if ($reversalAmount > 0) {
                    $wallet->increment('balance', $reversalAmount);
                } else {
                    $wallet->decrement('balance', abs($reversalAmount));
                }

            });

            Notification::make()
                ->title('Transaction Reversed')
                ->body('The transaction has been successfully reversed.')
                ->success()
                ->send();
        } catch (\Exception $e) {

            Notification::make()
                ->title('Reversal Failed')
                ->body('Failed to reverse transaction: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    
    protected static function retryTransaction(Transaction $record): void
    {
        try {
            DB::transaction(function () use ($record) {

                $record->update([
                    'status' => 'pending',
                    'meta_data' => array_merge($record->meta_data ?? [], [
                        'retry_attempted_at' => now(),
                        'retry_admin_id' => auth()->user()?->id,
                    ]),
                ]);

                if ($record->booking_id) {
                    $paymentService = app(\App\Services\PaymentGatewayService::class);
                    $result = $paymentService->processPayment(
                        $record->booking,
                        (float) abs($record->amount),
                        $record->payment_method
                    );

                    if ($result['success']) {
                        $record->update(['status' => 'completed']);
                    } else {
                        $record->update(['status' => 'failed']);
                        throw new \Exception($result['message']);
                    }
                }

            });

            Notification::make()
                ->title('Transaction Retry Successful')
                ->body('The transaction has been successfully processed.')
                ->success()
                ->send();
        } catch (\Exception $e) {

            Notification::make()
                ->title('Retry Failed')
                ->body('Failed to retry transaction: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    
    protected static function markTransactionCompleted(Transaction $record, array $data): void
    {
        try {
            DB::transaction(function () use ($record, $data) {
                $record->update([
                    'status' => 'completed',
                    'meta_data' => array_merge($record->meta_data ?? [], [
                        'manually_completed_at' => now(),
                        'completion_reason' => $data['completion_reason'],
                        'completion_details' => $data['completion_details'] ?? [],
                        'admin_id' => auth()->user()?->id,
                    ]),
                ]);

                $wallet = null;
                if ($record->wallet_id) {
                    if (!$record->relationLoaded('wallet')) {
                        $record->load('wallet');
                    }
                    $wallet = $record->wallet;
                } else {

                    if (!$record->relationLoaded('user')) {
                        $record->load('user');
                    }
                    if ($record->user && !$record->user->relationLoaded('wallet')) {
                        $record->user->load('wallet');
                    }
                    $wallet = $record->user->wallet ?? null;
                }

                if (in_array($record->type, ['credit', 'debit']) && $wallet) {
                    if ($record->type === 'credit') {
                        $wallet->increment('balance', $record->amount);
                    } else {
                        $wallet->decrement('balance', abs($record->amount));
                    }
                }

            });

            Notification::make()
                ->title('Transaction Completed')
                ->body('The transaction has been marked as completed.')
                ->success()
                ->send();
        } catch (\Exception $e) {

            Notification::make()
                ->title('Completion Failed')
                ->body('Failed to complete transaction: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    
    protected static function createAdjustmentTransaction(Transaction $record, array $data): void
    {
        try {
            DB::transaction(function () use ($record, $data) {

                $wallet = null;
                $walletId = null;

                if ($record->wallet_id) {

                    if (!$record->relationLoaded('wallet')) {
                        $record->load('wallet');
                    }
                    $wallet = $record->wallet;
                    $walletId = $record->wallet_id;
                } else {

                    if (!$record->relationLoaded('user')) {
                        $record->load('user');
                    }

                    if (!$record->user) {
                        throw new \Exception('User not found for this transaction.');
                    }

                    if (!$record->user->relationLoaded('wallet')) {
                        $record->user->load('wallet');
                    }

                    $wallet = $record->user->wallet;

                    if (!$wallet) {
                        throw new \Exception('Wallet not found for this user.');
                    }

                    $walletId = $wallet->id;
                }

                if (!$wallet) {
                    throw new \Exception('Wallet not found for this transaction.');
                }

                $adjustmentTransaction = Transaction::create([
                    'transaction_id' => 'ADJ_' . uniqid(),
                    'wallet_id' => $walletId,
                    'user_id' => $record->user_id,
                    'booking_id' => $record->booking_id,
                    'type' => 'adjustment',
                    'amount' => $data['adjustment_amount'],
                    'balance' => $wallet->balance + $data['adjustment_amount'],
                    'description' => 'Adjustment for transaction #' . $record->transaction_id . ': ' . $data['adjustment_reason'],
                    'status' => 'completed',
                    'payment_method' => 'manual',
                    'reference_id' => $record->id,
                    'reference_type' => Transaction::class,
                    'meta_data' => [
                        'original_transaction_id' => $record->id,
                        'adjustment_reason' => $data['adjustment_reason'],
                        'admin_id' => auth()->user()?->id,
                        'created_at' => now(),
                    ],
                ]);

                if ($data['adjustment_amount'] > 0) {
                    $wallet->increment('balance', $data['adjustment_amount']);
                } else {
                    $wallet->decrement('balance', abs($data['adjustment_amount']));
                }

            });

            Notification::make()
                ->title('Adjustment Created')
                ->body('The adjustment transaction has been created successfully.')
                ->success()
                ->send();
        } catch (\Exception $e) {

            Notification::make()
                ->title('Adjustment Failed')
                ->body('Failed to create adjustment: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
}
