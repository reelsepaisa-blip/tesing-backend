<?php

namespace App\Filament\Resources\RefundRequestResource\Pages;

use App\Filament\Resources\RefundRequestResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use App\Models\RefundRequest;
use Illuminate\Support\Facades\Auth;

class EditRefundRequest extends EditRecord
{
    protected static string $resource = RefundRequestResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Refund Request Updated')
            ->body('The refund request has been updated successfully.');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {

        if (in_array($data['status'] ?? null, [RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_PARTIALLY_APPROVED])) {
            if (empty($data['processed_at'])) {
                $data['processed_by'] = Auth::id();
                $data['processed_at'] = now();
            }

            $refundSource = $data['refund_source'] ?? $this->record->refund_source;
            $approvedAmount = $data['approved_amount'] ?? $this->record->approved_amount;

            if ($refundSource && $approvedAmount) {

                if (!$this->record->relationLoaded('booking')) {
                    $this->record->load('booking');
                }

                $originalSource = $this->record->refund_source;
                $originalAmount = $this->record->approved_amount;

                $this->record->refund_source = $refundSource;
                $this->record->approved_amount = (float) $approvedAmount;

                $this->record->refund_source = $originalSource;
                $this->record->approved_amount = $originalAmount;
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->record;

        $record->refresh();

        if ($record->isApproved() && $record->approved_amount && $record->refund_source) {
            try {

                $hasTransactions = \App\Models\WalletTransaction::where('meta_data->refund_request_id', $record->id)
                    ->exists();

                if (!$hasTransactions) {
                    $record->processRefund();
                }
            } catch (\Exception $e) {

                $record->update([
                    'status' => RefundRequest::STATUS_PENDING,
                    'processed_by' => null,
                    'processed_at' => null,
                ]);

                Notification::make()
                    ->title('Refund Processing Failed')
                    ->body('Failed to process wallet transactions: ' . $e->getMessage() . '. Refund status has been reverted to pending.')
                    ->danger()
                    ->send();
            }
        }
    }
}

