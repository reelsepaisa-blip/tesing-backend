<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Utilities\Set;

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

use App\Filament\Resources\PromoCodeResource\Pages;
use App\Models\PromoCode;
use App\Services\PromoService;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Forms;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;

class PromoCodeResource extends BaseResource
{
    protected static ?string $model = PromoCode::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-ticket';

    protected static string | \UnitEnum | null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 6;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Group::make()
                    ->schema([
                        Section::make('Basic Information')
                            ->schema([
                                Forms\Components\TextInput::make('code')
                                    ->required()
                                    ->unique(
                                        table: PromoCode::class,
                                        column: 'code',
                                        ignoreRecord: true,
                                        modifyRuleUsing: function (\Illuminate\Validation\Rules\Unique $rule) {
                                            return $rule->whereNull('deleted_at');
                                        }
                                    )
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set, PromoService $service) {
                                        if (!$state)
                                            return;
                                        $set('code', strtoupper($state));
                                    }),
                                Forms\Components\TextInput::make('description')
                                    ->required(),
                                Forms\Components\Select::make('type')
                                    ->options([
                                        PromoCode::TYPE_FIXED => 'Fixed Amount',
                                        PromoCode::TYPE_PERCENTAGE => 'Percentage',
                                    ])
                                    ->required()
                                    ->live(),
                                Forms\Components\TextInput::make('value')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->prefix(fn($get) => $get('type') === PromoCode::TYPE_PERCENTAGE ? '' : '₹')
                                    ->suffix(fn($get) => $get('type') === PromoCode::TYPE_PERCENTAGE ? '%' : '')
                                    ->live()
                                    ->rules([
                                        function ($get) {
                                            return function (string $attribute, $value, \Closure $fail) use ($get) {
                                                if ($get('type') === PromoCode::TYPE_PERCENTAGE) {
                                                    if ($value > 100) {
                                                        $fail('The percentage value cannot be greater than 100.');
                                                    }
                                                    if ($value <= 0) {
                                                        $fail('The percentage value must be greater than 0.');
                                                    }
                                                } else {
                                                    if ($value <= 0) {
                                                        $fail('The discount value must be greater than 0.');
                                                    }
                                                }
                                            };
                                        },
                                    ])
                                    ->helperText(fn($get) => $get('type') === PromoCode::TYPE_PERCENTAGE
                                        ? 'Enter the percentage value (e.g., 50 for 50% off)'
                                        : 'Enter the fixed discount amount'),

                                Forms\Components\TextInput::make('max_discount_amount')
                                    ->label('Max Discount Amount')
                                    ->numeric()
                                    ->prefix('₹')
                                    ->minValue(0)
                                    ->visible(fn($get) => $get('type') === PromoCode::TYPE_PERCENTAGE)
                                    ->helperText(function ($get) {
                                        $percentageValue = $get('value');
                                        $maxDiscount = $get('max_discount_amount');

                                        if ($percentageValue && $maxDiscount) {
                                            // Calculate what fare amount would hit this max discount
                                            $fareAtMaxDiscount = ($maxDiscount * 100) / $percentageValue;
                                            return "Maximum discount cap: ₹{$maxDiscount}. For a {$percentageValue}% discount, this limit will apply to fares above ₹{$fareAtMaxDiscount}. Leave empty for no limit.";
                                        }
                                        return 'Maximum discount cap in rupees. Leave empty for no limit.';
                                    })
                                    ->rules([
                                        function ($get) {
                                            return function (string $attribute, $value, \Closure $fail) use ($get) {
                                                $type = $get('type');
                                                $percentageValue = $get('value');

                                                if ($type === PromoCode::TYPE_PERCENTAGE && $value && $percentageValue) {
                                                    // Calculate minimum reasonable max discount based on percentage
                                                    // For 50% off, ₹1 is way too low - users would get almost no benefit
                                                    $minReasonableDiscount = max(10, $percentageValue * 0.5); // At least 50% of percentage or ₹10

                                                    if ($value < $minReasonableDiscount) {
                                                        $suggestedAmount = $percentageValue * 5; // Suggest 5x the percentage as a reasonable cap
                                                        $fail("⚠️ Warning: Max discount of ₹{$value} is very low for {$percentageValue}% off. Users will only get ₹{$value} discount even on large fares. Consider setting at least ₹{$minReasonableDiscount} or higher (e.g., ₹{$suggestedAmount}), or leave empty for no limit.");
                                                    }
                                                }
                                            };
                                        },
                                    ]),

                                Forms\Components\TextInput::make('min_order_amount')
                                    ->label('Minimum Order Amount')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->prefix('₹'),
                            ]),
                        Section::make('Usage Limits')
                            ->schema([
                                Forms\Components\TextInput::make('max_uses_per_user')
                                    ->numeric()
                                    ->label('Max Uses Per User'),
                                Forms\Components\TextInput::make('max_uses_total')
                                    ->numeric()
                                    ->label('Max Total Uses'),
                            ]),
                        Section::make('Validity Period')
                            ->schema([
                                Forms\Components\DateTimePicker::make('starts_at')
                                    ->label('Start Date')
                                    ->live(),
                                Forms\Components\DateTimePicker::make('expires_at')
                                    ->label('Expiry Date')
                                    ->afterOrEqual('starts_at')
                                    ->rules([
                                        function ($get) {
                                            return function (string $attribute, $value, \Closure $fail) use ($get) {
                                                $startsAt = $get('starts_at');
                                                if ($startsAt && $value && strtotime($value) < strtotime($startsAt)) {
                                                    $fail('The expiry date must be after or equal to the start date.');
                                                }
                                            };
                                        },
                                    ]),
                                Forms\Components\Select::make('status')
                                    ->options([
                                        PromoCode::STATUS_ACTIVE => 'Active',
                                        PromoCode::STATUS_INACTIVE => 'Inactive',
                                        PromoCode::STATUS_EXPIRED => 'Expired',
                                    ])
                                    ->required(),
                            ]),
                        Section::make('Restrictions')
                            ->schema([
                                Forms\Components\Toggle::make('is_first_ride_only')
                                    ->label('First Ride Only'),
                                Forms\Components\Select::make('city_ids')
                                    ->label('Restrict to Cities')
                                    ->multiple()
                                    ->relationship('cities', 'name', function ($query) {
                                        return $query->whereNotNull('name')->where('name', '!=', '');
                                    })
                                    ->searchable()
                                    ->preload(),
                                Forms\Components\Select::make('ride_type_ids')
                                    ->label('Restrict to Ride Types')
                                    ->multiple()
                                    ->relationship('rideTypes', 'name', function ($query) {
                                        return $query->whereNotNull('name')->where('name', '!=', '');
                                    })
                                    ->searchable()
                                    ->preload(),
                            ]),
                        Section::make('Referral Settings')
                            ->schema([
                                Forms\Components\Toggle::make('is_referral_code')
                                    ->label('Is Referral Code')
                                    ->live(),
                                Forms\Components\Select::make('referral_user_id')
                                    ->label('Referral User')
                                    ->relationship('referralUser', 'name', function ($query) {
                                        return $query->whereNotNull('name')->where('name', '!=', '');
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->visible(fn($get) => $get('is_referral_code')),
                            ])
                            ->collapsed(),
                    ])
                    ->columnSpan(['lg' => 2]),
                Group::make()
                    ->schema([
                        Section::make('Usage Statistics')
                            ->schema([
                                Forms\Components\Placeholder::make('usage_stats')
                                    ->content(fn(PromoCode $record) => view(
                                        'filament.resources.promo-code.usage-stats',
                                        ['promoCode' => $record]
                                    )),
                            ]),
                        Section::make('Recent Usage')
                            ->schema([
                                Forms\Components\Placeholder::make('recent_usage')
                                    ->content(fn(PromoCode $record) => view(
                                        'filament.resources.promo-code.recent-usage',
                                        ['promoCode' => $record]
                                    )),
                            ])
                            ->collapsed(),
                        Section::make('Additional Information')
                            ->schema([
                                Forms\Components\KeyValue::make('meta_data')
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
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('description')
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'fixed' => 'success',
                        'percentage' => 'info',
                        'cashback' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('value')
                    ->label('Value')
                    ->formatStateUsing(
                        fn(PromoCode $record) =>
                        $record->type === PromoCode::TYPE_PERCENTAGE
                            ? $record->value . '%'
                            : '₹' . number_format($record->value, 2)
                    )
                    ->sortable(),
                Tables\Columns\TextColumn::make('usages_count')
                    ->label('Uses')
                    ->counts('usages')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'active', '1', 1 => 'success',
                        'inactive', '0', 0 => 'gray',
                        'expired' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        PromoCode::TYPE_FIXED => 'Fixed',
                        PromoCode::TYPE_PERCENTAGE => 'Percentage',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        PromoCode::STATUS_ACTIVE => 'Active',
                        PromoCode::STATUS_INACTIVE => 'Inactive',
                        PromoCode::STATUS_EXPIRED => 'Expired',
                    ]),
                Tables\Filters\TernaryFilter::make('is_referral_code')
                    ->label('Referral Codes'),
                Tables\Filters\TernaryFilter::make('is_first_ride_only')
                    ->label('First Ride Only'),
                Tables\Filters\Filter::make('expired')
                    ->query(fn(Builder $query): Builder => $query->expired()),
                Tables\Filters\Filter::make('active')
                    ->query(fn(Builder $query): Builder => $query->active()),
            ])
            ->actions([
                 EditAction::make(),
                 DeleteAction::make()
                    ->action(function ($record) {
                        // Block deletion for restricted users (ID 2)
                        $userId = auth()->id();
                        if ($userId === 2) {
                            \Filament\Notifications\Notification::make()
                                ->title('Access Restricted')
                                ->body('In demo mode you are not deleting data...')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Proceed with normal deletion
                        $record->delete();

                        \Filament\Notifications\Notification::make()
                            ->title('Deleted')
                            ->body('The promo code has been deleted.')
                            ->success()
                            ->send();
                    }),
                 ActionGroup::make([
                     Action::make('generate_code')
                        ->label('Generate Code')
                        ->icon('heroicon-o-sparkles')
                        ->action(function (PromoCode $record, PromoService $service) {
                            $record->update(['code' => $service->generateCode()]);
                        })
                        ->visible(fn(PromoCode $record) => !$record->is_referral_code),
                     Action::make('duplicate')
                        ->label('Duplicate')
                        ->icon('heroicon-o-document-duplicate')
                        ->action(function (PromoCode $record) {
                            $newCode = $record->replicate();
                            $newCode->code = $record->code . '_COPY';
                            $newCode->status = PromoCode::STATUS_INACTIVE;
                            $newCode->save();
                        }),
                     Action::make('deactivate')
                        ->label('Deactivate')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (PromoCode $record) {
                            $record->update(['status' => PromoCode::STATUS_INACTIVE]);
                        })
                        ->visible(fn(PromoCode $record) => $record->status === PromoCode::STATUS_ACTIVE),
                     Action::make('activate')
                        ->label('Activate')
                        ->color('success')
                        ->action(function (PromoCode $record) {
                            $record->update(['status' => PromoCode::STATUS_ACTIVE]);
                        })
                        ->visible(fn(PromoCode $record) => $record->status === PromoCode::STATUS_INACTIVE),
                ]),
            ])
            ->bulkActions([
                 BulkActionGroup::make([
                     DeleteBulkAction::make()
                        ->action(function ($records) {
                            // Block deletion for restricted users (ID 2)
                            $userId = auth()->id();
                            if ($userId === 2) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Access Restricted')
                                    ->body('In demo mode you are not deleting data...')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            // Default bulk delete behavior
                            foreach ($records as $record) {
                                $record->delete();
                            }

                            \Filament\Notifications\Notification::make()
                                ->title('Deleted')
                                ->body(count($records) . ' promo code(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
                     BulkAction::make('activate')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                            $records->each(function ($record) {
                                if ($record->status === PromoCode::STATUS_INACTIVE) {
                                    $record->update(['status' => PromoCode::STATUS_ACTIVE]);
                                }
                            });
                        })
                        ->deselectRecordsAfterCompletion(),
                     BulkAction::make('deactivate')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                            $records->each(function ($record) {
                                if ($record->status === PromoCode::STATUS_ACTIVE) {
                                    $record->update(['status' => PromoCode::STATUS_INACTIVE]);
                                }
                            });
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromoCodes::route('/'),
            'create' => Pages\CreatePromoCode::route('/create'),
            'edit' => Pages\EditPromoCode::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count() ?: null;
    }
}
