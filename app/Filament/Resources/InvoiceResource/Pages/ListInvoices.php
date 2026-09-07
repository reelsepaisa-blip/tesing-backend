<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Booking;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Response;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export_all')
                ->label('Export All Invoices')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function () {
                    return $this->exportInvoices();
                }),
        ];
    }

    protected function exportInvoices()
    {

        $invoices = Booking::query()
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->with(['user', 'driver'])
            ->orderBy('completed_at', 'desc')
            ->get();

        $filename = 'invoices_' . now()->format('Y-m-d_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($invoices) {
            $file = fopen('php://output', 'w');

            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($file, [
                'Invoice #',
                'Customer',
                'Driver',
                'Invoice Date',
                'Amount (₹)',
                'Driver Amount (₹)',
                'Platform Commission (₹)',
                'Payment Method',
                'Payment Status',
            ]);

            foreach ($invoices as $invoice) {
                fputcsv($file, [
                    $invoice->booking_code ?? '',
                    $invoice->user->name ?? 'N/A',
                    $invoice->driver->name ?? 'N/A',
                    $invoice->completed_at ? $invoice->completed_at->format('Y-m-d H:i:s') : '',
                    number_format($invoice->total_amount ?? 0, 2, '.', ''),
                    number_format($invoice->driver_amount ?? 0, 2, '.', ''),
                    number_format($invoice->admin_commission ?? 0, 2, '.', ''),
                    $invoice->payment_method ?? 'N/A',
                    $invoice->payment_status ?? 'N/A',
                ]);
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }
}
