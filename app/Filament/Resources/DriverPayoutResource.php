<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\KeyValue;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Actions\EditAction;
use Filament\Actions\ActionGroup;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use App\Filament\Resources\DriverPayoutResource\Pages\ListDriverPayouts;
use App\Filament\Resources\DriverPayoutResource\Pages\CreateDriverPayout;
use App\Filament\Resources\DriverPayoutResource\Pages\EditDriverPayout;
use App\Filament\Resources\DriverPayoutResource\Pages;
use App\Models\DriverPayout;
use App\Services\CommissionService;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DriverPayoutResource extends BaseResource
{
    protected static ?string $model = DriverPayout::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';

    protected static string | \UnitEnum | null $navigationGroup = 'Finance Management';

    protected static ?int $navigationSort = 8;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make()
                    ->schema([
                        Section::make('Driver Information')
                            ->schema([
                                Select::make('driver_id')
                                    ->relationship('driver', 'name', function ($query) {
                                        return $query->whereNotNull('name')->where('name', '!=', '');
                                    })
                                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?: 'Unknown Driver')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set) {
                                        if (!$state) return;

                                        $driver = User::find($state);
                                        if (!$driver) return;

                                        $driverProfile = $driver->driverProfile;

                                        $set('bank_name', $driverProfile?->bank_name ?? '');
                                        $set('account_number', $driverProfile?->account_number ?? '');
                                        $set('ifsc_code', $driverProfile?->ifsc_code ?? '');
                                    }),

                                TextInput::make('amount')
                                    ->numeric()
                                    ->prefix('₹')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, $record, Get $get) {
                                        if (!$state || !$get('driver_id')) return;

                                        $driver = User::find($get('driver_id'));
                                        $availableBalance = (float) ($driver?->wallet?->balance ?? 0);

                                        if (!$driver || $availableBalance < (float) $state) {
                                            Notification::make()
                                                ->title('Insufficient wallet balance')
                                                ->warning()
                                                ->send();
                                        }
                                    }),

                                Placeholder::make('available_balance')
                                    ->label('Available Balance')
                                    ->content(function ($get) {
                                        if (!$get('driver_id')) return '₹0.00';

                                        $driver = User::find($get('driver_id'));
                                        $availableBalance = (float) ($driver?->wallet?->balance ?? 0);
                                        return '₹' . number_format($availableBalance, 2);
                                    }),
                            ]),

                        Section::make('Bank Details')
                            ->schema([
                                TextInput::make('bank_name')
                                    ->required(),

                                TextInput::make('account_number')
                                    ->required(),

                                TextInput::make('ifsc_code')
                                    ->required(),
                            ]),

                        Section::make('Status')
                            ->schema([
                                Select::make('status')
                                    ->options([
                                        DriverPayout::STATUS_PENDING => 'Pending',
                                        DriverPayout::STATUS_PROCESSING => 'Processing',
                                        DriverPayout::STATUS_COMPLETED => 'Completed',
                                        DriverPayout::STATUS_FAILED => 'Failed',
                                        DriverPayout::STATUS_CANCELLED => 'Cancelled',
                                    ])
                                    ->disabled(fn($record) => $record && ($record->isCompleted() || $record->isCancelled()))
                                    ->required(),

                                TextInput::make('reference_number')
                                    ->visible(fn($get) => $get('status') === DriverPayout::STATUS_COMPLETED),

                                Textarea::make('failed_reason')
                                    ->visible(fn($get) => $get('status') === DriverPayout::STATUS_FAILED),

                                Textarea::make('cancelled_reason')
                                    ->visible(fn($get) => $get('status') === DriverPayout::STATUS_CANCELLED),
                            ]),
                    ])
                    ->columnSpan(['lg' => 2]),

                Group::make()
                    ->schema([
                        Section::make('Transactions')
                            ->schema([
                                Placeholder::make('transactions')
                                    ->content(fn($record) => $record ? view(
                                        'filament.resources.driver-payout.transactions',
                                        ['payout' => $record]
                                    ) : 'No transactions available for new payout'),
                            ]),

                        Section::make('Additional Information')
                            ->schema([
                                KeyValue::make('meta_data')
                                    ->disabled(),
                            ])
                            ->collapsed(),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('driver.name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('amount')
                    ->money('INR')
                    ->sortable(),

                TextColumn::make('bank_name')
                    ->searchable(),

                TextColumn::make('account_number')
                    ->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'pending' => 'gray',
                        'processing' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        'cancelled' => 'danger',
                    })
                    ->sortable(),

                TextColumn::make('reference_number')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('processed_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('driver')
                    ->relationship('driver', 'name')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                    ->searchable()
                    ->preload()
                    ->multiple(),

                SelectFilter::make('status')
                    ->options([
                        DriverPayout::STATUS_PENDING => 'Pending',
                        DriverPayout::STATUS_PROCESSING => 'Processing',
                        DriverPayout::STATUS_COMPLETED => 'Completed',
                        DriverPayout::STATUS_FAILED => 'Failed',
                        DriverPayout::STATUS_CANCELLED => 'Cancelled',
                    ]),

                Filter::make('date')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
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
                ActionGroup::make([
                    Action::make('process')
                        ->requiresConfirmation()
                        ->action(function (DriverPayout $record): void {
                            $record->markAsProcessing();
                        })
                        ->visible(fn(DriverPayout $record) => $record->isPending()),

                    Action::make('complete')
                        ->requiresConfirmation()
                        ->schema([
                            TextInput::make('reference_number')
                                ->label('Bank Reference Number')
                                ->required(),
                        ])
                        ->action(function (DriverPayout $record, array $data, CommissionService $service): void {
                            $service->completePayout($record, $data['reference_number']);
                        })
                        ->visible(fn(DriverPayout $record) => $record->isProcessing()),

                    Action::make('fail')
                        ->requiresConfirmation()
                        ->color('danger')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Failure Reason')
                                ->required(),
                        ])
                        ->action(function (DriverPayout $record, array $data): void {
                            $record->markAsFailed($data['reason']);
                        })
                        ->visible(fn(DriverPayout $record) => $record->isProcessing()),

                    Action::make('cancel')
                        ->requiresConfirmation()
                        ->color('danger')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Cancellation Reason')
                                ->required(),
                        ])
                        ->action(function (DriverPayout $record, array $data, CommissionService $service): void {
                            $service->cancelPayout($record, $data['reason']);
                        })
                        ->visible(fn(DriverPayout $record) => $record->isPending()),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('process')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                if ($record->isPending()) {
                                    $record->markAsProcessing();
                                }
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('cancel')
                        ->requiresConfirmation()
                        ->color('danger')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Cancellation Reason')
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data, CommissionService $service): void {
                            foreach ($records as $record) {
                                if ($record->isPending()) {
                                    $service->cancelPayout($record, $data['reason']);
                                }
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
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
            'index' => ListDriverPayouts::route('/'),
            'create' => CreateDriverPayout::route('/create'),
            'edit' => EditDriverPayout::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'pending')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::where('status', 'pending')->exists() ? 'warning' : null;
    }
}
