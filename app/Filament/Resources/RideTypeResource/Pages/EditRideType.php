<?php

namespace App\Filament\Resources\RideTypeResource\Pages;

use App\Filament\Resources\RideTypeResource;
use App\Models\City;
use App\Models\RideType;
use Filament\Actions;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\HtmlString;

class EditRideType extends EditRecord
{

    protected static string $resource = RideTypeResource::class;

    protected array $originalCityIds = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
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
                        ->body('The ride type has been deleted.')
                        ->success()
                        ->send();

                    return redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->originalCityIds = DB::table('city_ride_types')
            ->where('ride_type_id', $this->record->id)
            ->whereNotNull('city_id')
            ->pluck('city_id')
            ->map(fn($id) => intval($id))
            ->toArray();
        $this->loadCityPricingData();
    }

    protected function loadCityPricingData(): void
    {
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {

        $availableCities = $this->record->availableCities()->get();
        $data['available_cities'] = $availableCities->pluck('id')->toArray();

        $existingCityPricing = $this->record->cities()->get();


        $this->originalCityIds = DB::table('city_ride_types')
            ->where('ride_type_id', $this->record->id)
            ->whereNotNull('city_id')
            ->pluck('city_id')
            ->map(fn($id) => intval($id))
            ->toArray();

        $cityPricingData = [];
        $addedCityIds = []; // Track city_ids to prevent duplicates

        foreach ($existingCityPricing as $cityPricing) {
            $cityId = intval($cityPricing->id);

            if (in_array($cityId, $addedCityIds, true)) {
                continue;
            }

            $pivot = $cityPricing->pivot;

            $hasCustomPricing =
                !(bool) $pivot->status ||
                $pivot->base_distance != $this->record->base_distance ||
                $pivot->base_price != $this->record->base_price ||
                $pivot->price_per_km != $this->record->price_per_km ||
                $pivot->price_per_minute != $this->record->price_per_minute ||
                $pivot->minimum_fare != $this->record->minimum_fare ||
                $pivot->waiting_charge_per_minute != $this->record->waiting_charge_per_minute ||
                $pivot->waiting_time_limit != $this->record->waiting_time_limit ||
                $pivot->commission_rate != $this->record->commission_rate;

            if (!$hasCustomPricing) {
                continue;
            }

            $cityPricingData[] = [
                'city_id' => $cityId,
                'city_name' => $cityPricing->name,
                'is_active' => (bool) $pivot->status,
                'base_distance' => $pivot->base_distance,
                'base_price' => $pivot->base_price,
                'price_per_km' => $pivot->price_per_km,
                'price_per_minute' => $pivot->price_per_minute,
                'minimum_fare' => $pivot->minimum_fare,
                'waiting_charge_per_minute' => $pivot->waiting_charge_per_minute,
                'waiting_time_limit' => $pivot->waiting_time_limit,
                'commission_rate' => $pivot->commission_rate,
            ];

            $addedCityIds[] = $cityId;
        }

        $uniqueCityPricing = [];
        $seenCityIds = [];
        foreach ($cityPricingData as $pricing) {
            $cityId = intval($pricing['city_id'] ?? 0);
            if ($cityId > 0 && !in_array($cityId, $seenCityIds, true)) {
                $uniqueCityPricing[] = $pricing;
                $seenCityIds[] = $cityId;
            }
        }

        $data['city_pricing'] = $uniqueCityPricing;

        return $data;
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
                                Grid::make(2)
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
                                                            ->where('id', '!=', $this->record->id)
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
                                    ->searchable()
                                    ->preload()
                                    ->helperText('Select cities where this ride type will be available. Only these cities can be configured in the City Pricing tab.')
                                    ->dehydrated(true)
                                    ->required(),
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
                                                view('filament.forms.components.pricing-label-with-tooltip', ['label' => 'City Pricing'])->render()
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
                                                        foreach ($allPricing as $uuid => $pricing) {
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
                                                                if ($value === null || $value === '') {
                                                                    return;
                                                                }

                                                                $value = intval($value);
                                                                $rideTypeId = $this->record->id;

                                                                $allPricing = $get('../../city_pricing') ?? [];

                                                                $currentUuid = null;
                                                                if (preg_match('/city_pricing\.([a-f0-9\-]+)\.city_id/', $attribute, $matches)) {
                                                                    $currentUuid = $matches[1];
                                                                }

                                                                $duplicateFound = false;
                                                                foreach ($allPricing as $uuid => $pricing) {

                                                                    if ($currentUuid !== null && $uuid === $currentUuid) {
                                                                        continue;
                                                                    }

                                                                    if (!isset($pricing['city_id']) || $pricing['city_id'] === null || $pricing['city_id'] === '') {
                                                                        continue;
                                                                    }

                                                                    $existingCityId = intval($pricing['city_id']);
                                                                    if ($existingCityId === $value) {
                                                                        $duplicateFound = true;
                                                                        break;
                                                                    }
                                                                }

                                                                if ($duplicateFound) {
                                                                    $cityName = City::find($value)?->name;
                                                                    $fail(' ' . ($cityName ?? 'this city') . ' in another entry. Please remove the duplicate.');
                                                                    return;
                                                                }

                                                                if (!is_array($this->originalCityIds) || empty($this->originalCityIds)) {
                                                                    $this->originalCityIds = DB::table('city_ride_types')
                                                                        ->where('ride_type_id', $rideTypeId)
                                                                        ->whereNotNull('city_id')
                                                                        ->pluck('city_id')
                                                                        ->map(fn($id) => intval($id))
                                                                        ->toArray();
                                                                }

                                                                $existsInDb = DB::table('city_ride_types')
                                                                    ->where('city_id', $value)
                                                                    ->where('ride_type_id', $rideTypeId)
                                                                    ->whereNotNull('city_id')
                                                                    ->exists();




                                                                if ($existsInDb && !in_array($value, $this->originalCityIds, true)) {
                                                                    $cityName = City::find($value)?->name;
                                                                    $fail('Pricing for ' . ($cityName ?? 'this city') . ' already exists in the database for this ride type.');
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

    protected function getCityPricingSchema(): array
    {
        $schema = [];

        if (!$this->cities) {
            return $schema;
        }

        foreach ($this->cities as $city) {
            $schema[] = Section::make($city->name)
                ->schema([
                    Toggle::make("city_{$city->id}.status")
                        ->label('Active')
                        ->default(false)
                        ->live(),

                    Grid::make(2)
                        ->schema([
                            TextInput::make("city_{$city->id}.base_distance")
                                ->label('Base Distance (km)')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->step(0.01)
                                ->disabled(fn($get) => !$get("city_{$city->id}.status")),

                            TextInput::make("city_{$city->id}.base_price")
                                ->label('Base Price')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->step(0.01)
                                ->prefix('₹')
                                ->disabled(fn($get) => !$get("city_{$city->id}.status")),

                            TextInput::make("city_{$city->id}.price_per_km")
                                ->label('Price per KM')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->step(0.01)
                                ->prefix('₹')
                                ->disabled(fn($get) => !$get("city_{$city->id}.status")),

                            TextInput::make("city_{$city->id}.price_per_minute")
                                ->label('Price per Minute')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->step(0.01)
                                ->prefix('₹')
                                ->disabled(fn($get) => !$get("city_{$city->id}.status")),

                            TextInput::make("city_{$city->id}.minimum_fare")
                                ->label('Minimum Fare')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->step(0.01)
                                ->prefix('₹')
                                ->disabled(fn($get) => !$get("city_{$city->id}.status")),


                            TextInput::make("city_{$city->id}.waiting_charge_per_minute")
                                ->label('Waiting Charge per Minute')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->step(0.01)
                                ->prefix('₹')
                                ->disabled(fn($get) => !$get("city_{$city->id}.status")),

                            TextInput::make("city_{$city->id}.waiting_time_limit")
                                ->label('Free Waiting Time (minutes)')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->step(1)
                                ->disabled(fn($get) => !$get("city_{$city->id}.status")),

                            TextInput::make("city_{$city->id}.commission_rate")
                                ->label('Commission Rate (%)')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->maxValue(100)
                                ->step(0.01)
                                ->suffix('%')
                                ->disabled(fn($get) => !$get("city_{$city->id}.status")),
                        ]),
                ])
                ->collapsed();
        }

        return $schema;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Save city pricing data first (needs the full data array)
        $this->saveCityPricingData($data);

        // Remove non-fillable fields before returning data for model update
        unset($data['available_cities'], $data['city_pricing']);

        return $data;
    }

    protected function afterSave(): void
    {

        $data = $this->form->getState();
        $availableCityIds = isset($data['available_cities']) && is_array($data['available_cities'])
            ? array_values(array_map('intval', $data['available_cities']))
            : [];

        $rideTypeId = $this->record->id;

        DB::table('available_ride_cities')
            ->where('ride_type_id', $rideTypeId)
            ->delete();

        if (!empty($availableCityIds)) {
            $insertData = [];
            foreach ($availableCityIds as $cityId) {
                $insertData[] = [
                    'ride_type_id' => $rideTypeId,
                    'city_id' => $cityId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('available_ride_cities')->insert($insertData);
        }
    }

    protected function saveCityPricingData(array $data): void
    {
        try {
            DB::beginTransaction();

            $availableCityIds = isset($data['available_cities']) && is_array($data['available_cities'])
                ? array_values(array_map('intval', $data['available_cities']))
                : [];

            $defaultBaseDistance = $data['base_distance'] ?? $this->record->base_distance;
            $defaultBasePrice = $data['base_price'] ?? $this->record->base_price;
            $defaultPricePerKm = $data['price_per_km'] ?? $this->record->price_per_km;
            $defaultPricePerMinute = $data['price_per_minute'] ?? $this->record->price_per_minute;
            $defaultMinimumFare = $data['minimum_fare'] ?? $this->record->minimum_fare;
            $defaultWaitingChargePerMinute = $data['waiting_charge_per_minute'] ?? $this->record->waiting_charge_per_minute;
            $defaultWaitingTimeLimit = $data['waiting_time_limit'] ?? $this->record->waiting_time_limit;
            $defaultCommissionRate = $data['commission_rate'] ?? $this->record->commission_rate;

            $rideTypeId = $this->record->id;

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

                    DB::table('city_ride_types')->updateOrInsert(
                        [
                            'ride_type_id' => $rideTypeId,
                            'city_id' => $cityId,
                        ],
                        [
                            'status' => $isActive,
                            'base_distance' => !empty($pricing['base_distance']) ? $pricing['base_distance'] : $defaultBaseDistance,
                            'base_price' => !empty($pricing['base_price']) ? $pricing['base_price'] : $defaultBasePrice,
                            'price_per_km' => !empty($pricing['price_per_km']) ? $pricing['price_per_km'] : $defaultPricePerKm,
                            'price_per_minute' => !empty($pricing['price_per_minute']) ? $pricing['price_per_minute'] : $defaultPricePerMinute,
                            'minimum_fare' => !empty($pricing['minimum_fare']) ? $pricing['minimum_fare'] : $defaultMinimumFare,
                            'waiting_charge_per_minute' => !empty($pricing['waiting_charge_per_minute']) ? $pricing['waiting_charge_per_minute'] : $defaultWaitingChargePerMinute,
                            'waiting_time_limit' => !empty($pricing['waiting_time_limit']) ? $pricing['waiting_time_limit'] : $defaultWaitingTimeLimit,
                            'commission_rate' => !empty($pricing['commission_rate']) ? $pricing['commission_rate'] : $defaultCommissionRate,
                            'cancellation_charge' => $this->record->cancellation_charge ?? 0,
                            'updated_at' => now(),
                            'created_at' => DB::raw('COALESCE(created_at, NOW())'),
                        ]
                    );
                }
            }

            if (!empty($availableCityIds) && empty($citiesWithCustomPricing)) {

                DB::table('city_ride_types')->updateOrInsert(
                    [
                        'ride_type_id' => $rideTypeId,
                        'city_id' => null,
                    ],
                    [
                        'status' => true,
                        'base_distance' => $defaultBaseDistance,
                        'base_price' => $defaultBasePrice,
                        'price_per_km' => $defaultPricePerKm,
                        'price_per_minute' => $defaultPricePerMinute,
                        'minimum_fare' => $defaultMinimumFare,
                        'waiting_charge_per_minute' => $defaultWaitingChargePerMinute,
                        'waiting_time_limit' => $defaultWaitingTimeLimit,
                        'commission_rate' => $defaultCommissionRate,
                        'cancellation_charge' => $this->record->cancellation_charge ?? 0,
                        'updated_at' => now(),
                        'created_at' => DB::raw('COALESCE(created_at, NOW())'),
                    ]
                );
            } else {

                DB::table('city_ride_types')
                    ->where('ride_type_id', $rideTypeId)
                    ->whereNull('city_id')
                    ->delete();
            }

            DB::table('city_ride_types')
                ->where('ride_type_id', $rideTypeId)
                ->whereNotNull('city_id')
                ->whereNotIn('city_id', $citiesWithCustomPricing)
                ->delete();

            DB::commit();

            Notification::make()
                ->title('City pricing updated successfully')
                ->success()
                ->send();
        } catch (\Exception $e) {
            DB::rollBack();

            Notification::make()
                ->title('Error updating city pricing')
                ->danger()
                ->body($e->getMessage())
                ->send();
        }
    }
}
