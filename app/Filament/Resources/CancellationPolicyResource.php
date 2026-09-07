<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\CancellationPolicyResource\Pages\ListCancellationPolicies;
use App\Filament\Resources\CancellationPolicyResource\Pages\CreateCancellationPolicy;
use App\Filament\Resources\CancellationPolicyResource\Pages\EditCancellationPolicy;
use App\Filament\Resources\CancellationPolicyResource\Pages;
use App\Models\CancellationPolicy;
use App\Models\City;
use App\Models\RideType;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CancellationPolicyResource extends Resource
{
    protected static ?string $model = CancellationPolicy::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-x-circle';
    protected static string | \UnitEnum | null $navigationGroup = 'Ride Configuration';
    protected static ?string $navigationLabel = 'Cancellation Policies';
    protected static ?string $modelLabel = 'Cancellation Policy';
    protected static ?string $pluralModelLabel = 'Cancellation Policies';
    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Policy Information')
                    ->description('Configure cancellation policies for ride bookings. City is optional (leave empty to apply to all cities). Ride Type is required.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Policy Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., Standard Cancellation Policy'),

                        Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->placeholder('Description of this cancellation policy'),

                        Select::make('city_id')
                            ->label('City')
                            ->relationship('city', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Select a city for city-specific policy. Leave empty to apply to all cities.'),

                        Select::make('ride_type_id')
                            ->label('Ride Type')
                            ->relationship('rideType', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Select a ride type for this policy. This field is mandatory.'),

                        Toggle::make('allow_customer_cancellation')
                            ->label('Allow Customer Cancellation')
                            ->default(true)
                            ->hidden()
                            ->helperText('Enable or disable customer cancellation feature'),
                    ]),

                Section::make('Cancellation Window')
                    ->schema([
                        TextInput::make('free_cancellation_window')
                            ->label('Free Cancellation Window (seconds)')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default(60)
                            ->helperText('Time window for free cancellation after booking'),
                    ]),

                Section::make('Cancellation Fees')
                    ->schema([
                        TextInput::make('cancellation_fee')
                            ->label('Fixed Cancellation Fee (₹)')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->step(0.01)
                            ->helperText('Fixed amount charged for cancellation'),

                        TextInput::make('cancellation_fee_percentage')
                            ->label('Cancellation Fee Percentage (%)')
                            ->numeric()
                            ->hidden()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0)
                            ->step(0.01)
                            ->suffix('%')
                            ->helperText('Percentage of trip amount charged for cancellation'),

                        // Forms\Components\Toggle::make('driver_gets_share')
                        //     ->label('Driver Gets Share of Cancellation Fee')
                        //     ->default(false)
                        //     ->
                        //     ->helperText('Whether driver receives a portion of cancellation fee'),

                        // Forms\Components\TextInput::make('driver_share_percentage')
                        //     ->label('Driver Share Percentage (%)')
                        //     ->numeric()
                        //     ->minValue(0)
                        //     ->maxValue(100)
                        //     ->default(0)
                        //     ->step(0.01)
                        //     ->suffix('%')
                        //     ->visible(fn($get) => $get('driver_gets_share'))
                        //     ->required(fn($get) => $get('driver_gets_share'))
                        //     ->helperText('Percentage of cancellation fee that goes to driver'),
                    ]),

                Section::make('Status')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active policies are applied to bookings'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Policy Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('city.name')
                    ->label('City')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Default'),

                TextColumn::make('rideType.name')
                    ->label('Ride Type')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Default'),

                TextColumn::make('free_cancellation_window')
                    ->label('Free Window')
                    ->formatStateUsing(fn($state) => $state . 's')
                    ->sortable(),

                TextColumn::make('cancellation_fee')
                    ->label('Fixed Fee')
                    ->money('INR')
                    ->sortable(),

                TextColumn::make('cancellation_fee_percentage')
                    ->label('Fee %')
                    ->formatStateUsing(fn($state) => $state . '%')
                    ->sortable(),

                IconColumn::make('driver_gets_share')
                    ->label('Driver Share')
                    ->boolean()
                    ->hidden()
                    ->sortable(),

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
                TernaryFilter::make('is_active')
                    ->label('Active Status'),
                TernaryFilter::make('allow_customer_cancellation')
                    ->label('Allows Cancellation'),
            ])
            ->actions([
                EditAction::make()
                    ->visible(fn($record) => !(auth()->id() === 2 && in_array($record->id, [1, 2, 3, 4, 5, 6, 7]))),
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
                            ->body('The cancellation policy has been deleted.')
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
                                ->body(count($records) . ' cancellation policy(ies) have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCancellationPolicies::route('/'),
            'create' => CreateCancellationPolicy::route('/create'),
            'edit' => EditCancellationPolicy::route('/{record}/edit'),
        ];
    }
}
