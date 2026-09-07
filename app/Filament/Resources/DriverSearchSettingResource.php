<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Closure;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\DriverSearchSettingResource\Pages\ListDriverSearchSettings;
use App\Filament\Resources\DriverSearchSettingResource\Pages\CreateDriverSearchSetting;
use App\Filament\Resources\DriverSearchSettingResource\Pages\EditDriverSearchSetting;
use App\Filament\Resources\DriverSearchSettingResource\Pages;
use App\Models\DriverSearchSetting;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DriverSearchSettingResource extends Resource
{
    protected static ?string $model = DriverSearchSetting::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-map-pin';
    protected static string | \UnitEnum | null $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Driver Search Radius';
    protected static ?string $modelLabel = 'Driver Search Radius';
    protected static ?string $pluralModelLabel = 'Driver Search Radius Settings';

    protected static ?int $navigationSort = 17;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function getNavigationUrl(): string
    {

        $setting = DriverSearchSetting::first();

        if (!$setting) {

            $setting = DriverSearchSetting::create(DriverSearchSetting::defaults());
        }

        return static::getUrl('edit', ['record' => $setting]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Search radius per round')
                    ->columns(3)
                    ->schema([
                        TextInput::make('round1_radius_km')
                            ->label('Round 1 (km)')
                            ->numeric()
                            ->suffix('km')
                            ->default(5)
                            ->minValue(0.1)
                            ->required()
                            ->rules([
                                function ($get) {
                                    return function (string $attribute, $value, Closure $fail) use ($get) {
                                        $round2 = $get('round2_radius_km');
                                        if ($round2 && (float) $value >= (float) $round2) {
                                            $fail('Round 1 must be less than Round 2.');
                                        }
                                    };
                                },
                            ]),
                        TextInput::make('round2_radius_km')
                            ->label('Round 2 (km)')
                            ->numeric()
                            ->suffix('km')
                            ->default(10)
                            ->minValue(0.1)
                            ->required()
                            ->rules([
                                function ($get) {
                                    return function (string $attribute, $value, Closure $fail) use ($get) {
                                        $round1 = $get('round1_radius_km');
                                        $round3 = $get('round3_radius_km');

                                        if ($round1 && (float) $value <= (float) $round1) {
                                            $fail('Round 2 must be greater than Round 1.');
                                        }

                                        if ($round3 && (float) $value >= (float) $round3) {
                                            $fail('Round 2 must be less than Round 3.');
                                        }
                                    };
                                },
                            ]),
                        TextInput::make('round3_radius_km')
                            ->label('Round 3 (km)')
                            ->numeric()
                            ->suffix('km')
                            ->default(15)
                            ->minValue(0.1)
                            ->required()
                            ->rules([
                                function ($get) {
                                    return function (string $attribute, $value, Closure $fail) use ($get) {
                                        $round2 = $get('round2_radius_km');
                                        if ($round2 && (float) $value <= (float) $round2) {
                                            $fail('Round 3 must be greater than Round 2.');
                                        }
                                    };
                                },
                            ]),
                    ]),
                Section::make('Status')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active settings are used for driver search.'),
                    ]),
            ])
            ->statePath('data');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('round1_radius_km')
                    ->label('Round 1 (km)')
                    ->formatStateUsing(fn($state) => number_format((float) $state, 2)),
                TextColumn::make('round2_radius_km')
                    ->label('Round 2 (km)')
                    ->formatStateUsing(fn($state) => number_format((float) $state, 2)),
                TextColumn::make('round3_radius_km')
                    ->label('Round 3 (km)')
                    ->formatStateUsing(fn($state) => number_format((float) $state, 2)),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active status'),
            ])
            ->actions([
                EditAction::make(),
                Action::make('activate')
                    ->visible(fn(DriverSearchSetting $record) => !$record->is_active)
                    ->requiresConfirmation()
                    ->label('Set Active')
                    ->icon('heroicon-o-check')
                    ->action(function (DriverSearchSetting $record) {
                        DriverSearchSetting::query()->update(['is_active' => false]);
                        $record->update(['is_active' => true]);
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDriverSearchSettings::route('/'),
            'create' => CreateDriverSearchSetting::route('/create'),
            'edit' => EditDriverSearchSetting::route('/{record}/edit'),
        ];
    }
}
