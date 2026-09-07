<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\DriverMatchingSettingResource\Pages\ListDriverMatchingSettings;
use App\Filament\Resources\DriverMatchingSettingResource\Pages\CreateDriverMatchingSetting;
use App\Filament\Resources\DriverMatchingSettingResource\Pages\EditDriverMatchingSetting;
use App\Filament\Resources\DriverMatchingSettingResource\Pages;
use App\Models\DriverMatchingSetting;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DriverMatchingSettingResource extends Resource
{
    protected static ?string $model = DriverMatchingSetting::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static string | \UnitEnum | null $navigationGroup = 'Ride Configuration';
    protected static ?string $navigationLabel = 'Matching Settings';
    protected static ?string $modelLabel = 'Driver Matching Setting';
    protected static ?string $pluralModelLabel = 'Driver Matching Settings';
    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Driver Matching Configuration')
                    ->description('Configure weighted parameters for driver ranking and matching')
                    ->schema([
                        Select::make('type')
                            ->label('Driver Type')
                            ->options([
                                'idle' => 'Idle Drivers',
                                'fallback' => 'Fallback (On-Trip) Drivers',
                            ])
                            ->required()
                            ->disabled(fn($record) => $record !== null)
                            ->helperText('Idle drivers are available drivers. Fallback drivers are currently on a trip but can accept new rides.'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active settings are used for driver matching'),
                    ]),

                Section::make('Idle Driver Scoring Parameters')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('weights.distance')
                                    ->label('Distance from Pickup (%)')
                                    ->helperText('Closer drivers preferred')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(35)
                                    ->suffix('%')
                                    ->required(),

                                TextInput::make('weights.trips_today')
                                    ->label('Trips Completed Today (%)')
                                    ->helperText('Fewer trips → higher fairness')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(20)
                                    ->suffix('%')
                                    ->required(),

                                TextInput::make('weights.rating')
                                    ->label('Driver Rating (%)')
                                    ->helperText('Rewards higher rated drivers')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(15)
                                    ->suffix('%')
                                    ->required(),

                                TextInput::make('weights.acceptance_rate')
                                    ->label('Acceptance Rate (%)')
                                    ->helperText('Reflects offer reliability')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(10)
                                    ->suffix('%')
                                    ->required(),

                                TextInput::make('weights.idle_time')
                                    ->label('Idle Time (%)')
                                    ->helperText('Rewards longer idle wait')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(10)
                                    ->suffix('%')
                                    ->required(),

                                TextInput::make('weights.cancel_rate')
                                    ->label('Ride Canceled by Driver (%)')
                                    ->helperText('Penalizes cancellations after accepting')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(10)
                                    ->suffix('%')
                                    ->required(),
                            ]),
                    ])
                    ->visible(fn($get) => $get('type') === 'idle'),

                Section::make('Fallback Driver Scoring Parameters')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('weights.d2p_distance')
                                    ->label('Drop → Pickup Distance (%)')
                                    ->helperText('Shorter chaining preferred')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(35)
                                    ->suffix('%')
                                    ->required(),

                                TextInput::make('weights.trips_today')
                                    ->label('Trips Completed Today (%)')
                                    ->helperText('Fewer trips → higher fairness')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(20)
                                    ->suffix('%')
                                    ->required(),

                                TextInput::make('weights.rating')
                                    ->label('Driver Rating (%)')
                                    ->helperText('Maintains quality')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(15)
                                    ->suffix('%')
                                    ->required(),

                                TextInput::make('weights.acceptance_rate')
                                    ->label('Acceptance Rate (%)')
                                    ->helperText('Prioritizes consistent drivers')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(10)
                                    ->suffix('%')
                                    ->required(),

                                TextInput::make('weights.fairness_balance')
                                    ->label('Trips Fairness Balance (%)')
                                    ->helperText('Avoids over-repeating same top drivers')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(10)
                                    ->suffix('%')
                                    ->required(),

                                TextInput::make('weights.cancel_rate')
                                    ->label('Ride Canceled by Driver (%)')
                                    ->helperText('Penalizes post-acceptance cancellations')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(10)
                                    ->suffix('%')
                                    ->required(),
                            ]),
                    ])
                    ->visible(fn($get) => $get('type') === 'fallback'),

                Section::make('Weight Validation')
                    ->schema([
                        Placeholder::make('weight_total')
                            ->label('Total Weight')
                            ->content(function ($get) {
                                $weights = $get('weights') ?? [];
                                $total = array_sum($weights);
                                $isValid = abs($total - 100) < 0.01;

                                return view('filament.components.weight-total', [
                                    'total' => $total,
                                    'isValid' => $isValid,
                                ]);
                            }),
                    ])
                    ->collapsible(),
            ])
            ->statePath('data');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('Driver Type')
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'idle' => 'Idle Drivers',
                        'fallback' => 'Fallback Drivers',
                        default => $state,
                    })
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'idle' => 'success',
                        'fallback' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('weights_summary')
                    ->label('Weight Configuration')
                    ->getStateUsing(function (DriverMatchingSetting $record): string {
                        $weights = $record->weights ?? [];
                        $summary = [];
                        foreach ($weights as $param => $weight) {
                            $summary[] = ucfirst(str_replace('_', ' ', $param)) . ': ' . $weight . '%';
                        }
                        return implode(', ', $summary);
                    })
                    ->wrap(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'idle' => 'Idle Drivers',
                        'fallback' => 'Fallback Drivers',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ])
            ->actions([
                EditAction::make(),
                Action::make('reset_to_defaults')
                    ->label('Reset to Defaults')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (DriverMatchingSetting $record) {
                        $defaults = $record->type === 'idle'
                            ? DriverMatchingSetting::getDefaultIdleWeights()
                            : DriverMatchingSetting::getDefaultFallbackWeights();

                        $record->update(['weights' => $defaults]);
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('type');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDriverMatchingSettings::route('/'),
            'create' => CreateDriverMatchingSetting::route('/create'),
            'edit' => EditDriverMatchingSetting::route('/{record}/edit'),
        ];
    }
}
