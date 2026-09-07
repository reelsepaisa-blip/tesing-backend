<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VehiclesRelationManager extends RelationManager
{
    protected static string $relationship = 'vehicles';

    protected static ?string $recordTitleAttribute = 'license_plate';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Basic Information')
                    ->schema([
                        Forms\Components\Select::make('ride_type_id')
                            ->relationship('rideType', 'name')
                            ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                            ->required(),
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
                    ])->columns(2),

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
                    ])->columns(2),

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
                    ])->columns(2),

                Section::make('Features')
                    ->schema([
                        Forms\Components\KeyValue::make('features')
                            ->keyLabel('Feature')
                            ->valueLabel('Value')
                            ->addable()
                            ->deletable(),
                    ]),

                Section::make('Documents')
                    ->schema([
                        Forms\Components\KeyValue::make('documents')
                            ->keyLabel('Document Type')
                            ->valueLabel('Document URL')
                            ->addable()
                            ->deletable(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('rideType.name')
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
                    ->sortable(),
                Tables\Columns\TextColumn::make('insurance_expiry')
                    ->date()
                    ->sortable(),
                Tables\Columns\SelectColumn::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'maintenance' => 'Maintenance',
                        'rejected' => 'Rejected',
                    ])
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('ride_type')
                    ->relationship('rideType', 'name')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown'),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'maintenance' => 'Maintenance',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->headerActions([
                 CreateAction::make(),
            ])
            ->actions([
                 EditAction::make(),
                 DeleteAction::make(),
            ])
            ->bulkActions([
                 BulkActionGroup::make([
                     DeleteBulkAction::make(),
                ]),
            ]);
    }
}
