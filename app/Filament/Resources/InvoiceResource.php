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

use App\Filament\Resources\InvoiceResource\Pages;
use App\Models\Booking;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoiceResource extends BaseResource
{
    protected static ?string $model = Booking::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static string | \UnitEnum | null $navigationGroup = 'Finance Management';

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?int $navigationSort = 8;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    protected static ?string $pluralModelLabel = 'Invoices';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user', 'driver'])
            ->where('status', 'completed')
            ->whereNotNull('completed_at');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Invoice Information')
                    ->schema([
                        Forms\Components\TextInput::make('booking_code')
                            ->label('Invoice Number')
                            ->disabled(),
                        Forms\Components\TextInput::make('customer_name')
                            ->label('Customer Name')
                            ->disabled()
                            ->afterStateHydrated(function ($component, $state, $record) {
                                if ($record && $record->user_id) {

                                    $customer = $record->relationLoaded('user')
                                        ? $record->user
                                        : \App\Models\User::where('id', $record->user_id)->where('role_id', 3)->first();
                                    $component->state($customer && $customer->role_id == 3 ? ($customer->name ?? '') : '');
                                }
                            }),
                        Forms\Components\TextInput::make('driver_name')
                            ->label('Driver Name')
                            ->disabled()
                            ->afterStateHydrated(function ($component, $state, $record) {
                                if ($record && $record->driver_id) {

                                    $driver = $record->relationLoaded('driver')
                                        ? $record->driver
                                        : \App\Models\User::where('id', $record->driver_id)->where('role_id', 2)->first();
                                    $component->state($driver && $driver->role_id == 2 ? ($driver->name ?? '') : '');
                                }
                            }),
                        Forms\Components\TextInput::make('total_amount')
                            ->label('Total Amount')
                            ->prefix('₹')
                            ->disabled(),
                        Forms\Components\TextInput::make('payment_status')
                            ->disabled(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking_code')
                    ->label('Invoice #')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn($state) => $state ?? 'N/A'),
                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Driver')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn($state) => $state ?? 'N/A'),
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Invoice Date')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Amount')
                    ->money('INR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('driver_amount')
                    ->label('Driver Amount')
                    ->money('INR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('admin_commission')
                    ->label('Platform Commission')
                    ->money('INR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_method')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'wallet' => 'success',
                        'cash' => 'warning',
                        'card' => 'info',
                        'upi' => 'info',
                        'netbanking' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('payment_status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'completed' => 'success',
                        'paid' => 'success',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('payment_method')
                    ->options([
                        'wallet' => 'Wallet',
                        'cash' => 'Cash',
                        'card' => 'Card',
                        'upi' => 'UPI',
                        'netbanking' => 'Net Banking',
                    ]),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->options([
                        'completed' => 'Completed',
                        'paid' => 'Paid',
                        'pending' => 'Pending',
                        'failed' => 'Failed',
                    ]),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('From Date'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('Until Date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn(Builder $query, $date): Builder => $query->whereDate('completed_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('completed_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                 ViewAction::make(),
                 Action::make('view_invoice')
                    ->label('View Invoice')
                    ->icon('heroicon-o-document-text')
                    ->color('success')
                    ->url(fn(Booking $record): string => route('admin.bookings.invoice', $record))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                 BulkActionGroup::make([
                     Action::make('export_invoices')
                        ->label('Export Invoices')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($records) {

                        }),
                ]),
            ])
            ->defaultSort('completed_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'view' => Pages\ViewInvoice::route('/{record}'),
        ];
    }
}
