<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\DriverIncentiveResource\Pages\ListDriverIncentives;
use App\Filament\Resources\DriverIncentiveResource\Pages\CreateDriverIncentive;
use App\Filament\Resources\DriverIncentiveResource\Pages\ViewDriverIncentive;
use App\Filament\Resources\DriverIncentiveResource\Pages\EditDriverIncentive;
use App\Filament\Resources\DriverIncentiveResource\Pages;
use App\Models\DriverIncentive;
use App\Models\User;
use App\Models\City;
use App\Models\RideType;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\KeyValue;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;

class DriverIncentiveResource extends Resource
{
    protected static ?string $model = DriverIncentive::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'Driver Incentives';

    protected static ?string $modelLabel = 'Driver Incentive';

    protected static ?string $pluralModelLabel = 'Driver Incentives';

    protected static string | \UnitEnum | null $navigationGroup = 'Driver Management';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Basic Information')
                    ->schema([
                        Select::make('driver_id')
                            ->label('Driver')
                            ->options(function () {

                                $driverOptions = User::query()
                                    ->where('role_id', 2) // Filter for drivers only
                                    ->where('is_register', 1) // Filter for registered drivers only
                                    ->selectRaw("id, COALESCE(NULLIF(TRIM(name), ''), CONCAT('User #', id)) as label")
                                    ->orderBy('label')
                                    ->pluck('label', 'id')
                                    ->toArray();

                                return ['all' => 'All Drivers'] + $driverOptions;
                            })
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->dehydrateStateUsing(fn($state) => $state === 'all' ? null : $state)
                            ->afterStateHydrated(function (Select $component, $state) {
                                if ($state === null) {
                                    $component->state('all');
                                }
                            })
                            ->helperText('Choose "All Drivers" to apply globally'),

                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->validationMessages([
                                'required' => 'Title is required',
                                'max' => 'Title cannot exceed 255 characters',
                            ]),

                        Textarea::make('description')
                            ->required()
                            ->rows(3)
                            ->maxLength(1000)
                            ->validationMessages([
                                'required' => 'Description is required',
                                'max' => 'Description cannot exceed 1000 characters',
                            ]),

                        Select::make('type')
                            ->options([
                                'ride_count' => 'Ride Count',
                                'streak' => 'Streak',
                                'time_based' => 'Time Based',
                                'earnings' => 'Earnings',
                                'custom' => 'Custom',
                            ])
                            ->required()
                            ->reactive()
                            ->live()
                            ->afterStateUpdated(function (Set $set, $state) {
                                // Clear all criteria fields when type changes
                                $set('criteria_target', null);
                                $set('criteria_consecutive_days', null);
                                $set('criteria_hours', null);
                                $set('criteria_period', null);
                                $set('criteria_target_amount', null);
                                $set('criteria', []);
                            })
                            ->validationMessages([
                                'required' => 'Incentive type is required',
                            ]),

                        TextInput::make('reward_amount')
                            ->label('Reward Amount')
                            ->numeric()
                            ->prefix('₹')
                            ->minValue(0)
                            ->required()
                            ->validationMessages([
                                'required' => 'Reward amount is required',
                                'numeric' => 'Reward amount must be a number',
                                'min' => 'Reward amount cannot be negative',
                            ]),
                    ])
                    ->columns(2),

                Section::make('Timing')
                    ->schema([
                        DateTimePicker::make('start_time')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y H:i')
                            ->validationMessages([
                                'required' => 'Start time is required',
                            ]),

                        DateTimePicker::make('end_time')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y H:i')
                            ->after('start_time')
                            ->validationMessages([
                                'required' => 'End time is required',
                                'after' => 'End time must be after start time',
                            ]),

                        Select::make('status')
                            ->options([
                                'upcoming' => 'Upcoming',
                                'live' => 'Live',
                                'completed' => 'Completed',
                                'expired' => 'Expired',
                                'cancelled' => 'Cancelled',
                            ])
                            ->required()
                            ->validationMessages([
                                'required' => 'Status is required',
                            ]),
                    ])
                    ->columns(3),

