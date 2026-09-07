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

use App\Filament\Resources\RefundRequestResource\Pages;
use App\Models\RefundRequest;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class RefundRequestResource extends Resource
{
    protected static ?string $model = RefundRequest::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-currency-dollar';
    protected static string | \UnitEnum | null $navigationGroup = 'Support';
    protected static ?string $navigationLabel = 'Refund Requests';
    protected static ?int $navigationSort = 10;
    protected static ?string $modelLabel = 'Refund Request';
    protected static ?string $pluralModelLabel = 'Refund Requests';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Refund Request Details')
                    ->schema([
                        Forms\Components\View::make('filament.components.info-field')
                            ->columnSpan(['md' => 2])
                            ->viewData(fn(?RefundRequest $record) => [
                                'label' => 'Booking Code',
                                'icon' => 'heroicon-o-ticket',
                                'value' => $record?->booking?->booking_code ?? 'Not available',
                            ]),

                        Forms\Components\View::make('filament.components.info-field')
                            ->columnSpan(['md' => 2])
                            ->viewData(fn(?RefundRequest $record) => [
                                'label' => 'Customer',
                                'icon' => 'heroicon-o-user-circle',
                                'value' => $record?->user?->name ?? 'Not available',
                            ]),

                        Forms\Components\View::make('filament.components.info-field')
                            ->columnSpan(['md' => 2])
                            ->viewData(fn(?RefundRequest $record) => [
                                'label' => 'Refund Reason',
                                'icon' => 'heroicon-o-chat-bubble-bottom-center-text',
                                'value' => $record?->reason ?? 'Not available',
                            ]),

                        Forms\Components\View::make('filament.components.info-field')
                            ->columnSpan(['md' => 2])
                            ->viewData(fn(?RefundRequest $record) => [
                                'label' => 'Requested Amount',
                                'icon' => 'heroicon-o-banknotes',
                                'value' => $record ? '₹' . number_format($record->requested_amount, 2) : 'Not available',
                            ]),

                        Forms\Components\View::make('filament.components.info-field')
                            ->columnSpan(['md' => 4])
                            ->viewData(fn(?RefundRequest $record) => [
                                'label' => 'Description',
                                'icon' => 'heroicon-o-document-text',
                                'value' => $record?->description ?? 'Not available',
                            ]),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 6,
                    ]),

                Section::make('Admin Processing')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                RefundRequest::STATUS_PENDING => 'Pending',
                                RefundRequest::STATUS_APPROVED => 'Approved (Full)',
                                RefundRequest::STATUS_REJECTED => 'Rejected',
                            ])
                            ->required()
                            ->live(),

                        Forms\Components\TextInput::make('approved_amount')
                            ->label('Approved Amount')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(fn($get) => $get('requested_amount'))
                            ->visible(fn($get) => in_array($get('status'), [
                                RefundRequest::STATUS_APPROVED,
                                RefundRequest::STATUS_PARTIALLY_APPROVED
                            ]))
                            ->required(fn($get) => in_array($get('status'), [
                                RefundRequest::STATUS_APPROVED,
                                RefundRequest::STATUS_PARTIALLY_APPROVED
                            ]))
                            ->live()
                            ->rules([
                                function ($get, $record) {
                                    return function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                                        if (!$value) {
                                            return;
                                        }

                                        $refundSource = $get('refund_source');
                                        if (!$refundSource) {
                                            return;
                                        }

                                        if (!in_array($refundSource, [
                                            RefundRequest::SOURCE_ADMIN_ACCOUNT,
                                            RefundRequest::SOURCE_DRIVER_WALLET
                                        ])) {
                                            return;
                                        }

                                        if ($record) {

                                            if (!$record->relationLoaded('booking')) {
                                                $record->load('booking');
                                            }

                                            $originalSource = $record->refund_source;
                                            $originalAmount = $record->approved_amount;

                                            $record->refund_source = $refundSource;
                                            $record->approved_amount = (float) $value;

                                            $record->refund_source = $originalSource;
                                            $record->approved_amount = $originalAmount;
                                        }
                                    };
                                },
                            ]),

                        Forms\Components\Select::make('refund_source')
                            ->label('Refund Source')
                            ->options([
                                RefundRequest::SOURCE_ADMIN_ACCOUNT => 'Admin Account (Platform)',
                                RefundRequest::SOURCE_DRIVER_WALLET => 'Driver Wallet',
                            ])
                            ->visible(fn($get) => in_array($get('status'), [
                                RefundRequest::STATUS_APPROVED,
                                RefundRequest::STATUS_PARTIALLY_APPROVED
                            ]))
                            ->required(fn($get) => in_array($get('status'), [
                                RefundRequest::STATUS_APPROVED,
                                RefundRequest::STATUS_PARTIALLY_APPROVED
                            ]))
                            ->live(),

                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Admin Notes')
                            ->rows(3)
                            ->placeholder('Internal notes about this refund request'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking.booking_code')
                    ->label('Booking Code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
                    ->searchable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('requested_amount')
                    ->label('Requested')
                    ->money('INR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('approved_amount')
                    ->label('Approved')
                    ->money('INR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        RefundRequest::STATUS_PENDING => 'warning',
                        RefundRequest::STATUS_APPROVED => 'success',
                        RefundRequest::STATUS_PARTIALLY_APPROVED => 'info',
                        RefundRequest::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('refund_source')
                    ->label('Source')
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        RefundRequest::SOURCE_ADMIN_ACCOUNT => 'Admin Account',
                        RefundRequest::SOURCE_DRIVER_WALLET => 'Driver Wallet',
                        default => 'Not Set',
                    })
                    ->badge()
                    ->color(fn(?string $state): string => match ($state) {
                        RefundRequest::SOURCE_ADMIN_ACCOUNT => 'success',
                        RefundRequest::SOURCE_DRIVER_WALLET => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('processed_at')
                    ->label('Processed')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        RefundRequest::STATUS_PENDING => 'Pending',
                        RefundRequest::STATUS_APPROVED => 'Approved',
                        RefundRequest::STATUS_REJECTED => 'Rejected',
                    ]),

                Tables\Filters\SelectFilter::make('refund_source')
                    ->label('Refund Source')
                    ->options([
                        RefundRequest::SOURCE_ADMIN_ACCOUNT => 'Admin Account',
                        RefundRequest::SOURCE_DRIVER_WALLET => 'Driver Wallet',
                    ]),
            ])
            ->actions([
                 EditAction::make(),
                 Action::make('process_refund')
                    ->label('Process Refund')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(RefundRequest $record) => $record->isPending())
                    ->form(fn(RefundRequest $record) => [
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                RefundRequest::STATUS_APPROVED => 'Approved',
                                RefundRequest::STATUS_REJECTED => 'Rejected',
                            ])
                            ->required()
                            ->live()
                            ->default(RefundRequest::STATUS_APPROVED),

                        Forms\Components\TextInput::make('approved_amount')
                            ->label('Approved Amount')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue($record->requested_amount)
                            ->default($record->requested_amount)
                            ->visible(fn($get) => in_array($get('status'), [
                                RefundRequest::STATUS_APPROVED,
                                RefundRequest::STATUS_PARTIALLY_APPROVED
                            ]))
                            ->required(fn($get) => in_array($get('status'), [
                                RefundRequest::STATUS_APPROVED,
                                RefundRequest::STATUS_PARTIALLY_APPROVED
                            ])),

                        Forms\Components\Select::make('refund_source')
                            ->label('Refund Source')
                            ->options([
                                RefundRequest::SOURCE_ADMIN_ACCOUNT => 'Admin Account (Platform)',
                                RefundRequest::SOURCE_DRIVER_WALLET => 'Driver Wallet',
                            ])
                            ->visible(fn($get) => in_array($get('status'), [
                                RefundRequest::STATUS_APPROVED,
                                RefundRequest::STATUS_PARTIALLY_APPROVED
                            ]))
                            ->required(fn($get) => in_array($get('status'), [
                                RefundRequest::STATUS_APPROVED,
                                RefundRequest::STATUS_PARTIALLY_APPROVED
                            ]))
                            ->default(RefundRequest::SOURCE_ADMIN_ACCOUNT),

                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Admin Notes')
                            ->rows(3)
                            ->placeholder('Internal notes about this refund request'),
                    ])
                    ->requiresConfirmation()
                    ->action(function (RefundRequest $record, array $data) {

                        $approvedAmount = $data['approved_amount'] ?? $record->requested_amount;
                        $refundSource = $data['refund_source'] ?? null;

                        if ($refundSource && in_array($data['status'], [RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_PARTIALLY_APPROVED])) {

                            if (!$record->relationLoaded('booking')) {
                                $record->load('booking');
                            }

                            $originalSource = $record->refund_source;
                            $originalAmount = $record->approved_amount;

                            $record->refund_source = $refundSource;
                            $record->approved_amount = (float) $approvedAmount;

                            $record->refund_source = $originalSource;
                            $record->approved_amount = $originalAmount;
                        }

                        $record->update([
                            'status' => $data['status'],
                            'approved_amount' => $approvedAmount,
                            'refund_source' => $refundSource,
                            'admin_notes' => $data['admin_notes'] ?? null,
                            'processed_by' => Auth::id(),
                            'processed_at' => now(),
                        ]);

                        if ($record->isApproved()) {
                            try {
                                $record->processRefund();

                                Notification::make()
                                    ->title('Refund Processed')
                                    ->body('The refund has been processed successfully.')
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {

                                $record->update([
                                    'status' => RefundRequest::STATUS_PENDING,
                                    'processed_by' => null,
                                    'processed_at' => null,
                                ]);

                                Notification::make()
                                    ->title('Refund Processing Failed')
                                    ->body($e->getMessage() . '. Refund status has been reverted to pending.')
                                    ->danger()
                                    ->send();
                            }
                        } else {
                            Notification::make()
                                ->title('Refund Request Updated')
                                ->body('The refund request has been updated successfully.')
                                ->success()
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
            'index' => Pages\ListRefundRequests::route('/'),
            'edit' => Pages\EditRefundRequest::route('/{record}/edit'),
        ];
    }
}
