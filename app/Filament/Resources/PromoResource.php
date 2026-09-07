<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use App\Filament\Resources\PromoResource\Pages;
use App\Filament\Resources\PromoResource\RelationManagers;
use App\Models\PromoCode;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Collection;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

class PromoResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-gift';

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 6;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Basic Information')
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: PromoCode::class,
                                column: 'code',
                                ignoreRecord: true,
                                modifyRuleUsing: function (\Illuminate\Validation\Rules\Unique $rule) {
                                    return $rule->whereNull('deleted_at');
                                }
                            ),
                        Forms\Components\Select::make('type')
                            ->options([
                                'fixed' => 'Fixed Amount',
                                'percentage' => 'Percentage',


                            ])
                            ->required()
                            ->live(),
                        Forms\Components\TextInput::make('value')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->prefix(fn(Get $get) => $get('type') === 'percentage' ? '%' : '₹')
                            ->live()
                            ->rules([
                                function (Get $get) {
                                    return function (string $attribute, $value, \Closure $fail) use ($get) {
                                        if ($get('type') === 'percentage' && $value > 100) {
                                            $fail('The percentage value cannot be greater than 100.');
                                        }
                                    };
                                },
                            ]),
                        Forms\Components\Textarea::make('description')
                            ->required()
                            ->maxLength(65535),
                    ])->columns(2),

                Section::make('Validity')
                    ->schema([
                        Forms\Components\DateTimePicker::make('starts_at')
                            ->required()
                            ->live(),
                        Forms\Components\DateTimePicker::make('expires_at')
                            ->required()
                            ->afterOrEqual('starts_at')
                            ->rules([
                                function (Get $get) {
                                    return function (string $attribute, $value, \Closure $fail) use ($get) {
                                        $startsAt = $get('starts_at');
                                        if ($startsAt && $value && strtotime($value) < strtotime($startsAt)) {
                                            $fail('The expiry date must be after or equal to the start date.');
                                        }
                                    };
                                },
                            ]),
                        Forms\Components\Toggle::make('status')
                            ->required(),
                    ])->columns(3),

                Section::make('Usage Limits')
                    ->schema([
                        Forms\Components\TextInput::make('min_order_amount')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₹'),
                        Forms\Components\TextInput::make('max_discount_amount')
                            ->numeric()
                            ->prefix('₹')
                            ->visible(fn(Get $get) => in_array($get('type'), ['percentage', 'cashback'])),
                        Forms\Components\TextInput::make('max_uses_total')
                            ->numeric()
                            ->minValue(1),
                        Forms\Components\TextInput::make('max_uses_per_user')
                            ->numeric()
                            ->minValue(1),
                    ])->columns(2),

                Section::make('User Types')
                    ->schema([
                        Forms\Components\CheckboxList::make('user_types')
                            ->options([
                                'new' => 'New Users',
                                'existing' => 'Existing Users',

                                'all' => 'All Users',
                            ])
                            ->required(),
                    ]),

                Section::make('Referral Settings')
                    ->schema([
                        Forms\Components\TextInput::make('meta_data.referrer_reward')
                            ->label('Referrer Reward')
                            ->numeric()
                            ->prefix('₹')
                            ->visible(fn(Get $get) => $get('type') === 'referral'),
                        Forms\Components\TextInput::make('meta_data.referee_reward')
                            ->label('Referee Reward')
                            ->numeric()
                            ->prefix('₹')
                            ->visible(fn(Get $get) => $get('type') === 'referral'),
                    ])->columns(2)
                    ->visible(fn(Get $get) => $get('type') === 'referral'),

                Section::make('Applicability')
                    ->schema([
                        Forms\Components\Select::make('meta_data.applicable_cities')
                            ->label('Applicable Cities')
                            ->relationship('cities', 'name', function ($query) {
                                return $query->whereNotNull('name')->where('name', '!=', '');
                            })
                            ->multiple()
                            ->required()
                            ->preload(),
                        Forms\Components\Select::make('meta_data.applicable_ride_types')
                            ->label('Applicable Ride Types')
                            ->relationship('rideTypes', 'name', function ($query) {
                                return $query->whereNotNull('name')->where('name', '!=', '');
                            })
                            ->multiple()
                            ->required()
                            ->preload(),
                    ])->columns(2)
                    ->visible(fn(Get $get) => $get('type') !== 'referral'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'fixed' => 'success',
                        'percentage' => 'info',
                        'cashback' => 'warning',
                        'referral' => 'primary',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('value')
                    ->formatStateUsing(fn($record) => $record->type === 'percentage' ? "{$record->value}%" : "₹{$record->value}")
                    ->sortable(),
                Tables\Columns\TextColumn::make('min_order_amount')
                    ->money('INR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_discount_amount')
                    ->money('INR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_uses_total')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('usages_count')
                    ->label('Usage')
                    ->counts('usages')
                    ->formatStateUsing(function ($state, PromoCode $record) {
                        $totalUses = $state ?? 0;
                        $maxUses = $record->max_uses_total;

                        if ($maxUses) {
                            return "{$totalUses}/{$maxUses}";
                        }

                        return (string) $totalUses;
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_uses_per_user')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'expired' => 'Expired',
                        '1' => 'Active',
                        '0' => 'Inactive',
                        default => ucfirst($state),
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'expired' => 'danger',
                        '1' => 'success',
                        '0' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('starts_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'fixed' => 'Fixed Amount',
                        'percentage' => 'Percentage',
                        'cashback' => 'Cashback',
                        'referral' => 'Referral',
                    ])
                    ->multiple(),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'expired' => 'Expired',
                    ]),
                Tables\Filters\Filter::make('active_period')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn(Builder $query, $date): Builder => $query->where('expires_at', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn(Builder $query, $date): Builder => $query->where('starts_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalHeading('Delete Promo Code')
                    ->modalDescription('Are you sure you want to delete this promo code? This action cannot be undone.')
                    ->modalSubmitActionLabel('Yes, delete it')
                    ->requiresConfirmation(),
                Action::make('duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(function (PromoCode $record) {
                        $newPromo = $record->replicate();
                        $newPromo->code = $record->code . '_COPY';

                        unset($newPromo->usages_count);
                        $newPromo->save();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalHeading('Delete Selected Promo Codes')
                        ->modalDescription('Are you sure you want to delete the selected promo codes? This action cannot be undone.')
                        ->modalSubmitActionLabel('Yes, delete them')
                        ->requiresConfirmation(),
                    BulkAction::make('activate')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn(Collection $records) => $records->each->update(['status' => 'active']))
                        ->requiresConfirmation(),
                    BulkAction::make('deactivate')
                        ->icon('heroicon-o-x-circle')
                        ->action(fn(Collection $records) => $records->each->update(['status' => 'inactive']))
                        ->requiresConfirmation(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\UsagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromos::route('/'),
            'create' => Pages\CreatePromo::route('/create'),
            'edit' => Pages\EditPromo::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'active')
            ->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }
}