                Section::make('Criteria & Configuration')
                    ->schema([
                        // Criteria fields - displayed based on type selection
                        Section::make('Incentive Criteria')
                            ->description('The criteria key is predefined based on the incentive type. You only need to enter the value.')
                            ->visible(fn(Get $get) => !empty($get('type')))
                            ->schema([
                                // Ride Count Criteria
                                TextInput::make('criteria_target')
                                    ->label('Target Rides (Key: target)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->integer()
                                    ->required(fn(Get $get) => $get('type') === 'ride_count')
                                    ->visible(fn(Get $get) => $get('type') === 'ride_count')
                                    ->reactive()
                                    ->helperText('The key "target" is already set. Enter the number of rides required (minimum 1).')
                                    ->validationMessages([
                                        'required' => 'Target rides is required for ride count incentive',
                                        'numeric' => 'Target rides must be a number',
                                        'min' => 'Target rides must be at least 1',
                                        'integer' => 'Target rides must be a whole number',
                                    ]),

                                // Streak Criteria
                                TextInput::make('criteria_consecutive_days')
                                    ->label('Consecutive Days (Key: consecutive_days)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->integer()
                                    ->required(fn(Get $get) => $get('type') === 'streak')
                                    ->visible(fn(Get $get) => $get('type') === 'streak')
                                    ->reactive()
                                    ->helperText('The key "consecutive_days" is already set. Enter the number of consecutive days required (minimum 1).')
                                    ->validationMessages([
                                        'required' => 'Consecutive days is required for streak incentive',
                                        'numeric' => 'Consecutive days must be a number',
                                        'min' => 'Consecutive days must be at least 1',
                                        'integer' => 'Consecutive days must be a whole number',
                                    ]),

                                // Time Based Criteria
                                TextInput::make('criteria_hours')
                                    ->label('Target Hours (Key: hours)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->required(fn(Get $get) => $get('type') === 'time_based')
                                    ->visible(fn(Get $get) => $get('type') === 'time_based')
                                    ->reactive()
                                    ->helperText('The key "hours" is already set. Enter the number of hours to work (minimum 1).')
                                    ->validationMessages([
                                        'required' => 'Target hours is required for time-based incentive',
                                        'numeric' => 'Target hours must be a number',
                                        'min' => 'Target hours must be at least 1',
                                    ]),

                                Select::make('criteria_period')
                                    ->label('Period (Key: period)')
                                    ->options([
                                        'daily' => 'Daily',
                                        'weekly' => 'Weekly',
                                        'monthly' => 'Monthly',
                                    ])
                                    ->required(fn(Get $get) => $get('type') === 'time_based')
                                    ->visible(fn(Get $get) => $get('type') === 'time_based')
                                    ->reactive()
                                    ->helperText('The key "period" is already set. Select the time period for this incentive.')
                                    ->validationMessages([
                                        'required' => 'Period is required for time-based incentive',
                                    ]),

                                // Earnings Criteria
                                TextInput::make('criteria_target_amount')
                                    ->label('Target Earnings (Key: target_amount)')
                                    ->numeric()
                                    ->prefix('₹')
                                    ->minValue(1)
                                    ->required(fn(Get $get) => $get('type') === 'earnings')
                                    ->visible(fn(Get $get) => $get('type') === 'earnings')
                                    ->reactive()
                                    ->helperText('The key "target_amount" is already set. Enter the target earnings in rupees (minimum ₹1).')
                                    ->validationMessages([
                                        'required' => 'Target earnings is required for earnings incentive',
                                        'numeric' => 'Target earnings must be a number',
                                        'min' => 'Target earnings must be at least ₹1',
                                    ]),

                                // Custom Criteria - show key-value for custom type
                                KeyValue::make('criteria')
                                    ->label('Custom Criteria')
                                    ->keyLabel('Key')
                                    ->valueLabel('Value')
                                    ->addActionLabel('Add Criteria')
                                    ->visible(fn(Get $get) => $get('type') === 'custom')
                                    ->required(fn(Get $get) => $get('type') === 'custom')
                                    ->reactive()
                                    ->helperText('Define custom criteria for this incentive (e.g., target: 12, type: rides)'),
                            ])
                            ->columns(2)
                            ->visible(fn(Get $get) => !empty($get('type'))),

                        Repeater::make('milestones')
                            ->label('Milestones')
                            ->schema([
                                TextInput::make('target')
                                    ->label('Target Count')
                                    ->numeric()
                                    ->minValue(1)
                                    ->required()
                                    ->helperText('Target number to achieve'),
                                TextInput::make('reward')
                                    ->label('Reward Amount')
                                    ->numeric()
                                    ->prefix('₹')
                                    ->minValue(0)
                                    ->required()
                                    ->helperText('Reward for achieving this milestone'),
                                Textarea::make('description')
                                    ->label('Description')
                                    ->required()
                                    ->rows(2)
                                    ->maxLength(500)
                                    ->helperText('Description of this milestone'),
                            ])
                            ->columns(3)
                            ->addActionLabel('Add Milestone')
                            ->collapsible()
                            ->helperText('Optional: Define milestone rewards. Leave empty if not using milestones.')
                            ->visible(fn(Get $get) => in_array($get('type'), ['ride_count', 'earnings', 'time_based'])),

                        Select::make('zones')
                            ->label('Applicable Zones')
                            ->multiple()
                            ->options(function () {
                                return City::all()
                                    ->mapWithKeys(function ($city) {
                                        return [$city->id => $city->name ?? "City #{$city->id}"];
                                    });
                            })
                            ->searchable()
                            ->helperText('Leave empty to apply to all zones'),

                        Select::make('ride_types')
                            ->label('Applicable Ride Types')
                            ->multiple()
                            ->options(function () {
                                return RideType::all()
                                    ->mapWithKeys(function ($rideType) {
                                        return [$rideType->id => $rideType->name ?? "Ride Type #{$rideType->id}"];
                                    });
                            })
                            ->searchable()
                            ->helperText('Leave empty to apply to all ride types'),

                        Repeater::make('time_slots')
                            ->label('Time Slots')
                            ->schema([
                                TimePicker::make('start')
                                    ->label('Start Time')
                                    ->required()
                                    ->seconds(false)
                                    ->helperText('Select the start time for this time slot'),
                                TimePicker::make('end')
                                    ->label('End Time')
                                    ->required()
                                    ->seconds(false)
                                    ->helperText('Select the end time for this time slot'),
                            ])
                            ->columns(2)
                            ->addActionLabel('Add Time Slot')
                            ->collapsible()
                            ->helperText('Optional: Leave empty to apply to all times')
                            ->visible(fn(Get $get) => in_array($get('type'), ['time_based', 'ride_count'])),
                    ])
                    ->visible(fn(Get $get) => !empty($get('type'))),

                Section::make('Settings')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),

                        // KeyValue::make('meta_data')
                        //     ->label('Additional Data')
                        //     ->keyLabel('Key')
                        //     ->valueLabel('Value')
                        //     ->addActionLabel('Add Meta Data')
                        //     ->helperText('Additional configuration data'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('driver.name')
                    ->label('Driver')
                    ->hidden(),

                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                TextColumn::make('type')->badge()
                    ->colors([
                        'primary' => 'ride_count',
                        'success' => 'streak',
                        'warning' => 'time_based',
                        'danger' => 'earnings',
                        'secondary' => 'custom',
                    ]),

                TextColumn::make('reward_amount')
                    ->label('Reward')
                    ->money('INR')
                    ->sortable(),

                TextColumn::make('status')->badge()
                    ->colors([
                        'warning' => 'upcoming',
                        'success' => 'live',
                        'primary' => 'completed',
                        'danger' => 'expired',
                        'secondary' => 'cancelled',
                    ]),

                TextColumn::make('start_time')
                    ->label('Start Time')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('end_time')
                    ->label('End Time')
                    ->dateTime()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Active'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'upcoming' => 'Upcoming',
                        'live' => 'Live',
                        'completed' => 'Completed',
                        'expired' => 'Expired',
                        'cancelled' => 'Cancelled',
                    ]),

