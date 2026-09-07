<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use App\Filament\Resources\RideTypeResource\Pages;
use App\Models\RideType;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Grid;
use Filament\Notifications\Notification;

class RideTypeResource extends BaseResource
{
    protected static ?string $model = RideType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|\UnitEnum|null $navigationGroup = 'Ride Configuration';

    protected static ?int $navigationSort = 3;



    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Tabs::make('Ride Type Management')
                    ->tabs([
                        Tab::make('Basic Information')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('code')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true),
                                Forms\Components\Textarea::make('description')
                                    ->maxLength(65535)
                                    ->columnSpanFull(),
                                Forms\Components\FileUpload::make('icon')
                                    ->image()
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                    ->rules(['mimes:jpg,jpeg,png,webp'])
                                    ->directory('ride-types')
                                    ->maxSize(1024)
                                    ->columnSpanFull(),
                                Grid::make(3)
                                    ->schema([
                                        Forms\Components\TextInput::make('capacity')
                                            ->required()
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(20)
                                            ->default(4),
                                        Forms\Components\TextInput::make('order')
                                            ->required()
                                            ->numeric()
                                            ->minValue(0)
                                            ->default(0),
                                        Forms\Components\Toggle::make('status')
                                            ->label('Active')
                                            ->default(true)
                                            ->required(),
                                    ]),
                            ]),

                        Tab::make('Default Pricing')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('base_distance')
                                            ->label('Base Distance (km)')
                                            ->default(3.00)
                                            ->numeric()
                                            ->required()
                                            ->minValue(0)
                                            ->step(0.01),
                                        Forms\Components\TextInput::make('base_price')
                                            ->label('Base Price')
                                            ->default(50.00)
                                            ->numeric()
                                            ->required()
                                            ->minValue(0)
                                            ->step(0.01)
                                            ->prefix('₹'),
                                        Forms\Components\TextInput::make('price_per_km')
                                            ->label('Price per KM')
                                            ->default(12.00)
                                            ->numeric()
                                            ->required()
                                            ->minValue(0)
                                            ->step(0.01)
                                            ->prefix('₹'),
                                        Forms\Components\TextInput::make('price_per_minute')
                                            ->label('Price per Minute')
                                            ->default(2.00)
                                            ->numeric()
                                            ->required()
                                            ->minValue(0)
                                            ->step(0.01)
                                            ->prefix('₹'),
                                        Forms\Components\TextInput::make('minimum_fare')
                                            ->label('Minimum Fare')
                                            ->default(50.00)
                                            ->numeric()
                                            ->required()
                                            ->minValue(0)
                                            ->step(0.01)
                                            ->prefix('₹'),
                                    ]),
                            ]),

                        Tab::make('Waiting Charges')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('waiting_charge_per_minute')
                                            ->label('Waiting Charge per Minute')
                                            ->default(2.00)
                                            ->numeric()
                                            ->required()
                                            ->minValue(0)
                                            ->step(0.01)
                                            ->prefix('₹'),
                                        Forms\Components\TextInput::make('waiting_time_limit')
                                            ->label('Free Waiting Time (minutes)')
                                            ->default(3)
                                            ->numeric()
                                            ->required()
                                            ->minValue(0)
                                            ->step(1),
                                        Forms\Components\TextInput::make('commission_rate')
                                            ->label('Commission Rate (%)')
                                            ->default(20.00)
                                            ->numeric()
                                            ->required()
                                            ->minValue(0)
                                            ->maxValue(100)
                                            ->step(0.01)
                                            ->suffix('%'),
                                    ]),
                            ]),

                        Tab::make('Requirements')
                            ->schema([
                                Forms\Components\Repeater::make('driver_requirements')
                                    ->schema([
                                        Forms\Components\TextInput::make('requirement')
                                            ->required()
                                            ->maxLength(255),
                                    ])
                                    ->columnSpanFull()
                                    ->defaultItems(0)
                                    ->reorderable(false)
                                    ->addActionLabel('Add Driver Requirement'),

                                Forms\Components\Repeater::make('vehicle_requirements')
                                    ->schema([
                                        Forms\Components\TextInput::make('requirement')
                                            ->required()
                                            ->maxLength(255),
                                    ])
                                    ->columnSpanFull()
                                    ->defaultItems(0)
                                    ->reorderable(false)
                                    ->addActionLabel('Add Vehicle Requirement'),
                            ]),
                    ])
                    ->columnSpan('full'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('icon')
                    ->square(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('status')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('base_price')
                    ->money('INR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_per_km')
                    ->money('INR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('commission_rate')
                    ->numeric(2)
                    ->suffix('%')
                    ->sortable(),





                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalHeading('Delete Ride Type')
                    ->modalDescription('Are you sure you want to delete this ride type? This action cannot be undone.')
                    ->modalSubmitActionLabel('Yes, delete it')
                    ->requiresConfirmation()
                    // Custom notification is sent below.
                    ->successNotification(null)
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
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalHeading('Delete Selected Ride Types')
                        ->modalDescription('Are you sure you want to delete the selected ride types? This action cannot be undone.')
                        ->modalSubmitActionLabel('Yes, delete them')
                        ->requiresConfirmation()
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
                                ->body(count($records) . ' ride type(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('order');
    }

    public static function getRelations(): array
    {
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRideTypes::route('/'),
            'create' => Pages\CreateRideType::route('/create'),
            'edit' => Pages\EditRideType::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code', 'description'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {

        return "{$record->name} ({$record->code})";
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {

        $details = [];

        if ($record->description) {
            $details['Description'] = Str::limit($record->description, 60);
        }

        $details['Status'] = $record->status ? 'Active' : 'Inactive';
        $details['Base Price'] = '₹' . number_format($record->base_price ?? 0, 2);
        $details['Price per KM'] = '₹' . number_format($record->price_per_km ?? 0, 2);

        return $details;
    }
}
