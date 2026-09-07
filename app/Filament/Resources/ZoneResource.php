<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ZoneResource\Pages;
use App\Forms\Components\GoogleMapsDraw;
use App\Models\Zone;
use App\Models\ZoneSurgeSlot;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Objects\Polygon;
use MatanYadaev\EloquentSpatial\Objects\LineString;

use Illuminate\Support\Str;
use App\Models\City;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Actions as SchemaActions;

class ZoneResource extends BaseResource
{
    protected static ?string $model = Zone::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static string|\UnitEnum|null $navigationGroup = 'Location Management';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Group::make()
                    ->schema([
                        Section::make('Zone Information')
                            ->schema([
                                Forms\Components\Select::make('city_id')
                                    ->relationship('city', 'name')
                                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->reactive()
                                    ->afterStateHydrated(function ($state, $component) {
                                        try {
                                            if (!$state) {
                                                return;
                                            }
                                            $city = City::find($state);
                                            if ($city) {
                                                $livewire = $component->getLivewire();
                                                $currentZoneId = null;
                                                $currentZoneData = null;

                                                if (method_exists($livewire, 'getRecord') && $livewire->getRecord()) {

                                                    $currentZone = $livewire->getRecord();
                                                    $currentZoneId = $currentZone->id;
                                                    $currentCoordinates = static::extractCoordinatesFromPolygon($currentZone->boundaries);

                                                    if (!empty($currentCoordinates)) {
                                                        $currentZoneData = [
                                                            'id' => $currentZone->id,
                                                            'name' => $currentZone->name,
                                                            'coordinates' => $currentCoordinates,
                                                        ];
                                                    }
                                                }

                                                $existingZones = Zone::where('city_id', $state)
                                                    ->whereNotNull('boundaries')
                                                    ->when($currentZoneId, fn($query) => $query->where('id', '!=', $currentZoneId))
                                                    ->get();

                                                $zonesData = $existingZones
                                                    ->map(function (Zone $zone) {
                                                        $coordinates = static::extractCoordinatesFromPolygon($zone->boundaries);
                                                        if (empty($coordinates)) {
                                                            return null;
                                                        }

                                                        return [
                                                            'id' => $zone->id,
                                                            'name' => $zone->name,
                                                            'coordinates' => $coordinates,
                                                        ];
                                                    })
                                                    ->filter()
                                                    ->values()
                                                    ->all();

                                                $livewire->dispatch(
                                                    'zone-city-changed',
                                                    lat: (float) $city->latitude,
                                                    lng: (float) $city->longitude,
                                                    zones: $zonesData,
                                                    currentZone: $currentZoneData
                                                );
                                            }
                                        } catch (\Throwable $e) {
                                        }
                                    })
                                    ->afterStateUpdated(function ($state, $old, $set, $get, $component) {
                                        try {
                                            if (!$state) {
                                                return;
                                            }
                                            $city = City::find($state);
                                            if ($city) {
                                                $livewire = $component->getLivewire();
                                                $currentZoneId = null;
                                                $currentZoneData = null;

                                                if (method_exists($livewire, 'getRecord') && $livewire->getRecord()) {

                                                    $currentZone = $livewire->getRecord();
                                                    $currentZoneId = $currentZone->id;
                                                    $currentCoordinates = static::extractCoordinatesFromPolygon($currentZone->boundaries);

                                                    if (!empty($currentCoordinates)) {
                                                        $currentZoneData = [
                                                            'id' => $currentZone->id,
                                                            'name' => $currentZone->name,
                                                            'coordinates' => $currentCoordinates,
                                                        ];
                                                    }
                                                }

                                                $existingZones = Zone::where('city_id', $state)
                                                    ->whereNotNull('boundaries')
                                                    ->when($currentZoneId, fn($query) => $query->where('id', '!=', $currentZoneId))
                                                    ->get();

                                                $zonesData = $existingZones
                                                    ->map(function (Zone $zone) {
                                                        $coordinates = static::extractCoordinatesFromPolygon($zone->boundaries);
                                                        if (empty($coordinates)) {
                                                            return null;
                                                        }

                                                        return [
                                                            'id' => $zone->id,
                                                            'name' => $zone->name,
                                                            'coordinates' => $coordinates,
                                                        ];
                                                    })
                                                    ->filter()
                                                    ->values()
                                                    ->all();

                                                $livewire->dispatch(
                                                    'zone-city-changed',
                                                    lat: (float) $city->latitude,
                                                    lng: (float) $city->longitude,
                                                    zones: $zonesData,
                                                    currentZone: $currentZoneData
                                                );
                                            }
                                        } catch (\Throwable $e) {
                                        }
                                    }),
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\Textarea::make('description')
                                    ->maxLength(65535)
                                    ->columnSpanFull(),
                                Forms\Components\Toggle::make('status')
                                    ->label('Active')
                                    ->default(true)
                                    ->required()
                                    ->rules([
                                        function ($livewire) {
                                            return function (string $attribute, $value, \Closure $fail) use ($livewire) {
                                                if ($value) { // If trying to activate zone
                                                    $cityId = null;

                                                    if (method_exists($livewire, 'getRecord') && $livewire->getRecord()) {
                                                        $cityId = $livewire->getRecord()->city_id;
                                                    } else {

                                                        $cityId = request()->input('city_id');
                                                    }

                                                    if ($cityId) {
                                                        $city = \App\Models\City::find($cityId);
                                                        if ($city && !$city->status) {
                                                            $fail('City is inactive. Cannot activate zone in an inactive city.');
                                                        }
                                                    }
                                                }
                                            };
                                        },
                                    ]),
                            ]),
                    ])
                    ->columnSpan(['lg' => 1]),

