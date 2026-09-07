<?php

namespace App\Filament\Resources\CityResource\RelationManagers;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\KeyValue;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\AttachAction;
use Filament\Actions\EditAction;
use Filament\Actions\DetachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachBulkAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RideTypesRelationManager extends RelationManager
{
    protected static string $relationship = 'rideTypes';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Basic Information')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('capacity')
                            ->required()
                            ->numeric()
                            ->minValue(1),
                        Toggle::make('is_active')
                            ->required(),
                    ])->columns(2),

                Section::make('Base Pricing')
                    ->schema([
                        TextInput::make('base_fare')
                            ->required()
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('per_kilometer_rate')
                            ->required()
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('per_minute_rate')
                            ->required()
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('minimum_fare')
                            ->required()
                            ->numeric()
                            ->minValue(0),
                    ])->columns(2),

                Section::make('Distance & Time Settings')
                    ->schema([
                        TextInput::make('minimum_distance')
                            ->required()
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('cancellation_time_limit')
                            ->required()
                            ->numeric()
                            ->minValue(0),

                    ])->columns(3),

                Section::make('Advanced Pricing')
                    ->schema([
                        KeyValue::make('distance_slabs')
                            ->keyLabel('Distance Range')
                            ->valueLabel('Rate')
                            ->addable()
                            ->deletable(),
                        KeyValue::make('time_multipliers')
                            ->keyLabel('Time Range')
                            ->valueLabel('Multiplier')
                            ->addable()
                            ->deletable(),
                    ]),

                Section::make('Settings')
                    ->schema([
                        KeyValue::make('settings')
                            ->keyLabel('Setting Name')
                            ->valueLabel('Value')
                            ->addable()
                            ->deletable(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('capacity')
                    ->numeric(),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('base_fare')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('per_kilometer_rate')
                    ->numeric(2)
                    ->suffix('/km')
                    ->sortable(),
                TextColumn::make('minimum_fare')
                    ->money('INR')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active'),
            ])
            ->headerActions([
                AttachAction::make(),
            ])
            ->actions([
                EditAction::make(),
                DetachAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }
}
