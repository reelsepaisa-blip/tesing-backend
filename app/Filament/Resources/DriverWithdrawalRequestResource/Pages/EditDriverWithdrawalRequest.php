<?php

namespace App\Filament\Resources\DriverWithdrawalRequestResource\Pages;

use App\Models\Wallet;
use Exception;
use App\Filament\Resources\DriverWithdrawalRequestResource;
use App\Models\DriverWithdrawalRequest;
use App\Models\WalletTransaction;
use App\Models\Transaction;
use App\Services\FCMService;
use App\Models\Notification as NotificationModel;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;

use Illuminate\Support\Facades\DB;

class EditDriverWithdrawalRequest extends EditRecord
{
    protected static string $resource = DriverWithdrawalRequestResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {

        $this->record->loadMissing(['driver', 'bankAccount', 'processedBy']);

        $data['driver_name'] = $this->record->driver->name ?? 'N/A';
        $data['driver_phone'] = $this->record->driver->phone ?? 'N/A';
        $data['driver_email'] = $this->record->driver->email ?? 'N/A';

        if ($this->record->bankAccount) {
            $data['bank_account_holder_name'] = $this->record->bankAccount->account_holder_name ?? 'N/A';
            $data['bank_account_number'] = $this->record->bankAccount->account_number ?? 'N/A';
            $data['bank_name'] = $this->record->bankAccount->bank_name ?? 'N/A';
            $data['bank_ifsc_code'] = $this->record->bankAccount->ifsc_code ?? 'N/A';
            $data['bank_branch_name'] = $this->record->bankAccount->branch_name ?? 'N/A';
        } else {
            $data['bank_account_holder_name'] = 'N/A';
            $data['bank_account_number'] = 'N/A';
            $data['bank_name'] = 'N/A';
            $data['bank_ifsc_code'] = 'N/A';
            $data['bank_branch_name'] = 'N/A';
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Withdrawal Request Updated')
            ->body('The withdrawal request has been updated successfully.');
    }

    protected $originalStatus;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Capture the original status from database before save
        $this->originalStatus = $this->record->getOriginal('status') ?? $this->record->status;
        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->record->fresh(); // Refresh to get the latest data
        $originalStatus = $this->originalStatus;
        $newStatus = $record->status;

        if ($originalStatus !== $newStatus) {
            try {
                DB::beginTransaction();

                // Update processed_by and processed_at when status changes
                $record->update([
                    'processed_by' => auth()->id(),
                    'processed_at' => now(),
                ]);

                $driver = $record->driver;
                $walletTransaction = $this->findWalletTransaction($record);

                if ($newStatus === DriverWithdrawalRequest::STATUS_APPROVED) {

                    if ($walletTransaction) {
                        $walletTransaction->update([
                            'status' => WalletTransaction::STATUS_COMPLETED,
                            'meta_data' => array_merge($walletTransaction->meta_data ?? [], [
                                'approved_at' => now()->toDateTimeString(),
                                'payment_reference' => $record->payment_reference ?? null,
                                'approved_by' => auth()->id(),
                                'withdrawal_request_id' => $record->id,
                            ]),
                        ]);
                    }

                    // Also update Transaction model if it exists
                    $transaction = $this->findTransaction($record);
                    if ($transaction) {
                        $transaction->update([
                            'status' => 'completed',
                            'meta_data' => array_merge($transaction->meta_data ?? [], [
                                'approved_at' => now()->toDateTimeString(),
                                'payment_reference' => $record->payment_reference ?? null,
                                'approved_by' => auth()->id(),
                                'withdrawal_request_id' => $record->id,
                            ]),
                        ]);
                    }

                    $this->sendWithdrawalNotification($driver, $record, 'approved');
                } elseif ($newStatus === DriverWithdrawalRequest::STATUS_REJECTED) {

                    if ($walletTransaction) {
                        $walletTransaction->update([
                            'status' => WalletTransaction::STATUS_FAILED,
                            'meta_data' => array_merge($walletTransaction->meta_data ?? [], [
                                'rejected_at' => now()->toDateTimeString(),
                                'rejection_reason' => $record->rejection_reason ?? null,
                                'rejected_by' => auth()->id(),
                                'withdrawal_request_id' => $record->id,
                            ]),
                        ]);
                    }

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
                        "Withdrawal request #{$record->id} rejected - amount refunded. Reason: " . ($record->rejection_reason ?? 'Not provided'),
                        [
                            'withdrawal_request_id' => $record->id,
                            'rejection_reason' => $record->rejection_reason ?? null,
                            'refunded_at' => now()->toDateTimeString(),
                            'refunded_by' => auth()->id(),
                        ]
                    );

                    $driverWallet->refresh();

                    // Also update Transaction model if it exists
                    $transaction = $this->findTransaction($record);
                    if ($transaction) {
                        $transaction->update([
                            'status' => 'failed',
                            'meta_data' => array_merge($transaction->meta_data ?? [], [
                                'rejected_at' => now()->toDateTimeString(),
                                'rejection_reason' => $record->rejection_reason ?? null,
                                'rejected_by' => auth()->id(),
                                'withdrawal_request_id' => $record->id,
                            ]),
                        ]);
                    }

                    $this->sendWithdrawalNotification($driver, $record, 'rejected', $record->rejection_reason);
                }

                DB::commit();

                Notification::make()
                    ->success()
                    ->title('Withdrawal Status Updated')
                    ->body('The withdrawal request status has been updated and notification sent to driver.')
                    ->send();
            } catch (Exception $e) {
                DB::rollBack();

                Notification::make()
                    ->danger()
                    ->title('Error')
                    ->body('Failed to update withdrawal request: ' . $e->getMessage())
                    ->send();
            }
        }
    }


    protected function sendWithdrawalNotification($driver, DriverWithdrawalRequest $withdrawalRequest, string $status, ?string $rejectionReason = null): void
    {
        try {
            $amount = '₹' . number_format($withdrawalRequest->amount, 2);
            $fcmService = app(FCMService::class);

            if ($status === 'approved') {
                $title = 'Withdrawal Approved';
                $body = "Your withdrawal request of {$amount} has been approved. Payment Reference: " . ($withdrawalRequest->payment_reference ?? 'N/A');
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


    protected function findWalletTransaction(DriverWithdrawalRequest $withdrawalRequest): ?WalletTransaction
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

    protected function findTransaction(DriverWithdrawalRequest $withdrawalRequest): ?Transaction
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
}
