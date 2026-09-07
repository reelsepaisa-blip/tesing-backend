<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DriverProfileRelationManager extends RelationManager
{
    protected static string $relationship = 'driverProfile';

    protected static ?string $recordTitleAttribute = 'license_number';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('License Information')
                    ->schema([
                        Forms\Components\TextInput::make('license_number')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Forms\Components\DatePicker::make('license_expiry')
                            ->required(),
                        Forms\Components\Select::make('verification_status')
                            ->options([
                                'pending' => 'Pending',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ])
                            ->required(),
                        Forms\Components\Textarea::make('rejection_reason')
                            ->maxLength(65535)
                            ->visible(fn (Get $get) => $get('verification_status') === 'rejected'),
                    ])->columns(2),

                Section::make('Status')
                    ->schema([
                        Forms\Components\Toggle::make('is_online')
                            ->required(),
                        Forms\Components\Toggle::make('is_available')
                            ->required(),
                    ])->columns(2),

                Section::make('Location')
                    ->schema([
                        Forms\Components\TextInput::make('current_latitude')
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90),
                        Forms\Components\TextInput::make('current_longitude')
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180),
                    ])->columns(2),

                Section::make('Performance')
                    ->schema([
                        Forms\Components\TextInput::make('rating')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(5)
                            ->step(0.1),
                        Forms\Components\TextInput::make('total_trips')
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\TextInput::make('commission_rate')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),
                    ])->columns(3),

                Section::make('Service Zones')
                    ->schema([
                        Forms\Components\KeyValue::make('service_zones')
                            ->keyLabel('Zone ID')
                            ->valueLabel('Status')
                            ->addable()
                            ->deletable(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('license_number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('license_expiry')
                    ->date()
                    ->sortable(),
                Tables\Columns\SelectColumn::make('verification_status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_online')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_available')
                    ->boolean(),
                Tables\Columns\TextColumn::make('rating')
                    ->numeric(2)
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_trips')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('commission_rate')
                    ->numeric(2)
                    ->suffix('%')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('verification_status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),
                Tables\Filters\TernaryFilter::make('is_online'),
                Tables\Filters\TernaryFilter::make('is_available'),
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























