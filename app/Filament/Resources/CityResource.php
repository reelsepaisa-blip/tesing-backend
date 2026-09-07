<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TimePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use App\Filament\Resources\CityResource\Pages\ListCities;
use App\Filament\Resources\CityResource\Pages\CreateCity;
use App\Filament\Resources\CityResource\Pages\EditCity;
use App\Filament\Resources\CityResource\Pages;
use App\Models\City;
use App\Forms\Components\CityAutocomplete;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CityResource extends BaseResource
{
    protected static ?string $model = City::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|\UnitEnum|null $navigationGroup = 'Location Management';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('City Management')
                    ->tabs([
                        Tab::make('Basic Information')
                            ->schema([
                                CityAutocomplete::make('name')
                                    ->label('City Name')
                                    ->placeholder('Start typing city name...')
                                    ->stateField('state')
                                    ->countryField('country')
                                    ->latitudeField('latitude')
                                    ->longitudeField('longitude')
                                    ->required()
                                    ->maxLength(255)
                                    ->rules([
                                        function () {
                                            return function (string $attribute, $value, Closure $fail) {
                                                $state = request()->input('state');
                                                $country = request()->input('country');

                                                if ($state && $country) {
                                                    $exists = City::where('name', $value)
                                                        ->where('state', $state)
                                                        ->where('country', $country)
                                                        ->exists();

                                                    if ($exists) {
                                                        $fail("The city '{$value}, {$state}, {$country}' already exists in the system.");
                                                    }
                                                }
                                            };
                                        },
                                    ]),
                                TextInput::make('state')
                                    ->label('State/Province')
                                    ->placeholder('State will be auto-filled')
                                    ->maxLength(255),
                                TextInput::make('country')
                                    ->label('Country')
                                    ->placeholder('Country will be auto-filled')
                                    ->default('India')
                                    ->required()
                                    ->maxLength(255),
                                Grid::make(2)
                                    ->schema([
                                        TextInput::make('latitude')
                                            ->label('Latitude')
                                            ->placeholder('Will be auto-filled')
                                            ->required()
                                            ->numeric()
                                            ->minValue(-90)
                                            ->maxValue(90)
                                            ->step(0.000001),
                                        TextInput::make('longitude')
                                            ->label('Longitude')
                                            ->placeholder('Will be auto-filled')
                                            ->required()
                                            ->numeric()
                                            ->minValue(-180)
                                            ->maxValue(180)
                                            ->step(0.000001),
                                    ]),
                                Toggle::make('status')
                                    ->label('Active')
                                    ->default(true)
                                    ->required(),
                            ]),

                        Tab::make('Service Hours')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        TimePicker::make('service_start_time')
                                            ->default('06:00:00')
                                            ->required(),
                                        TimePicker::make('service_end_time')
                                            ->default('23:00:00')
                                            ->required(),
                                    ]),
                            ]),

                        Tab::make('Night Charges')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        TextInput::make('night_charge_multiplier')
                                            ->label('Night Charge Multiplier')
                                            ->default(1.50)
                                            ->numeric()
                                            ->required()
                                            ->minValue(1)
                                            ->maxValue(5)
                                            ->step(0.01)
                                            ->suffix('x'),
                                        TimePicker::make('night_start_time')
                                            ->default('22:00:00')
                                            ->required(),
                                        TimePicker::make('night_end_time')
                                            ->default('06:00:00')
                                            ->required(),
                                    ]),
                            ]),
                    ])
                    ->columnSpan('full'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('state')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('status')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('zones_count')
                    ->counts('zones')
                    ->label('Zones'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->actions([
                EditAction::make()
                    ->visible(fn($record) => !(auth()->id() === 2 && $record->id === 1)),
                DeleteAction::make()
                    ->modalHeading('Delete City')
                    ->modalDescription('Are you sure you would like to do this?')
                    ->modalSubmitActionLabel('Yes, delete it')
                    ->requiresConfirmation()
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
                            ->body('The city has been deleted.')
                            ->success()
                            ->send();
                    }),
                Action::make('zones')
                    ->label('Manage Zones')
                    ->icon('heroicon-o-map')
                    ->url(fn(City $record) => route('filament.admin.resources.zones.index', ['city_id' => $record->id])),
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
                                ->body(count($records) . ' city(ies) have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCities::route('/'),
            'create' => CreateCity::route('/create'),
            'edit' => EditCity::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'state', 'country'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {

        $location = trim("{$record->name}, {$record->state}, {$record->country}", ', ');
        return $location;
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {

        $details = [];

        if ($record->state) {
            $details['State'] = $record->state;
        }

        if ($record->country) {
            $details['Country'] = $record->country;
        }

        $details['Status'] = $record->status ? 'Active' : 'Inactive';

        if ($record->currency) {
            $details['Currency'] = $record->currency;
        }

        if ($record->timezone) {
            $details['Timezone'] = $record->timezone;
        }

        return $details;
    }
}