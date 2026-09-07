<?php

namespace App\Filament\Resources\RideTypeResource\Pages;

use App\Filament\Resources\RideTypeResource;
use App\Models\City;
use App\Models\RideType;
use Filament\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use App\Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\HtmlString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;


class CreateRideType extends CreateRecord
{

    protected static string $resource = RideTypeResource::class;

    public $selectedCities = [];

    public function mount(): void
    {
        parent::mount();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Tabs::make('RideTypeTabs')
                    ->tabs([
                        Tab::make('General Information')
                            ->schema([
                                \Filament\Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->label('Ride Type Name'),
                                \Filament\Schemas\Components\Grid::make(2)
                                    ->schema([
                                        \Filament\Forms\Components\TextInput::make('code')
                                            ->required()
                                            ->maxLength(255)
                                            ->label('Code')
                                            ->rules([
                                                'required',
                                                'string',
                                                'max:255',
                                                function () {
                                                    return function (string $attribute, $value, \Closure $fail) {
                                                        $exists = RideType::where('code', $value)
                                                            ->whereNull('deleted_at')
                                                            ->exists();
                                                        if ($exists) {
                                                            $fail('The code has already been taken.');
                                                        }
                                                    };
                                                },
                                            ])
                                            ->helperText('Unique identifier for this ride type (e.g., TAXI, PREMIUM, ECONOMY)'),
                                        \Filament\Forms\Components\TextInput::make('capacity')
                                            ->label('Seating Capacity')
                                            ->required()
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(20)
                                            ->default(4)
                                            ->helperText('Total number of passengers this ride type can accommodate.'),
                                    ]),

                                \Filament\Forms\Components\TextInput::make('description')
                                    ->maxLength(255)
                                    ->label('Description'),

                                \Filament\Forms\Components\FileUpload::make('icon')
                                    ->image()
                                    ->directory('ride-types')
                                    ->label('Icon')
                                    ->rules([
                                        function () {
                                            return function (string $attribute, $value, \Closure $fail) {
                                                if (!$value) {
                                                    return;
                                                }

                                                try {
                                                    // Check if file exists and get size safely
                                                    if (is_string($value)) {
                                                        // Already stored file path - skip validation
                                                        return;
                                                    }

                                                    // For Livewire TemporaryUploadedFile
                                                    if (method_exists($value, 'getSize')) {
                                                        try {
                                                            $size = $value->getSize();
                                                            $maxSize = 512 * 1024; // 512 KB in bytes
                                
                                                            if ($size > $maxSize) {
                                                                $fail('The icon file size must not exceed 512 KB.');
                                                            }
                                                        } catch (\League\Flysystem\UnableToRetrieveMetadata $e) {
                                                            // File was deleted or inaccessible - skip size validation
                                                            // This can happen if the temp file was cleaned up before validation
                                                            // Don't fail validation - file may have been cleaned up
                                                        } catch (\Exception $e) {
                                                            // Log but don't fail validation for other file system errors
                                                        }
                                                    }
                                                } catch (\Exception $e) {
                                                    // Log but don't fail validation for unexpected errors
                                                }
                                            };
                                        },
                                    ])
                                    ->columnSpanFull(),
                                Select::make('available_cities')
                                    ->label('Available Cities')
                                    ->options(fn() => City::query()
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray())
                                    ->multiple()
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->helperText('Select cities where this ride type will be available. Only these cities can be configured in the City Pricing tab.')
                                    ->live(),
                                \Filament\Forms\Components\Toggle::make('status')
                                    ->label('Active')
                                    ->default(true),
                            ])
                            ->columns(1),

                        Tab::make('City Pricing')
                            ->schema([
                                Section::make('City-Specific Pricing')

                                    ->description('Set different pricing for specific cities. This allows you to customize rates based on local market conditions.')
                                    ->schema([
                                        Repeater::make('city_pricing')
                                            ->label(fn() => new HtmlString(
                                                view('filament.forms.components.pricing-label-with-tooltip', ['label' => 'City Pricing Configuration'])->render()
                                            ))
                                            ->schema([
                                                Select::make('city_id')
                                                    ->label('Select City')
                                                    ->options(function ($get, $state) {

                                                        $availableCities = $get('../../available_cities') ?? [];
                                                        if (empty($availableCities) || !is_array($availableCities)) {
                                                            return [];
                                                        }

                                                        $allPricing = $get('../../city_pricing') ?? [];

                                                        $currentCityId = $state ? intval($state) : null;

                                                        $excludedCityIds = [];
                                                        foreach ($allPricing as $index => $pricing) {
                                                            if (!isset($pricing['city_id']) || $pricing['city_id'] === null || $pricing['city_id'] === '') {
                                                                continue;
                                                            }

                                                            $cityId = intval($pricing['city_id']);

                                                            if ($currentCityId !== null && $cityId === $currentCityId) {
                                                                continue;
                                                            }

                                                            $excludedCityIds[] = $cityId;
                                                        }

                                                        $availableCityIds = array_map('intval', $availableCities);
                                                        $filteredCityIds = array_diff($availableCityIds, $excludedCityIds);

                                                        if (empty($filteredCityIds)) {
                                                            return [];
                                                        }

                                                        return City::whereIn('id', $filteredCityIds)
                                                            ->orderBy('name')
                                                            ->pluck('name', 'id')
                                                            ->toArray();
                                                    })
                                                    ->searchable()
                                                    ->preload()
                                                    ->live()
                                                    ->rules([
                                                        function ($get) {
                                                            return function (string $attribute, $value, \Closure $fail) use ($get) {
                                                                if ($value === null || $value === '' || $value === 0) {
                                                                    return;
                                                                }

                                                                $value = intval($value);

                                                                // Skip if value is 0 (invalid city ID)
                                                                if ($value === 0) {
                                                                    return;
                                                                }


                                                                $allPricing = $get('../../city_pricing') ?? [];


                                                                $currentIndex = null;
                                                                if (preg_match('/city_pricing\.(\d+)\.city_id/', $attribute, $matches)) {
                                                                    $currentIndex = intval($matches[1]);
                                                                }

                                                                if ($currentIndex === null && preg_match('/\.(\d+)\.city_id/', $attribute, $matches)) {
                                                                    $currentIndex = intval($matches[1]);
                                                                }

                                                                $foundCurrentItem = false;
                                                                if ($currentIndex === null) {
                                                                    $occurrences = 0;
                                                                    foreach ($allPricing as $pricing) {
                                                                        if (isset($pricing['city_id']) && intval($pricing['city_id']) === $value) {
                                                                            $occurrences++;
                                                                        }
                                                                    }
                                                                    if ($occurrences <= 1) {
                                                                        return;
                                                                    }
                                                                }

                                                                $duplicateCount = 0;
                                                                foreach ($allPricing as $index => $pricing) {
                                                                    if ($currentIndex !== null && $index === $currentIndex) {
                                                                        continue;
                                                                    }

                                                                    if (!isset($pricing['city_id']) || $pricing['city_id'] === null || $pricing['city_id'] === '' || $pricing['city_id'] === 0) {
                                                                        continue;
                                                                    }

                                                                    $existingCityId = intval($pricing['city_id']);

                                                                    // Skip invalid city IDs
                                                                    if ($existingCityId === 0) {
                                                                        continue;
                                                                    }

                                                                    // Check if this city matches the value being validated
                                                                    if ($existingCityId === $value) {
                                                                        // Found a duplicate in another item
                                                                        $duplicateCount++;
                                                                    }
                                                                }

                                                                // If duplicate found in other items, show error
                                                                if ($duplicateCount > 0) {
                                                                    $cityName = City::find($value)?->name;
                                                                    $fail('You have already added pricing for ' . ($cityName ?? 'this city') . '.');
                                                                    return;
                                                                }
                                                            };
                                                        },
                                                    ])
                                                    ->afterStateHydrated(function ($state, $set) {
                                                        if ($state === null || $state === '') {
                                                            $set('city_id', null);
                                                            $set('city_name', null);
                                                            return;
                                                        }

                                                        $state = intval($state);
                                                        $set('city_id', $state);
                                                        $cityName = City::find($state)?->name;
                                                        $set('city_name', $cityName);
                                                    })
                                                    ->afterStateUpdated(function ($state, $set) {
                                                        if ($state === null || $state === '') {
                                                            $set('city_name', null);
                                                            return;
                                                        }

                                                        $state = intval($state);
                                                        $set('city_id', $state);
                                                        $cityName = City::find($state)?->name;
                                                        $set('city_name', $cityName);
                                                    })
                                                    ->default(null)
                                                    ->dehydrated(true),

                                                TextInput::make('city_name')
                                                    ->label('City Name')
                                                    ->disabled()
                                                    ->dehydrated(false),

                                                Toggle::make('is_active')
                                                    ->label('Enable Custom Pricing')
                                                    ->default(true)
                                                    ->live(),

                                                Grid::make(2)
                                                    ->schema([
                                                        TextInput::make('base_distance')
                                                            ->label('Base Distance (km)')
                                                            ->numeric()
                                                            ->minValue(0)
                                                            ->step(0.01)
                                                            ->hintIcon('heroicon-o-information-circle', tooltip: 'The initial distance (in kilometers) included in the base price. Charges apply for distances beyond this.')
                                                            ->disabled(fn($get) => !$get('is_active')),

                                                        TextInput::make('base_price')
                                                            ->label('Base Price')
                                                            ->numeric()
                                                            ->minValue(0)
                                                            ->step(0.01)
                                                            ->prefix('₹')
                                                            ->hintIcon('heroicon-o-information-circle', tooltip: 'The fixed starting price charged for every ride, regardless of distance or time.')
                                                            ->disabled(fn($get) => !$get('is_active')),

                                                        TextInput::make('price_per_km')
                                                            ->label('Price per KM')
                                                            ->numeric()
                                                            ->minValue(0)
                                                            ->step(0.01)
                                                            ->prefix('₹')
                                                            ->hintIcon('heroicon-o-information-circle', tooltip: 'The amount charged per kilometer traveled beyond the base distance.')
                                                            ->disabled(fn($get) => !$get('is_active')),

                                                        TextInput::make('price_per_minute')
                                                            ->label('Price per Minute')
                                                            ->numeric()
                                                            ->minValue(0)
                                                            ->step(0.01)
                                                            ->prefix('₹')
                                                            ->hintIcon('heroicon-o-information-circle', tooltip: 'The amount charged per minute of travel time during the ride.')
                                                            ->disabled(fn($get) => !$get('is_active')),

                                                        TextInput::make('minimum_fare')
                                                            ->label('Minimum Fare')
                                                            ->numeric()
                                                            ->minValue(0)
                                                            ->step(0.01)
                                                            ->prefix('₹')
                                                            ->hintIcon('heroicon-o-information-circle', tooltip: 'The minimum amount that will be charged for a ride, even if the calculated fare is lower.')
                                                            ->disabled(fn($get) => !$get('is_active')),


                                                        TextInput::make('waiting_charge_per_minute')
                                                            ->label('Waiting Charge per Minute')
                                                            ->numeric()
                                                            ->minValue(0)
                                                            ->step(0.01)
                                                            ->prefix('₹')
                                                            ->hintIcon('heroicon-o-information-circle', tooltip: 'The amount charged per minute when the driver is waiting for the passenger after the free waiting time has elapsed.')
                                                            ->disabled(fn($get) => !$get('is_active')),

                                                        TextInput::make('waiting_time_limit')
                                                            ->label('Free Waiting Time (minutes)')
                                                            ->numeric()
                                                            ->minValue(0)
                                                            ->step(1)
                                                            ->hintIcon('heroicon-o-information-circle', tooltip: 'The number of minutes of free waiting time before waiting charges begin to apply.')
                                                            ->disabled(fn($get) => !$get('is_active')),

                                                        TextInput::make('commission_rate')
                                                            ->label('Commission Rate (%)')
                                                            ->numeric()
                                                            ->minValue(0)
                                                            ->maxValue(100)
                                                            ->step(0.01)
                                                            ->suffix('%')
                                                            ->hintIcon('heroicon-o-information-circle', tooltip: 'The percentage of the total fare that the platform will receive as commission from each ride.')
                                                            ->disabled(fn($get) => !$get('is_active')),
                                                    ])
                                                    ->disabled(fn($get) => !$get('is_active')),
                                            ])
                                            ->addActionLabel('Add City')
                                            ->defaultItems(0)
                                            ->collapsible()
                                            ->itemLabel(fn(array $state): ?string => $state['city_name'] ?? 'New City Pricing')
                                            ->columnSpanFull(),
                                    ])
                                    ->collapsible()
                                    ->collapsed(false),
                            ])
                            ->icon('heroicon-o-building-office-2'),

                        Tab::make('Default Pricing')
                            ->schema([
                                Section::make('Default Pricing Configuration')
                                    ->description('Set the default pricing that will be used for all cities that don\'t have specific pricing configured above.')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextInput::make('base_distance')
                                                    ->label('Base Distance (km)')
                                                    ->numeric()
                                                    ->required()
                                                    ->minValue(0)
                                                    ->step(0.01)
                                                    ->hintIcon('heroicon-o-information-circle', tooltip: 'The initial distance (in kilometers) included in the base price. Charges apply for distances beyond this.'),

                                                TextInput::make('base_price')
                                                    ->label('Base Price')
                                                    ->numeric()
                                                    ->required()
                                                    ->minValue(0)
                                                    ->step(0.01)
                                                    ->prefix('₹')
                                                    ->hintIcon('heroicon-o-information-circle', tooltip: 'The fixed starting price charged for every ride, regardless of distance or time.'),

                                                TextInput::make('price_per_km')
                                                    ->label('Price per KM')
                                                    ->numeric()
                                                    ->required()
                                                    ->minValue(0)
                                                    ->step(0.01)
                                                    ->prefix('₹')
                                                    ->hintIcon('heroicon-o-information-circle', tooltip: 'The amount charged per kilometer traveled beyond the base distance.'),

                                                TextInput::make('price_per_minute')
                                                    ->label('Price per Minute')
                                                    ->numeric()
                                                    ->required()
                                                    ->minValue(0)
                                                    ->step(0.01)
                                                    ->prefix('₹')
                                                    ->hintIcon('heroicon-o-information-circle', tooltip: 'The amount charged per minute of travel time during the ride.'),

                                                TextInput::make('minimum_fare')
                                                    ->label('Minimum Fare')
                                                    ->numeric()
                                                    ->required()
                                                    ->minValue(0)
                                                    ->step(0.01)
                                                    ->prefix('₹')
                                                    ->hintIcon('heroicon-o-information-circle', tooltip: 'The minimum amount that will be charged for a ride, even if the calculated fare is lower.'),


                                                TextInput::make('waiting_charge_per_minute')
                                                    ->label('Waiting Charge per Minute')
                                                    ->numeric()
                                                    ->required()
                                                    ->minValue(0)
                                                    ->step(0.01)
                                                    ->prefix('₹')
                                                    ->hintIcon('heroicon-o-information-circle', tooltip: 'The amount charged per minute when the driver is waiting for the passenger after the free waiting time has elapsed.'),

                                                TextInput::make('waiting_time_limit')
                                                    ->label('Free Waiting Time (minutes)')
                                                    ->numeric()
                                                    ->required()
                                                    ->minValue(0)
                                                    ->step(1)
                                                    ->hintIcon('heroicon-o-information-circle', tooltip: 'The number of minutes of free waiting time before waiting charges begin to apply.'),

                                                TextInput::make('commission_rate')
                                                    ->label('Commission Rate (%)')
                                                    ->numeric()
                                                    ->required()
                                                    ->minValue(0)
                                                    ->maxValue(100)
                                                    ->step(0.01)
                                                    ->suffix('%')
                                                    ->hintIcon('heroicon-o-information-circle', tooltip: 'The percentage of the total fare that the platform will receive as commission from each ride.')
                                                    ->columnSpan(2),
                                            ]),
                                    ])
                                    ->collapsible()
                                    ->collapsed(false),
                            ])
                            ->icon('heroicon-o-cog-6-tooth'),
                    ])
                    ->columnSpanFull(),
            ]);
    }


    protected function handleRecordCreation(array $data): Model
    {
        // Extract non-fillable fields that need special handling
        $availableCities = $data['available_cities'] ?? null;
        $cityPricing = $data['city_pricing'] ?? null;

        // Remove non-fillable fields from data before mass assignment
        unset($data['available_cities'], $data['city_pricing']);

        // Create the record with only fillable fields
        $rideType = parent::handleRecordCreation($data);

        // Restore the non-fillable fields for saveCityData
        $data['available_cities'] = $availableCities;
        $data['city_pricing'] = $cityPricing;

        $this->saveCityData($rideType, $data);

        return $rideType;
    }

    protected function saveCityData(Model $rideType, array $data): void
    {
        try {
            DB::beginTransaction();

            $rideTypeId = $rideType->id;

            $availableCityIds = isset($data['available_cities']) && is_array($data['available_cities'])
                ? array_values(array_map('intval', $data['available_cities']))
                : [];

            if (!empty($availableCityIds)) {

                DB::table('available_ride_cities')
                    ->where('ride_type_id', $rideTypeId)
                    ->delete();

                $insertData = [];
                foreach ($availableCityIds as $cityId) {
                    $insertData[] = [
                        'ride_type_id' => $rideTypeId,
                        'city_id' => $cityId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if (!empty($insertData)) {
                    DB::table('available_ride_cities')->insert($insertData);
                }
            }

            $defaultBaseDistance = $rideType->base_distance;
            $defaultBasePrice = $rideType->base_price;
            $defaultPricePerKm = $rideType->price_per_km;
            $defaultPricePerMinute = $rideType->price_per_minute;
            $defaultMinimumFare = $rideType->minimum_fare;
            $defaultWaitingChargePerMinute = $rideType->waiting_charge_per_minute;
            $defaultWaitingTimeLimit = $rideType->waiting_time_limit;
            $defaultCommissionRate = $rideType->commission_rate;

            $citiesWithCustomPricing = [];
            if (!empty($data['city_pricing'])) {
                foreach ($data['city_pricing'] as $pricing) {
                    if (!isset($pricing['city_id']) || $pricing['city_id'] === null || $pricing['city_id'] === '') {
                        continue;
                    }

                    $cityId = intval($pricing['city_id']);

                    if (!in_array($cityId, $availableCityIds, true)) {
                        continue;
                    }

                    $citiesWithCustomPricing[] = $cityId;

                    $isActive = (bool) ($pricing['is_active'] ?? true);

                    DB::table('city_ride_types')->insert([
                        'ride_type_id' => $rideTypeId,
                        'city_id' => $cityId,
                        'status' => $isActive,
                        'base_distance' => !empty($pricing['base_distance']) ? $pricing['base_distance'] : $defaultBaseDistance,
                        'base_price' => !empty($pricing['base_price']) ? $pricing['base_price'] : $defaultBasePrice,
                        'price_per_km' => !empty($pricing['price_per_km']) ? $pricing['price_per_km'] : $defaultPricePerKm,
                        'price_per_minute' => !empty($pricing['price_per_minute']) ? $pricing['price_per_minute'] : $defaultPricePerMinute,
                        'minimum_fare' => !empty($pricing['minimum_fare']) ? $pricing['minimum_fare'] : $defaultMinimumFare,
                        'waiting_charge_per_minute' => !empty($pricing['waiting_charge_per_minute']) ? $pricing['waiting_charge_per_minute'] : $defaultWaitingChargePerMinute,
                        'waiting_time_limit' => !empty($pricing['waiting_time_limit']) ? $pricing['waiting_time_limit'] : $defaultWaitingTimeLimit,
                        'commission_rate' => !empty($pricing['commission_rate']) ? $pricing['commission_rate'] : $defaultCommissionRate,
                        'cancellation_charge' => $rideType->cancellation_charge ?? 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if (!empty($availableCityIds) && empty($citiesWithCustomPricing)) {

                DB::table('city_ride_types')->insert([
                    'ride_type_id' => $rideTypeId,
                    'city_id' => null,
                    'status' => true,
                    'base_distance' => $defaultBaseDistance,
                    'base_price' => $defaultBasePrice,
                    'price_per_km' => $defaultPricePerKm,
                    'price_per_minute' => $defaultPricePerMinute,
                    'minimum_fare' => $defaultMinimumFare,
                    'waiting_charge_per_minute' => $defaultWaitingChargePerMinute,
                    'waiting_time_limit' => $defaultWaitingTimeLimit,
                    'commission_rate' => $defaultCommissionRate,
                    'cancellation_charge' => $rideType->cancellation_charge ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
