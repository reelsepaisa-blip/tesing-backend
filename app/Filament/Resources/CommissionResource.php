<?php

namespace App\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use App\Models\Booking;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Placeholder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use App\Filament\Resources\CommissionResource\Pages\ListCommissions;
use App\Filament\Resources\CommissionResource\Pages\ViewCommission;
use App\Filament\Resources\CommissionResource\Pages;
use App\Models\Commission;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CommissionResource extends BaseResource
{
    protected static ?string $model = Commission::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance Management';

    protected static ?int $navigationSort = 8;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['booking', 'driver', 'rideType', 'transactions']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make()
                    ->schema([
                        Section::make('Booking Details')
                            ->schema([
                                TextInput::make('booking_code')
                                    ->label('Booking Code')
                                    ->disabled()
                                    ->afterStateHydrated(function ($component, $state, $record) {
                                        if ($record && $record->relationLoaded('booking') && $record->booking) {
                                            $component->state($record->booking->booking_code ?? '');
                                        } elseif ($record && $record->booking_id) {
                                            $booking = Booking::find($record->booking_id);
                                            $component->state($booking ? ($booking->booking_code ?? '') : '');
                                        }
                                    }),

                                Select::make('driver_id')
                                    ->relationship('driver', 'name', function ($query) {
                                        return $query->whereNotNull('name')->where('name', '!=', '');
                                    })
                                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?: 'Unknown Driver')
                                    ->searchable()
                                    ->preload()
                                    ->disabled(),

                                Select::make('ride_type_id')
                                    ->relationship('rideType', 'name', function ($query) {
                                        return $query->whereNotNull('name')->where('name', '!=', '');
                                    })
                                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?: 'Unknown Ride Type')
                                    ->searchable()
                                    ->preload()
                                    ->disabled(),
                            ]),

                        Section::make('Fare Details')
                            ->schema([
                                TextInput::make('base_fare')
                                    ->label('Base Fare')
                                    ->prefix('₹')
                                    ->disabled(),

                                TextInput::make('total_fare')
                                    ->label('Total Fare')
                                    ->prefix('₹')
                                    ->disabled(),

                                TextInput::make('commission_type')
                                    ->disabled(),

                                TextInput::make('commission_value')
                                    ->suffix(fn(Commission $record) => $record->isPercentageCommission() ? '%' : '₹')
                                    ->disabled(),
                            ]),

                        Section::make('Commission Breakdown')
                            ->schema([
                                TextInput::make('commission_amount')
                                    ->label('Commission Amount')
                                    ->prefix('₹')
                                    ->disabled(),

                                TextInput::make('tax_percentage')
                                    ->label('Tax Rate')
                                    ->suffix('%')
                                    ->disabled(),

                                TextInput::make('tax_amount')
                                    ->label('Tax Amount')
                                    ->prefix('₹')
                                    ->disabled(),

                                TextInput::make('driver_amount')
                                    ->label('Driver Earnings')
                                    ->prefix('₹')
                                    ->disabled(),
                            ]),
                    ])
                    ->columnSpan(['lg' => 2]),

                Group::make()
                    ->schema([
                        Section::make('Transaction Details')
                            ->schema([
                                Placeholder::make('transactions')
                                    ->content(fn(Commission $record) => view(
                                        'filament.resources.commission.transactions',
                                        ['commission' => $record]
                                    )),
                            ]),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking.booking_code')
                    ->label('Booking')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('driver.name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('rideType.name')
                    ->label('Service')
                    ->sortable(),

                TextColumn::make('total_fare')
                    ->label('Fare')
                    ->money('INR')
                    ->sortable(),

                TextColumn::make('commission_amount')
                    ->label('Commission')
                    ->money('INR')
                    ->sortable(),

                TextColumn::make('tax_amount')
                    ->label('Tax')
                    ->money('INR')
                    ->sortable()
                    ->getStateUsing(function (Commission $record) {
                        // If tax_amount is 0 or null, get from booking
                        $taxAmount = $record->tax_amount ?? 0;
                        if ($taxAmount == 0 && $record->relationLoaded('booking') && $record->booking) {
                            return $record->booking->tax_amount ?? 0;
                        }
                        return $taxAmount;
                    }),

                TextColumn::make('booking.debt_amount')
                    ->label('Debt Amount')
                    ->money('INR')
                    ->sortable()
                    ->default(0),

                TextColumn::make('driver_amount')
                    ->label('Driver Earnings')
                    ->money('INR')
                    ->sortable(),

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

                SelectFilter::make('ride_type')
                    ->relationship('rideType', 'name')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                    ->multiple(),

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
                ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommissions::route('/'),
            'view' => ViewCommission::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count() ?: null;
    }
}
