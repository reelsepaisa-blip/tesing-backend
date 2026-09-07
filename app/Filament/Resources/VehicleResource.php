<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Utilities\Get;

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Group;

use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;

use App\Filament\Resources\VehicleResource\Pages;
use App\Filament\Resources\VehicleResource\RelationManagers;
use App\Models\Vehicle;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Forms;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VehicleResource extends Resource
{
    use \App\Traits\HasResourcePermissions;

    public static function getPermissionResourceName(): string
    {
        return 'vehicles';
    }

    protected static ?string $model = Vehicle::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-truck';

    protected static string | \UnitEnum | null $navigationGroup = 'Fleet Management';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Driver & Type')
                    ->schema([
                        Forms\Components\Select::make('driver_id')
                            ->relationship('driver', 'name')
                            ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('email')
                                    ->email()
                                    ->required()
                                    ->maxLength(255)
                                    ->unique('users', 'email'),
                                Forms\Components\TextInput::make('phone')
                                    ->required()
                                    ->maxLength(20)
                                    ->unique('users', 'phone'),
                                Forms\Components\TextInput::make('password')
                                    ->password()
                                    ->required()
                                    ->minLength(8),
                            ]),
                        Forms\Components\Select::make('ride_type_id')
                            ->relationship('rideType', 'name')
                            ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Vehicle Details')
                    ->schema([
                        Forms\Components\TextInput::make('brand')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('model')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('year')
                            ->required()
                            ->maxLength(4),
                        Forms\Components\TextInput::make('color')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Registration Details')
                    ->schema([
                        Forms\Components\TextInput::make('license_plate')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Forms\Components\TextInput::make('registration_number')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Forms\Components\DatePicker::make('registration_expiry')
                            ->required(),
                        Forms\Components\DatePicker::make('insurance_expiry')
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Status')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                                'maintenance' => 'Maintenance',
                                'rejected' => 'Rejected',
                            ])
                            ->required(),
                        Forms\Components\Textarea::make('rejection_reason')
                            ->maxLength(65535)
                            ->visible(fn(Get $get) => $get('status') === 'rejected'),
                    ])
                    ->columns(2),
                Section::make('Features')
                    ->schema([
                        Forms\Components\Repeater::make('features')
                            ->schema([
                                Forms\Components\TextInput::make('feature')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('value')
                                    ->required()
                                    ->maxLength(255),
                            ])
                            ->columns(2)
                            ->collapsible()
                            ->itemLabel(fn(array $state): ?string => $state['feature'] ?? null),
                    ]),
                Section::make('Documents')
                    ->schema([
                        Forms\Components\Repeater::make('documents')
                            ->schema([
                                Forms\Components\TextInput::make('type')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\FileUpload::make('url')
                                    ->required()
                                    ->directory('vehicle-documents')
                                    ->acceptedFileTypes(['application/pdf', 'image/*'])
                                    ->maxSize(5120),
                            ])
                            ->columns(2)
                            ->collapsible()
                            ->itemLabel(fn(array $state): ?string => $state['type'] ?? null),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver.name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rideType.name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('brand')
                    ->searchable(),
                Tables\Columns\TextColumn::make('model')
                    ->searchable(),
                Tables\Columns\TextColumn::make('year')
                    ->sortable(),
                Tables\Columns\TextColumn::make('license_plate')
                    ->searchable(),
                Tables\Columns\TextColumn::make('registration_expiry')
                    ->date()
                    ->sortable()
                    ->color(
                        fn($record): string =>
                            $record->registration_expiry < now() ? 'danger' : ($record->registration_expiry < now()->addMonth() ? 'warning' : 'success')
                    ),
                Tables\Columns\TextColumn::make('insurance_expiry')
                    ->date()
                    ->sortable()
                    ->color(
                        fn($record): string =>
                            $record->insurance_expiry < now() ? 'danger' : ($record->insurance_expiry < now()->addMonth() ? 'warning' : 'success')
                    ),
                Tables\Columns\SelectColumn::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'maintenance' => 'Maintenance',
                        'rejected' => 'Rejected',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('driver')
                    ->relationship('driver', 'name')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                    ->searchable()
                    ->preload()
                    ->multiple(),
                Tables\Filters\SelectFilter::make('ride_type')
                    ->relationship('rideType', 'name')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                    ->multiple(),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'maintenance' => 'Maintenance',
                        'rejected' => 'Rejected',
                    ])
                    ->multiple(),
                Tables\Filters\Filter::make('registration_expiring')
                    ->query(fn(Builder $query): Builder => $query->where('registration_expiry', '<=', now()->addMonth())),
                Tables\Filters\Filter::make('insurance_expiring')
                    ->query(fn(Builder $query): Builder => $query->where('insurance_expiry', '<=', now()->addMonth())),
            ])
            ->actions([
                 EditAction::make(),
                 DeleteAction::make(),
                 Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn(Vehicle $record): bool => $record->status === 'pending')
                    ->action(fn(Vehicle $record) => $record->update(['status' => 'active'])),
                 Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->required()
                            ->maxLength(65535),
                    ])
                    ->visible(fn(Vehicle $record): bool => $record->status === 'pending')
                    ->action(fn(Vehicle $record, array $data) => $record->update([
                        'status' => 'rejected',
                        'rejection_reason' => $data['rejection_reason'],
                    ])),
            ])
            ->bulkActions([
                 BulkActionGroup::make([
                     DeleteBulkAction::make(),
                     BulkAction::make('approve')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn(Collection $records) => $records->each->update(['status' => 'active']))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicles::route('/'),
            'create' => Pages\CreateVehicle::route('/create'),
            'edit' => Pages\EditVehicle::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'pending')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::where('status', 'pending')->count() ? 'warning' : null;
    }
}