                Group::make()
                    ->schema([
                        Section::make('Zone Boundaries')
                            ->schema([

                                GoogleMapsDraw::make('google_maps_draw')
                                    ->columnSpanFull()
                                    ->dehydrateStateUsing(function ($state) {

                                        return null;
                                    })
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\Hidden::make('boundaries')
                                    ->live(onBlur: true)
                                    ->default(function ($record, $get, $livewire) {


                                        if (method_exists($livewire, 'getRecord') && $livewire->getRecord()) {
                                            try {

                                                $formData = $livewire->form->getRawState();
                                                if (isset($formData['boundaries']) && !empty($formData['boundaries'])) {
                                                    $boundaries = $formData['boundaries'];

                                                    if (is_string($boundaries) && $boundaries !== '' && $boundaries !== '[]') {
                                                        return $boundaries;
                                                    }

                                                    if (is_array($boundaries) && count($boundaries) >= 3) {
                                                        $jsonData = json_encode($boundaries);
                                                        return $jsonData;
                                                    }
                                                }
                                            } catch (\Throwable $e) {
                                            }
                                        }

                                        if ($record && $record->boundaries) {
                                            try {
                                                $polygon = $record->boundaries;
                                                $coordinates = [];

                                                if ($polygon instanceof \MatanYadaev\EloquentSpatial\Objects\Polygon) {
                                                    $geometries = $polygon->getGeometries();
                                                    if (!empty($geometries)) {
                                                        $linestring = $geometries[0];
                                                        $points = $linestring->getGeometries();

                                                        foreach ($points as $point) {
                                                            $coordinates[] = [
                                                                'lat' => $point->latitude,
                                                                'lng' => $point->longitude
                                                            ];
                                                        }

                                                        if (
                                                            count($coordinates) > 1 &&
                                                            $coordinates[0]['lat'] == $coordinates[count($coordinates) - 1]['lat'] &&
                                                            $coordinates[0]['lng'] == $coordinates[count($coordinates) - 1]['lng']
                                                        ) {
                                                            array_pop($coordinates);
                                                        }

                                                        $jsonData = json_encode($coordinates);
                                                        return $jsonData;
                                                    }
                                                }
                                            } catch (\Throwable $e) {
                                            }
                                        }


                                        return '';
                                    })
                                    ->afterStateHydrated(function ($state, $component, $record) {


                                        if ($record && $record->boundaries) {

                                            $isValidState = false;
                                            if (!empty($state) && is_string($state) && $state !== '[]' && $state !== 'null') {
                                                try {
                                                    $parsed = json_decode($state, true);
                                                    if (is_array($parsed) && count($parsed) >= 3) {
                                                        $isValidState = true;
                                                    }
                                                } catch (\Throwable $e) {
                                                }
                                            }

                                            if (!$isValidState) {
                                                try {
                                                    $coordinates = static::extractCoordinatesFromPolygon($record->boundaries);
                                                    if (!empty($coordinates) && count($coordinates) >= 3) {
                                                        $jsonData = json_encode($coordinates);
                                                        $component->state($jsonData);
                                                    }
                                                } catch (\Throwable $e) {
                                                    // Error handling
                                                }
                                            } else {
                                            }
                                        }
                                    })
                                    ->dehydrateStateUsing(function ($state, $record) {


                                        $boundariesData = $state;


                                        if (empty($boundariesData) || $boundariesData === null || $boundariesData === '' || $boundariesData === '[]') {

                                            $boundariesData = request()->input('boundaries');

                                            if (empty($boundariesData) || $boundariesData === '[]') {
                                                $boundariesData = request()->input('map_boundaries');
                                            }

                                            if (empty($boundariesData) || $boundariesData === '[]') {
                                                $boundariesData = request()->input('data.boundaries');
                                            }
                                            if (empty($boundariesData) || $boundariesData === '[]') {
                                                $boundariesData = request()->input('data[boundaries]');
                                            }

                                            if (empty($boundariesData) || $boundariesData === '[]') {
                                                $updates = request()->input('updates', []);
                                                foreach ($updates as $update) {
                                                    if (
                                                        isset($update['payload']['name']) &&
                                                        str_contains($update['payload']['name'], 'boundaries')
                                                    ) {
                                                        $boundariesData = $update['payload']['value'] ?? null;
                                                        if (!empty($boundariesData) && $boundariesData !== '[]') {

                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                        }

                                        if ($boundariesData && $boundariesData !== '[]' && $boundariesData !== 'null') {

                                            if (is_array($boundariesData)) {
                                                $points = $boundariesData;
                                            } else {

                                                $points = json_decode($boundariesData, true);
                                            }

                                            if ($points && is_array($points) && count($points) >= 3) {
                                                $coordinates = array_map(function ($point) {
                                                    return new Point($point['lat'], $point['lng']);
                                                }, $points);

                                                $coordinates[] = $coordinates[0];

                                                $polygon = new Polygon([new LineString($coordinates)]);


                                                return $polygon;
                                            }
                                        }

                                        if ($record && $record->boundaries) {

                                            return $record->boundaries;
                                        }


                                        return null;
                                    }),
                            ]),
                    ])
                    ->columnSpan(['lg' => 2]),

                Section::make('Surge Slots')
                    ->description('Set recurring weekly surge pricing. These slots repeat automatically every week.')
                    ->icon('heroicon-o-clock')
                    ->schema([
                        SchemaActions::make([])->alignEnd(),

                        static::makeDaySection(0, 'Sunday'),

                        static::makeDaySection(1, 'Monday'),

                        static::makeDaySection(2, 'Tuesday'),

                        static::makeDaySection(3, 'Wednesday'),

                        static::makeDaySection(4, 'Thursday'),

                        static::makeDaySection(5, 'Friday'),

                        static::makeDaySection(6, 'Saturday'),
                    ])
                    ->columnSpanFull(),

                Section::make('Date-Specific Surge (Events/Holidays)')
                    ->description('Set surge pricing for specific dates. Use this for holidays, events, or special occasions.')
                    ->icon('heroicon-o-calendar')
                    ->collapsed()
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('surge_multiplier')
                                    ->label('Surge Multiplier')
                                    ->default(1.00)
                                    ->numeric()
                                    ->required()
                                    ->minValue(1)
                                    ->maxValue(5)
                                    ->step(0.01)
                                    ->suffix('x')
                                    ->rules([
                                        'nullable',
                                        'numeric',
                                        'min:1',
                                        'max:5',
                                        'regex:/^\d+(\.\d{1,2})?$/',
                                    ])
                                    ->validationMessages([
                                        'min' => 'The surge multiplier must be at least 1.00x.',
                                        'max' => 'The surge multiplier cannot exceed 5.00x.',
                                        'regex' => 'The surge multiplier must have at most 2 decimal places.',
                                    ]),
                                Forms\Components\DateTimePicker::make('surge_start_time')
                                    ->label('Surge Start Time')
                                    ->displayFormat('M d, Y H:i')
                                    ->live()
                                    ->rules([
                                        function (Get $get) {
                                            return function (string $attribute, $value, \Closure $fail) use ($get) {
                                                $endTime = $get('surge_end_time');
                                                $multiplier = $get('surge_multiplier');

                                                if ($value || $endTime || ($multiplier && $multiplier != 1.00)) {
                                                    if (!$value) {
                                                        $fail('Start time is required when setting date-specific surge.');
                                                    } elseif (!$endTime) {
                                                        $fail('End time is required when setting date-specific surge.');
                                                    } elseif (!$multiplier || $multiplier == 1.00) {
                                                        $fail('Surge multiplier must be greater than 1.00 for date-specific surge.');
                                                    }
                                                }

                                                if ($value && $endTime && $value >= $endTime) {
                                                    $fail('Start time must be before end time.');
                                                }
                                            };
                                        },
                                    ]),
                                Forms\Components\DateTimePicker::make('surge_end_time')
                                    ->label('Surge End Time')
                                    ->displayFormat('M d, Y H:i')
                                    ->live()
                                    ->rules([
                                        function (Get $get) {
                                            return function (string $attribute, $value, \Closure $fail) use ($get) {
                                                $startTime = $get('surge_start_time');
                                                $multiplier = $get('surge_multiplier');

                                                if ($value || $startTime || ($multiplier && $multiplier != 1.00)) {
                                                    if (!$value) {
                                                        $fail('End time is required when setting date-specific surge.');
                                                    } elseif (!$startTime) {
                                                        $fail('Start time is required when setting date-specific surge.');
                                                    } elseif (!$multiplier || $multiplier == 1.00) {
                                                        $fail('Surge multiplier must be greater than 1.00 for date-specific surge.');
                                                    }
                                                }

                                                if ($value && $startTime && $value <= $startTime) {
                                                    $fail('End time must be after start time.');
                                                }
                                            };
                                        },
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('city.name')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\IconColumn::make('status')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_surge')
                    ->label('Current Surge')
                    ->getStateUsing(function (Zone $record): string {
                        return number_format($record->getCurrentMultiplier(), 2) . 'x';
                    })
                    ->badge()
                    ->color(fn(Zone $record): string => $record->getCurrentMultiplier() > 1 ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('surge_slots_count')
                    ->label('Weekly Slots')
                    ->counts('surgeSlots')
                    ->sortable(),
                Tables\Columns\TextColumn::make('drivers_count')
                    ->counts('drivers')
                    ->label('Active Drivers'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('city')
                    ->relationship('city', 'name')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                    ->default(function () {

                        return request()->integer('city_id') ?: null;
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->actions([
                EditAction::make()
                    ->visible(fn($record) => !(auth()->id() === 2 && $record->id === 1)),
                Action::make('view_map')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn(Zone $record) => route('filament.admin.resources.zones.map', $record))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->modalHeading('Delete Selected Zones')
                        ->modalDescription('Are you sure you want to delete the selected zones? This action cannot be undone.')
                        ->modalSubmitActionLabel('Yes, delete them')
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
                                ->body(count($records) . ' zone(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListZones::route('/'),
            'create' => Pages\CreateZone::route('/create'),
            'edit' => Pages\EditZone::route('/{record}/edit'),
            'map' => Pages\MapZone::route('/{record}/map'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'description'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['city']);
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {

        $cityName = $record->city ? $record->city->name : 'N/A';
        return "{$record->name} ({$cityName})";
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {

        $details = [];

        if ($record->city) {
            $details['City'] = $record->city->name;
        }

        if ($record->description) {
            $details['Description'] = Str::limit($record->description, 60);
        }

        $details['Status'] = $record->status ? 'Active' : 'Inactive';

        if ($record->surge_multiplier) {
            $details['Surge'] = $record->surge_multiplier . 'x';
        }

        return $details;
    }

    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        parent::applyGlobalSearchAttributeConstraints($query, $search);

        foreach (explode(' ', $search) as $searchWord) {
            $query->orWhere(function (Builder $query) use ($searchWord) {

                $query->whereHas('city', function (Builder $query) use ($searchWord) {
                    $query->where('name', 'like', "%{$searchWord}%");
                });
            });
        }
    }


    protected static function extractCoordinatesFromPolygon(?Polygon $polygon): array
    {
        if (!$polygon instanceof Polygon) {
            return [];
        }

        $geometries = $polygon->getGeometries();
        if (empty($geometries) || !$geometries[0] instanceof LineString) {
            return [];
        }

        $linestring = $geometries[0];
        $points = $linestring->getGeometries();
        $coordinates = [];

        foreach ($points as $point) {
            $coordinates[] = [
                'lat' => $point->latitude,
                'lng' => $point->longitude,
            ];
        }

        if (
            count($coordinates) > 1 &&
            $coordinates[0]['lat'] === $coordinates[count($coordinates) - 1]['lat'] &&
            $coordinates[0]['lng'] === $coordinates[count($coordinates) - 1]['lng']
        ) {
            array_pop($coordinates);
        }

        return $coordinates;
    }


    protected static function makeDaySection(int $dayOfWeek, string $dayName): Section
    {
        $fieldName = 'surge_slots_day_' . $dayOfWeek;

        return Section::make($dayName)
            ->description(
                fn($record) => $record
                ? static::getDaySlotCount($record->id, $dayOfWeek) . ' slot(s) configured'
                : 'No slots configured'
            )
            ->collapsed()
            ->collapsible()
            ->extraAttributes(['class' => 'day-section'])
            ->schema([
                Forms\Components\Repeater::make($fieldName)
                    ->label('')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                Forms\Components\TimePicker::make('start_time')
                                    ->label('Start Time')
                                    ->required()
                                    ->seconds(false)
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set) {

                                        $set('end_time', null);
                                    })
                                    ->rules([
                                        'required',
                                        'date_format:H:i',
                                    ]),
                                Forms\Components\TimePicker::make('end_time')
                                    ->label('End Time')
                                    ->required()
                                    ->seconds(false)
                                    ->minDate(fn(Get $get) => $get('start_time'))
                                    ->after('start_time')
                                    ->rules([
                                        'required',
                                        'date_format:H:i',
                                        function (Get $get) use ($dayOfWeek, $fieldName) {
                                            return function (string $attribute, $value, \Closure $fail) use ($get, $dayOfWeek, $fieldName) {
                                                $startTime = $get('start_time');
                                                if (!$startTime || !$value) {
                                                    return;
                                                }

                                                if ($value <= $startTime) {
                                                    $fail('End time must be after start time.');
                                                    return;
                                                }

                                                $allSlots = $get("../../{$fieldName}") ?? [];
                                                $currentIndex = null;

                                                foreach ($allSlots as $index => $slot) {
                                                    if (
                                                        isset($slot['start_time']) && $slot['start_time'] === $startTime &&
                                                        isset($slot['end_time']) && $slot['end_time'] === $value
                                                    ) {
                                                        $currentIndex = $index;
                                                        break;
                                                    }
                                                }

                                                foreach ($allSlots as $index => $slot) {

                                                    if ($index === $currentIndex) {
                                                        continue;
                                                    }

                                                    if (!isset($slot['start_time']) || !isset($slot['end_time'])) {
                                                        continue;
                                                    }

                                                    $existingStart = $slot['start_time'];
                                                    $existingEnd = $slot['end_time'];


                                                    if ($startTime < $existingEnd && $value > $existingStart) {
                                                        $fail("This time slot overlaps with another slot ({$existingStart} - {$existingEnd}).");
                                                        return;
                                                    }
                                                }
                                            };
                                        },
                                    ]),
                                Forms\Components\TextInput::make('surge_multiplier')
                                    ->label('Multiplier')
                                    ->numeric()
                                    ->required()
                                    ->default(1.50)
                                    ->minValue(1.00)
                                    ->maxValue(5)
                                    ->step(0.01)
                                    ->suffix('x')
                                    ->rules([
                                        'required',
                                        'numeric',
                                        'min:1',
                                        'max:5',
                                        'regex:/^\d+(\.\d{1,2})?$/',
                                    ])
                                    ->validationMessages([
                                        'min' => 'The multiplier must be at least 1.00x.',
                                        'max' => 'The multiplier cannot exceed 5.00x.',
                                        'regex' => 'The multiplier must have at most 2 decimal places.',
                                    ]),
                                Forms\Components\Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true)
                                    ->inline(false),
                            ]),
                    ])
                    ->defaultItems(0)
                    ->addActionLabel('+ Add Time Slot')
                    ->reorderable(false)
                    ->itemLabel(
                        fn(array $state): ?string =>
                        isset($state['start_time']) && isset($state['end_time'])
                        ? Carbon::parse($state['start_time'])->format('g:i A') .
                        ' - ' . Carbon::parse($state['end_time'])->format('g:i A') .
                        (isset($state['surge_multiplier']) ? ' (' . $state['surge_multiplier'] . 'x)' : '')
                        : null
                    )
                    ->afterStateHydrated(function ($component, $state, $record) use ($dayOfWeek) {
                        if ($record) {
                            $slots = ZoneSurgeSlot::where('zone_id', $record->id)
                                ->where('day_of_week', $dayOfWeek)
                                ->orderBy('start_time')
                                ->get()
                                ->map(fn($slot) => [
                                    'id' => $slot->id,
                                    'start_time' => Carbon::parse($slot->start_time)->format('H:i'),
                                    'end_time' => Carbon::parse($slot->end_time)->format('H:i'),
                                    'surge_multiplier' => (float) $slot->surge_multiplier,
                                    'is_active' => (bool) $slot->is_active,
                                ])
                                ->toArray();
                            $component->state($slots);
                        }
                    }),
            ]);
    }


    protected static function getDaySlotCount(?int $zoneId, int $dayOfWeek): int
    {
        if (!$zoneId)
            return 0;

        return ZoneSurgeSlot::where('zone_id', $zoneId)
            ->where('day_of_week', $dayOfWeek)
            ->count();
    }
}
