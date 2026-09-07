<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Carbon\Carbon;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Exception;
use App\Models\Wallet;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\DriverWithdrawalRequestResource\Pages\ListDriverWithdrawalRequests;
use App\Filament\Resources\DriverWithdrawalRequestResource\Pages\EditDriverWithdrawalRequest;
use App\Filament\Resources\DriverWithdrawalRequestResource\Pages;
use App\Models\DriverWithdrawalRequest;
use App\Services\FCMService;
use App\Models\WalletTransaction;
use App\Models\Transaction;
use App\Models\Notification as NotificationModel;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Notifications\Notification;

use Illuminate\Support\Facades\DB;

class DriverWithdrawalRequestResource extends Resource
{
    protected static ?string $model = DriverWithdrawalRequest::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';
    protected static string | \UnitEnum | null $navigationGroup = 'Driver Management';
    protected static ?string $navigationLabel = 'Withdrawal Requests';
    protected static ?string $modelLabel = 'Withdrawal Request';
    protected static ?string $pluralModelLabel = 'Withdrawal Requests';
    protected static ?int $navigationSort = 5;
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Driver Details')
                    ->schema([
                        TextInput::make('driver_name')
                            ->label('Driver Name')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('driver_phone')
                            ->label('Phone Number')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('driver_email')
                            ->label('Email')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn($get) => !empty($get('driver_email')) && $get('driver_email') !== 'N/A'),
                    ]),

                Section::make('Withdrawal Request Details')
                    ->schema([
                        TextInput::make('amount')
                            ->label('Requested Amount')
                            ->disabled()
                            ->dehydrated(false)
                            ->prefix('₹')
                            ->formatStateUsing(fn($state) => $state ? number_format((float) $state, 2) : '0.00'),

                        TextInput::make('created_at')
                            ->label('Request Date')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(function ($state) {
                                if (!$state) return 'N/A';
                                if (is_string($state)) {
                                    return Carbon::parse($state)->format('Y-m-d H:i:s');
                                }
                                return $state->format('Y-m-d H:i:s');
                            }),
                    ]),

                Section::make('Bank Account Details')
                    ->schema([
                        TextInput::make('bank_account_holder_name')
                            ->label('Account Holder Name')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('bank_account_number')
                            ->label('Account Number')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('bank_name')
                            ->label('Bank Name')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('bank_ifsc_code')
                            ->label('IFSC Code')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn($get) => !empty($get('bank_ifsc_code')) && $get('bank_ifsc_code') !== 'N/A'),

                        TextInput::make('bank_branch_name')
                            ->label('Branch Name')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn($get) => !empty($get('bank_branch_name')) && $get('bank_branch_name') !== 'N/A'),
                    ]),

                Section::make('Admin Processing')
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options([
                                DriverWithdrawalRequest::STATUS_PENDING => 'Pending',
                                DriverWithdrawalRequest::STATUS_APPROVED => 'Approved',
                                DriverWithdrawalRequest::STATUS_REJECTED => 'Rejected',
                            ])
                            ->required()
                            ->live(),

                        TextInput::make('payment_reference')
                            ->label('Payment Reference')
                            ->maxLength(255)
                            ->placeholder('Transaction ID or reference number')
                            ->visible(fn($get) => $get('status') === DriverWithdrawalRequest::STATUS_APPROVED)
                            ->required(fn($get) => $get('status') === DriverWithdrawalRequest::STATUS_APPROVED),

                        Textarea::make('rejection_reason')
                            ->label('Rejection Reason')
                            ->rows(3)
                            ->placeholder('Reason for rejection (if applicable)')
                            ->visible(fn($get) => $get('status') === DriverWithdrawalRequest::STATUS_REJECTED)
                            ->required(fn($get) => $get('status') === DriverWithdrawalRequest::STATUS_REJECTED),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {

                return $query->with(['driver', 'bankAccount', 'processedBy']);
            })
            ->columns([
                TextColumn::make('driver.name')
                    ->label('Driver')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('driver.phone')
                    ->label('Phone')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn($state) => '₹' . number_format((float) $state, 2))
                    ->sortable(),

                TextColumn::make('bankAccount.bank_name')
                    ->label('Bank')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('bankAccount', function (Builder $query) use ($search) {
                            $query->where('bank_name', 'like', "%{$search}%");
                        });
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('bankAccount.account_number')
                    ->label('Account Number')
                    ->formatStateUsing(fn($state) => '****' . substr($state, -4))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('bankAccount', function (Builder $query) use ($search) {
                            $query->where('account_number', 'like', "%{$search}%");
                        });
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        DriverWithdrawalRequest::STATUS_PENDING => 'warning',
                        DriverWithdrawalRequest::STATUS_APPROVED => 'success',
                        DriverWithdrawalRequest::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('payment_reference')
                    ->label('Payment Reference')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('processed_at')
                    ->label('Processed')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('processedBy.name')
                    ->label('Processed By')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        DriverWithdrawalRequest::STATUS_PENDING => 'Pending',
                        DriverWithdrawalRequest::STATUS_APPROVED => 'Approved',
                        DriverWithdrawalRequest::STATUS_REJECTED => 'Rejected',
                    ]),
            ])
            ->actions([
                EditAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(DriverWithdrawalRequest $record) => $record->isPending())
                    ->requiresConfirmation()
                    ->schema([
                        TextInput::make('payment_reference')
                            ->label('Payment Reference')
                            ->required()
                            ->placeholder('Transaction ID or reference number'),
                    ])
                    ->action(function (DriverWithdrawalRequest $record, array $data) {
                        try {
                            DB::beginTransaction();

                            $record->update([
                                'status' => DriverWithdrawalRequest::STATUS_APPROVED,
                                'payment_reference' => $data['payment_reference'],
                                'processed_by' => auth()->id(),
                                'processed_at' => now(),
                            ]);

                            $walletTransaction = self::findWalletTransaction($record);
                            if ($walletTransaction) {
                                $walletTransaction->update([
                                    'status' => WalletTransaction::STATUS_COMPLETED,
                                    'meta_data' => array_merge($walletTransaction->meta_data ?? [], [
                                        'approved_at' => now()->toDateTimeString(),
                                        'payment_reference' => $data['payment_reference'],
                                        'approved_by' => auth()->id(),
                                        'withdrawal_request_id' => $record->id,
                                    ]),
                                ]);
                            }

                            $transaction = self::findTransaction($record);
                            if ($transaction) {
                                $transaction->update([
                                    'status' => 'completed',
                                    'meta_data' => array_merge($transaction->meta_data ?? [], [
                                        'approved_at' => now()->toDateTimeString(),
                                        'payment_reference' => $data['payment_reference'],
                                        'approved_by' => auth()->id(),
                                        'withdrawal_request_id' => $record->id,
                                    ]),
                                ]);
                            }

                            self::sendWithdrawalNotification($record->driver, $record, 'approved');

                            DB::commit();

                            Notification::make()
                                ->title('Withdrawal Approved')
                                ->body('The withdrawal request has been approved and notification sent to driver.')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            DB::rollBack();

                            Notification::make()
                                ->title('Error')
                                ->body('Failed to approve withdrawal request: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn(DriverWithdrawalRequest $record) => $record->isPending())
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('rejection_reason')
                            ->label('Rejection Reason')
                            ->required()
                            ->rows(3)
                            ->placeholder('Reason for rejection'),
                    ])
                    ->action(function (DriverWithdrawalRequest $record, array $data) {
                        try {
                            DB::beginTransaction();

                            $record->update([
                                'status' => DriverWithdrawalRequest::STATUS_REJECTED,
                                'rejection_reason' => $data['rejection_reason'],
                                'processed_by' => auth()->id(),
                                'processed_at' => now(),
                            ]);

                            $walletTransaction = self::findWalletTransaction($record);
                            if ($walletTransaction) {
                                $walletTransaction->update([
                                    'status' => WalletTransaction::STATUS_FAILED,
                                    'meta_data' => array_merge($walletTransaction->meta_data ?? [], [
                                        'rejected_at' => now()->toDateTimeString(),
                                        'rejection_reason' => $data['rejection_reason'],
                                        'rejected_by' => auth()->id(),
                                        'withdrawal_request_id' => $record->id,
                                    ]),
                                ]);
                            }

                            $transaction = self::findTransaction($record);
                            if ($transaction) {
                                $transaction->update([
                                    'status' => 'failed',
                                    'meta_data' => array_merge($transaction->meta_data ?? [], [
                                        'rejected_at' => now()->toDateTimeString(),
                                        'rejection_reason' => $data['rejection_reason'],
                                        'rejected_by' => auth()->id(),
                                        'withdrawal_request_id' => $record->id,
                                    ]),
                                ]);
                            }

                            $driver = $record->driver;
                            $driverWallet = $driver->wallet;

                            if (!$driverWallet) {

                                $driverWallet = Wallet::create([
                                    'user_id' => $driver->id,
                                    'driver_id' => $driver->id,
                                    'balance' => 0,
                                    'total_credit' => 0,
                                    'total_debit' => 0,
                                    'status' => Wallet::STATUS_ACTIVE,
                                ]);
                            }

                            $driverWallet->refresh();

                            $driverWallet->credit(
                                (float) $record->amount,
                                WalletTransaction::TYPE_WITHDRAWAL_REFUND,
                                "Withdrawal request #{$record->id} rejected - amount refunded. Reason: {$data['rejection_reason']}",
                                [
                                    'withdrawal_request_id' => $record->id,
                                    'rejection_reason' => $data['rejection_reason'],
                                    'refunded_at' => now()->toDateTimeString(),
                                    'refunded_by' => auth()->id(),
                                ]
                            );

                            $driverWallet->refresh();

                            self::sendWithdrawalNotification($record->driver, $record, 'rejected', $data['rejection_reason']);

                            DB::commit();

                            Notification::make()
                                ->title('Withdrawal Rejected')
                                ->body('The withdrawal request has been rejected, amount refunded, and notification sent to driver.')
                                ->warning()
                                ->send();
                        } catch (Exception $e) {
                            DB::rollBack();

                            Notification::make()
                                ->title('Error')
                                ->body('Failed to reject withdrawal request: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDriverWithdrawalRequests::route('/'),
            'edit' => EditDriverWithdrawalRequest::route('/{record}/edit'),
        ];
    }


    protected static function findWalletTransaction(DriverWithdrawalRequest $withdrawalRequest): ?WalletTransaction
    {

        $metaData = $withdrawalRequest->meta_data ?? [];
        if (isset($metaData['wallet_transaction_id'])) {
            $transaction = WalletTransaction::find($metaData['wallet_transaction_id']);
            if ($transaction) {
                return $transaction;
            }
        }

        if ($withdrawalRequest->bank_account_id) {
            $transaction = WalletTransaction::where('type', WalletTransaction::TYPE_WALLET_WITHDRAWAL)
                ->where('reference_type', 'App\Models\BankAccount')
                ->where('reference_id', $withdrawalRequest->bank_account_id)
                ->where('driver_id', $withdrawalRequest->driver_id)
                ->where('amount', -abs($withdrawalRequest->amount))
                ->orderBy('created_at', 'desc')
                ->first();

            if ($transaction) {
                return $transaction;
            }
        }

        return null;
    }

    protected static function findTransaction(DriverWithdrawalRequest $withdrawalRequest): ?Transaction
    {
        // Try to find by direct reference
        $transaction = Transaction::where('reference_type', 'App\Models\DriverWithdrawalRequest')
            ->where('reference_id', $withdrawalRequest->id)
            ->first();

        if ($transaction) {
            return $transaction;
        }

        return null;
    }


    protected static function sendWithdrawalNotification($driver, DriverWithdrawalRequest $withdrawalRequest, string $status, ?string $rejectionReason = null): void
    {
        try {
            $amount = '₹' . number_format($withdrawalRequest->amount, 2);
            $fcmService = app(FCMService::class);

            if ($status === 'approved') {
                $title = 'Withdrawal Approved';
                $body = "Your withdrawal request of {$amount} has been approved. Payment Reference: {$withdrawalRequest->payment_reference}";
                $notificationData = [
                    'type' => 'withdrawal_approved',
                    'withdrawal_request_id' => $withdrawalRequest->id,
                    'amount' => (string) $withdrawalRequest->amount,
                    'payment_reference' => $withdrawalRequest->payment_reference ?? '',
                ];
            } else {
                $title = 'Withdrawal Rejected';
                $reason = $rejectionReason ?? $withdrawalRequest->rejection_reason ?? 'No reason provided';
                $body = "Your withdrawal request of {$amount} has been rejected. Reason: {$reason}. The amount has been refunded to your wallet.";
                $notificationData = [
                    'type' => 'withdrawal_rejected',
                    'withdrawal_request_id' => $withdrawalRequest->id,
                    'amount' => (string) $withdrawalRequest->amount,
                    'rejection_reason' => $reason,
                ];
            }

            $notificationRecord = NotificationModel::create([
                'user_id' => $driver->id,
                'type' => 'withdrawal_status',
                'title' => $title,
                'body' => $body,
                'data' => $notificationData,
                'status' => 'pending',
                'is_sent' => false,
            ]);

            if ($driver->device_token || $driver->fcm_token) {
                $fcmToken = $driver->device_token ?? $driver->fcm_token;
                $fcmResult = $fcmService->sendToDevice($fcmToken, [
                    'title' => $title,
                    'body' => $body,
                    'icon' => 'ic_notification',
                    'sound' => 'default',
                ], $notificationData);

                if ($fcmResult['success'] ?? false) {
                    $fcmMessageId = is_array($fcmResult['message_id'] ?? null)
                        ? json_encode($fcmResult['message_id'])
                        : ($fcmResult['message_id'] ?? null);

                    $notificationRecord->markAsSent($fcmMessageId ? (string)$fcmMessageId : null);
                } else {
                    $errorMessage = $fcmResult['message'] ?? $fcmResult['error'] ?? 'FCM send failed';
                    $notificationRecord->markAsFailed($errorMessage);
                }
            } else {
            }
        } catch (Exception $e) {
            // Error handling
        }
    }
}
