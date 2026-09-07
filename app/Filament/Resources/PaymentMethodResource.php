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
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;

use App\Filament\Resources\PaymentMethodResource\Pages;
use App\Models\PaymentMethod;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Payment Methods';

    protected static ?string $modelLabel = 'Payment Method';

    protected static ?string $pluralModelLabel = 'Payment Methods';

    protected static string | \UnitEnum | null $navigationGroup = 'Payment Management';

    protected static ?int $navigationSort = 13;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Basic Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        Forms\Components\TextInput::make('code')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Unique identifier for the payment method (e.g., cash, paypal, razorpay)')
                            ->columnSpan(2),

                        Forms\Components\Select::make('type')
                            ->required()
                            ->options([
                                PaymentMethod::TYPE_CASH => 'Cash',
                                PaymentMethod::TYPE_CARD => 'Card',
                                PaymentMethod::TYPE_WALLET => 'Wallet',
                                PaymentMethod::TYPE_ONLINE => 'Online',
                            ])
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->columnSpan(1),

                        Forms\Components\Textarea::make('description')
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Display Settings')
                    ->schema([
                        Forms\Components\TextInput::make('icon')
                            ->maxLength(255)
                            ->helperText('Icon class or URL (e.g., heroicon-o-credit-card)')
                            ->columnSpan(1),

                        Forms\Components\ColorPicker::make('color')
                            ->columnSpan(1),

                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->columnSpan(1),

                        Forms\Components\Toggle::make('is_online')
                            ->default(false)
                            ->columnSpan(1),

                        Forms\Components\Toggle::make('requires_verification')
                            ->default(false)
                            ->columnSpan(1),
                    ])
                    ->columns(2),

                Section::make('Amount Limits')
                    ->schema([
                        Forms\Components\TextInput::make('min_amount')
                            ->numeric()
                            ->prefix('₹')
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('max_amount')
                            ->numeric()
                            ->prefix('₹')
                            ->columnSpan(1),
                    ])
                    ->columns(2),

                Section::make('Processing Fees')
                    ->schema([
                        Forms\Components\TextInput::make('processing_fee_percentage')
                            ->numeric()
                            ->suffix('%')
                            ->default(0)
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('processing_fee_fixed')
                            ->numeric()
                            ->prefix('₹')
                            ->default(0)
                            ->columnSpan(1),
                    ])
                    ->columns(2),

                Section::make('Status & Configuration')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->required()
                            ->options([
                                PaymentMethod::STATUS_ACTIVE => 'Active',
                                PaymentMethod::STATUS_INACTIVE => 'Inactive',
                                PaymentMethod::STATUS_MAINTENANCE => 'Maintenance',
                            ])
                            ->columnSpan(1),

                        Forms\Components\Textarea::make('status_message')
                            ->maxLength(500)
                            ->helperText('Message to show when payment method is not available')
                            ->columnSpan(1),

                        Forms\Components\Textarea::make('configuration')
                            ->helperText('Payment gateway specific configuration (JSON format)')
                            ->rows(4)
                            ->columnSpanFull()
                            ->formatStateUsing(fn($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state)
                            ->dehydrateStateUsing(function ($state) {

                                if (is_array($state)) {
                                    return $state;
                                }

                                if (is_string($state) && !empty($state)) {
                                    $decoded = json_decode($state, true);
                                    if (json_last_error() !== JSON_ERROR_NONE) {
                                        throw new \Exception('Invalid JSON format in configuration field');
                                    }
                                    return $decoded;
                                }

                                return null;
                            })
                            ->rule(function () {
                                return function (string $attribute, $value, \Closure $fail) {
                                    if (empty($value)) {
                                        return; // Allow empty values
                                    }

                                    if (is_array($value)) {
                                        return;
                                    }

                                    if (is_string($value)) {
                                        $decoded = json_decode($value, true);
                                        if (json_last_error() !== JSON_ERROR_NONE) {
                                            $fail('The configuration must be valid JSON format.');
                                        }
                                    }
                                };
                            }),

                        Forms\Components\TagsInput::make('supported_currencies')
                            ->helperText('Supported currencies (e.g., INR, USD)')
                            ->columnSpan(1)
                            ->formatStateUsing(fn($state) => is_array($state) ? $state : [])
                            ->dehydrateStateUsing(fn($state) => is_array($state) ? $state : []),

                        Forms\Components\TagsInput::make('supported_countries')
                            ->helperText('Supported countries (e.g., IN, US)')
                            ->columnSpan(1)
                            ->formatStateUsing(fn($state) => is_array($state) ? $state : [])
                            ->dehydrateStateUsing(fn($state) => is_array($state) ? $state : []),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        PaymentMethod::TYPE_CASH => 'success',
                        PaymentMethod::TYPE_CARD => 'primary',
                        PaymentMethod::TYPE_WALLET => 'info',
                        PaymentMethod::TYPE_ONLINE => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        PaymentMethod::TYPE_CASH => 'Cash',
                        PaymentMethod::TYPE_CARD => 'Card',
                        PaymentMethod::TYPE_WALLET => 'Wallet',
                        PaymentMethod::TYPE_ONLINE => 'Online',
                        default => $state,
                    }),

                Tables\Columns\IconColumn::make('is_online')
                    ->boolean()
                    ->label('Online'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        PaymentMethod::STATUS_ACTIVE => 'success',
                        PaymentMethod::STATUS_INACTIVE => 'danger',
                        PaymentMethod::STATUS_MAINTENANCE => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        PaymentMethod::STATUS_ACTIVE => 'Active',
                        PaymentMethod::STATUS_INACTIVE => 'Inactive',
                        PaymentMethod::STATUS_MAINTENANCE => 'Maintenance',
                        default => $state,
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),

                Tables\Columns\TextColumn::make('processing_fee_percentage')
                    ->suffix('%')
                    ->sortable()
                    ->label('Fee %'),

                Tables\Columns\TextColumn::make('processing_fee_fixed')
                    ->money('INR')
                    ->sortable()
                    ->label('Fixed Fee'),

                Tables\Columns\TextColumn::make('sort_order')
                    ->sortable()
                    ->label('Order'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        PaymentMethod::TYPE_CASH => 'Cash',
                        PaymentMethod::TYPE_CARD => 'Card',
                        PaymentMethod::TYPE_WALLET => 'Wallet',
                        PaymentMethod::TYPE_ONLINE => 'Online',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        PaymentMethod::STATUS_ACTIVE => 'Active',
                        PaymentMethod::STATUS_INACTIVE => 'Inactive',
                        PaymentMethod::STATUS_MAINTENANCE => 'Maintenance',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),

                Tables\Filters\TernaryFilter::make('is_online')
                    ->label('Online Payment'),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                 Action::make('toggle_status')
                    ->label(fn(PaymentMethod $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn(PaymentMethod $record): string => $record->is_active ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn(PaymentMethod $record): string => $record->is_active ? 'danger' : 'success')
                    ->action(function (PaymentMethod $record) {
                        $record->update(['is_active' => !$record->is_active]);
                    })
                    ->requiresConfirmation(),

                 Action::make('set_maintenance')
                    ->label('Set Maintenance')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('warning')
                    ->visible(fn(PaymentMethod $record): bool => $record->status !== PaymentMethod::STATUS_MAINTENANCE)
                    ->form([
                        Forms\Components\Textarea::make('status_message')
                            ->label('Maintenance Message')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (PaymentMethod $record, array $data) {
                        $record->update([
                            'status' => PaymentMethod::STATUS_MAINTENANCE,
                            'status_message' => $data['status_message'],
                        ]);
                    }),

                 Action::make('set_active')
                    ->label('Set Active')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(PaymentMethod $record): bool => $record->status !== PaymentMethod::STATUS_ACTIVE)
                    ->action(function (PaymentMethod $record) {
                        $record->update([
                            'status' => PaymentMethod::STATUS_ACTIVE,
                            'status_message' => null,
                        ]);
                    }),

                 EditAction::make(),
                 DeleteAction::make(),
            ])
            ->bulkActions([
                 BulkActionGroup::make([
                     DeleteBulkAction::make(),
                     ForceDeleteBulkAction::make(),
                     RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order');
    }

    public static function getRelations(): array
    {
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentMethods::route('/'),
            'create' => Pages\CreatePaymentMethod::route('/create'),
            'edit' => Pages\EditPaymentMethod::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }
}
