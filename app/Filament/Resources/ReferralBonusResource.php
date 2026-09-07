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

use App\Filament\Resources\ReferralBonusResource\Pages;
use App\Models\ReferralBonus;
use App\Services\PromoService;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReferralBonusResource extends BaseResource
{
    protected static ?string $model = ReferralBonus::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-gift';

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
                        Section::make('Referral Information')
                            ->schema([
                                Forms\Components\Select::make('referrer_id')
                                    ->relationship('referrer', 'name')
                                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\Select::make('referred_id')
                                    ->relationship('referred', 'name')
                                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\Select::make('type')
                                    ->options([
                                        'referrer_bonus' => 'Referrer Bonus',
                                        'referred_bonus' => 'Referred Bonus',
                                    ])
                                    ->required(),

                                Forms\Components\TextInput::make('amount')
                                    ->numeric()
                                    ->prefix('₹')
                                    ->required(),
                            ]),

                        Section::make('Status & Timing')
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->options([
                                        ReferralBonus::STATUS_PENDING => 'Pending',
                                        ReferralBonus::STATUS_CREDITED => 'Credited',
                                        ReferralBonus::STATUS_EXPIRED => 'Expired',
                                        ReferralBonus::STATUS_CANCELLED => 'Cancelled',
                                    ])
                                    ->required(),

                                Forms\Components\DateTimePicker::make('credited_at')
                                    ->visible(fn($get) => $get('status') === ReferralBonus::STATUS_CREDITED),

                                Forms\Components\DateTimePicker::make('expires_at')
                                    ->required(),

                                Forms\Components\Textarea::make('cancelled_reason')
                                    ->visible(fn($get) => $get('status') === ReferralBonus::STATUS_CANCELLED),
                            ]),
                    ])
                    ->columnSpan(['lg' => 2]),

                Group::make()
                    ->schema([
                        Section::make('Transaction Details')
                            ->schema([
                                Forms\Components\Placeholder::make('transaction')
                                    ->content(fn(?ReferralBonus $record) => $record ? view(
                                        'filament.resources.referral-bonus.transaction',
                                        ['bonus' => $record]
                                    ) : 'Transaction details will be available after creating the referral bonus.'),
                            ]),

                        Section::make('Additional Information')
                            ->schema([
                                Forms\Components\KeyValue::make('meta_data')
                                    ->disabled(),
                            ])
                            ->hidden()
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
                Tables\Columns\TextColumn::make('referrer.name')
                    ->label('Referrer')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('referred.name')
                    ->label('Referred')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'referrer_bonus' => 'success',
                        'referred_bonus' => 'info',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->money('INR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'pending' => 'warning',
                        'credited' => 'success',
                        'expired' => 'danger',
                        'cancelled' => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('credited_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
                        'referrer_bonus' => 'Referrer Bonus',
                        'referred_bonus' => 'Referred Bonus',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        ReferralBonus::STATUS_PENDING => 'Pending',
                        ReferralBonus::STATUS_CREDITED => 'Credited',
                        ReferralBonus::STATUS_EXPIRED => 'Expired',
                        ReferralBonus::STATUS_CANCELLED => 'Cancelled',
                    ]),

                Tables\Filters\Filter::make('expired')
                    ->query(fn(Builder $query): Builder => $query->expired()),

                Tables\Filters\Filter::make('pending')
                    ->query(fn(Builder $query): Builder => $query->pending()),

                Tables\Filters\Filter::make('credited')
                    ->query(fn(Builder $query): Builder => $query->credited()),
            ])
            ->actions([
                 EditAction::make(),
                 ActionGroup::make([
                     Action::make('credit')
                        ->label('Credit Bonus')
                        ->icon('heroicon-o-banknotes')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (ReferralBonus $record, PromoService $service) {
                            if ($record->markAsCredited()) {
                                $service->processReferralBonuses($record->referrer);
                            }
                        })
                        ->visible(fn(ReferralBonus $record) => $record->isPending()),

                     Action::make('expire')
                        ->label('Mark Expired')
                        ->icon('heroicon-o-clock')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (ReferralBonus $record) {
                            $record->markAsExpired();
                        })
                        ->visible(fn(ReferralBonus $record) => $record->isPending()),

                     Action::make('cancel')
                        ->label('Cancel')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Cancellation Reason')
                                ->required(),
                        ])
                        ->action(function (ReferralBonus $record, array $data) {
                            $record->cancel($data['reason']);
                        })
                        ->visible(fn(ReferralBonus $record) => $record->isPending()),
                ]),
            ])
            ->bulkActions([
                 BulkActionGroup::make([
                     BulkAction::make('credit')
                        ->label('Credit Selected')
                        ->icon('heroicon-o-banknotes')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, PromoService $service) {
                            $records->each(function ($record) use ($service) {
                                if ($record->isPending()) {
                                    if ($record->markAsCredited()) {
                                        $service->processReferralBonuses($record->referrer);
                                    }
                                }
                            });
                        })
                        ->deselectRecordsAfterCompletion(),

                     BulkAction::make('expire')
                        ->label('Mark Expired')
                        ->icon('heroicon-o-clock')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $records->each(function ($record) {
                                if ($record->isPending()) {
                                    $record->markAsExpired();
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
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferralBonuses::route('/'),
            'create' => Pages\CreateReferralBonus::route('/create'),
            'edit' => Pages\EditReferralBonus::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }
}
