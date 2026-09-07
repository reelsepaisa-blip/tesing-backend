<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\CashCollectionPointResource\Pages\ListCashCollectionPoints;
use App\Filament\Resources\CashCollectionPointResource\Pages\CreateCashCollectionPoint;
use App\Filament\Resources\CashCollectionPointResource\Pages\EditCashCollectionPoint;
use App\Filament\Resources\CashCollectionPointResource\Pages;
use App\Forms\Components\LocationPickerField;
use App\Models\CashCollectionPoint;
use App\Models\City;
use App\Services\GoogleMapsService;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CashCollectionPointResource extends Resource
{
    protected static ?string $model = CashCollectionPoint::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-office';
    protected static string | \UnitEnum | null $navigationGroup = 'Driver Management';
    protected static ?string $navigationLabel = 'Cash Collection Points';
    protected static ?string $modelLabel = 'Cash Collection Point';
    protected static ?string $pluralModelLabel = 'Cash Collection Points';
    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Collection Point Information')
                    ->description('Configure cash collection points for drivers to submit collected cash')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('city_id')
                                    ->label('City')
                                    ->relationship('city', 'name')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        if (empty($state)) {
                                            return;
                                        }

                                        $city = City::select('latitude', 'longitude')->find($state);

                                        if (!$city || $city->latitude === null || $city->longitude === null) {
                                            return;
                                        }

                                        if (!$get('latitude') || !$get('longitude')) {
                                            $set('latitude', (float) $city->latitude);
                                            $set('longitude', (float) $city->longitude);
                                        }
                                    }),








                                TextInput::make('name')
                                    ->label('Collection Point Name')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('e.g., Central Office, Zone 1 Collection Center'),
                            ]),



                        Grid::make(3)
                            ->schema([
                                TextInput::make('contact_person')
                                    ->label('Contact Person')
                                    ->maxLength(255)
                                    ->placeholder('Name of the contact person'),

                                TextInput::make('contact_phone')
                                    ->label('Contact Phone')
                                    ->numeric()
                                    ->maxLength(15)

                                    ->placeholder('Phone number for contact'),

                                TextInput::make('contact_email')
                                    ->label('Contact Email')
                                    ->email()
                                    ->maxLength(255)
                                    ->placeholder('Email address for contact'),
                            ]),

                        Textarea::make('address')
                            ->label('Address')
                            ->required()
                            ->rows(3)
                            ->placeholder('Full address of the collection point'),
                    ]),

                Section::make('Location & Operating Hours')
                    ->schema([
                        LocationPickerField::make('location_picker')
                            ->label('Select Location on Map')

                            ->latitudeField('latitude')
                            ->longitudeField('longitude')
                            ->columnSpanFull()
                            ->dehydrated(false),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('latitude')
                                    ->label('Latitude')
                                    ->numeric()
                                    ->step(0.00000001)
                                    ->placeholder('e.g., 19.0760')
                                    ->required()
                                    ->reactive()
                                    ->rules([
                                        function (Get $get) {
                                            return function (string $attribute, $value, Closure $fail) use ($get) {
                                                if (!$value || !$get('longitude') || !$get('city_id')) {
                                                    return;
                                                }

                                                static::validateLocationMatchesCity(
                                                    $value,
                                                    $get('longitude'),
                                                    $get('city_id'),
                                                    $fail
                                                );
                                            };
                                        },
                                    ]),

                                TextInput::make('longitude')
                                    ->label('Longitude')
                                    ->numeric()
                                    ->step(0.00000001)
                                    ->placeholder('e.g., 72.8777')
                                    ->required()
                                    ->reactive()
                                    ->rules([
                                        function (Get $get) {
                                            return function (string $attribute, $value, Closure $fail) use ($get) {
                                                if (!$value || !$get('latitude') || !$get('city_id')) {
                                                    return;
                                                }

                                                static::validateLocationMatchesCity(
                                                    $get('latitude'),
                                                    $value,
                                                    $get('city_id'),
                                                    $fail
                                                );
                                            };
                                        },
                                    ]),
                            ]),

                        Repeater::make('operating_hours')
                            ->label('Operating Hours')
                            ->schema([
                                Select::make('day')
                                    ->options([
                                        'monday' => 'Monday',
                                        'tuesday' => 'Tuesday',
                                        'wednesday' => 'Wednesday',
                                        'thursday' => 'Thursday',
                                        'friday' => 'Friday',
                                        'saturday' => 'Saturday',
                                        'sunday' => 'Sunday',
                                    ])
                                    ->required(),

                                TimePicker::make('open_time')
                                    ->label('Open Time')
                                    ->required(),

                                TimePicker::make('close_time')
                                    ->label('Close Time')
                                    ->required(),

                                Toggle::make('is_closed')
                                    ->label('Closed on this day')
                                    ->default(false),
                            ])
                            ->columns(4)
                            ->defaultItems(7)
                            ->collapsible(),
                    ]),

                Section::make('Status')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active collection points are visible to drivers'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('city.name')
                    ->label('City')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Collection Point')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('address')
                    ->label('Address')
                    ->limit(50)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();
                        return strlen($state) > 50 ? $state : null;
                    }),

                TextColumn::make('contact_person')
                    ->label('Contact Person')
                    ->searchable(),

                TextColumn::make('contact_phone')
                    ->label('Phone')
                    ->searchable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('city_id')
                    ->label('City')
                    ->relationship('city', 'name')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make()
                    ->action(function ($record) {
                        // Block deletion for restricted users (ID 2)
                        $userId = auth()->id();
                        if ($userId === 2) {
                            Notification::make()
                                ->title('Access Restricted')
                                ->body('In demo mode you are not deleting data...')
                                ->danger()
                                ->send();
                            return;
                        }
                        
                        // Proceed with normal deletion
                        $record->delete();
                        
                        Notification::make()
                            ->title('Deleted')
                            ->body('The cash collection point has been deleted.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function ($records) {
                            // Block deletion for restricted users (ID 2)
                            $userId = auth()->id();
                            if ($userId === 2) {
                                Notification::make()
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
                            
                            Notification::make()
                                ->title('Deleted')
                                ->body(count($records) . ' cash collection point(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('city_id', 'name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashCollectionPoints::route('/'),
            'create' => CreateCashCollectionPoint::route('/create'),
            'edit' => EditCashCollectionPoint::route('/{record}/edit'),
        ];
    }

    
    protected static function validateLocationMatchesCity(
        ?string $latitude,
        ?string $longitude,
        ?string $cityId,
        Closure $fail
    ): void {
        if (!$latitude || !$longitude || !$cityId) {
            return;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if ($lat === 0.0 && $lng === 0.0) {
            return;
        }

        $city = City::find($cityId);
        if (!$city) {
            return;
        }

        $mapsService = app(GoogleMapsService::class);
        $geocodeResult = $mapsService->reverseGeocode($lat, $lng);

        if (!$geocodeResult || empty($geocodeResult['components'])) {


            return;
        }

        $locationCityName = null;
        $locationState = null;

        foreach ($geocodeResult['components'] as $component) {
            if (in_array('locality', $component['types'])) {
                $locationCityName = $component['long_name'];
            }
            if (in_array('administrative_area_level_1', $component['types'])) {
                $locationState = $component['long_name'];
            }
        }

        if (!$locationCityName) {
            foreach ($geocodeResult['components'] as $component) {
                if (in_array('administrative_area_level_2', $component['types'])) {
                    $locationCityName = $component['long_name'];
                    break;
                }
            }
        }

        $citiesMatch = false;

        if ($locationCityName) {
            $normalizedSelectedCity = strtolower(trim($city->name));
            $normalizedLocationCity = strtolower(trim($locationCityName));

            $normalizedSelectedCity = preg_replace('/\s+(city|municipality|corporation)$/i', '', $normalizedSelectedCity);
            $normalizedLocationCity = preg_replace('/\s+(city|municipality|corporation)$/i', '', $normalizedLocationCity);

            $citiesMatch = $normalizedSelectedCity === $normalizedLocationCity;

            if (!$citiesMatch) {

                $stateMatches = false;
                if ($locationState && $city->state) {
                    $normalizedSelectedState = strtolower(trim($city->state));
                    $normalizedLocationState = strtolower(trim($locationState));
                    $stateMatches = $normalizedSelectedState === $normalizedLocationState;
                }

                if (!$stateMatches) {
                    $fail("The selected location appears to be outside {$city->name}, {$city->state}. The location is in {$locationCityName}" . ($locationState ? ", {$locationState}" : "") . ". Please select a location within the chosen city.");
                    return;
                }
            }
        }


        if (!$citiesMatch && $city->latitude && $city->longitude) {
            $distance = static::calculateDistance(
                $lat,
                $lng,
                (float) $city->latitude,
                (float) $city->longitude
            );


            if ($distance > 50) {
                $errorMessage = "The selected location is too far from {$city->name} (approximately " . round($distance, 1) . " km away).";
                if ($locationCityName) {
                    $errorMessage .= " The location is in {$locationCityName}" . ($locationState ? ", {$locationState}" : "") . ".";
                }
                $errorMessage .= " Please select a location within the chosen city.";
                $fail($errorMessage);
                return;
            }
        }
    }

    
    protected static function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // Earth's radius in kilometers

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        if ($a >= 1.0) {
            $a = 0.999999;
        }

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}
