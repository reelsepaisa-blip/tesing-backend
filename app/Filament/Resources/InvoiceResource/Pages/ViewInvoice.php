<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('view_invoice')
                ->label('View Full Invoice')
                ->icon('heroicon-o-document-text')
                ->color('success')
                ->url(fn() => route('admin.bookings.invoice', $this->record))
                ->openUrlInNewTab(),
            Actions\Action::make('print_invoice')
                ->label('Print Invoice')
                ->icon('heroicon-o-printer')
                ->color('info')
                ->url(fn() => route('admin.bookings.invoice', $this->record) . '?print=1')
                ->openUrlInNewTab(),
        ];
    }
}