                SelectFilter::make('type')
                    ->options([
                        'ride_count' => 'Ride Count',
                        'streak' => 'Streak',
                        'time_based' => 'Time Based',
                        'earnings' => 'Earnings',
                        'custom' => 'Custom',
                    ]),

                TernaryFilter::make('is_active')
                    ->label('Active'),

                SelectFilter::make('driver_id')
                    ->label('Driver')
                    ->relationship('driver', 'name', function ($query) {
                        return $query->where('role_id', 2) // Filter for drivers only
                            ->where('is_register', 1); // Filter for registered drivers only
                    })
                    ->searchable()
                    ->preload()
                    ->indicator('Driver')
                    ->placeholder('All Drivers')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown Driver'),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        // Block deletion for restricted users (ID 2)
                        $userId = auth()->id();
                        if ($userId === 2) {
                            Notification::make()
                                ->title('Access Restricted')
                                ->body('In demo mode you are not deleting data...')
                                ->danger()
                                ->persistent()
                                ->send();
                            return false;
                        }
                        
                        // Proceed with normal deletion
                        $record->delete();
                        
                        Notification::make()
                            ->title('Deleted')
                            ->body('The driver incentive has been deleted.')
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
                                    ->persistent()
                                    ->send();
                                return;
                            }
                            
                            // Default bulk delete behavior
                            foreach ($records as $record) {
                                $record->delete();
                            }
                            
                            Notification::make()
                                ->title('Deleted')
                                ->body(count($records) . ' driver incentive(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
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
            'index' => ListDriverIncentives::route('/'),
            'create' => CreateDriverIncentive::route('/create'),
            'view' => ViewDriverIncentive::route('/{record}'),
            'edit' => EditDriverIncentive::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['driver']);
    }
}
