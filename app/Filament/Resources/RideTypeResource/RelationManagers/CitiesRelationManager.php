<?php

namespace App\Filament\Resources\RideTypeResource\RelationManagers;

use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'cities';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Basic Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('state')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('country')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Toggle::make('is_active')
                            ->required(),
                    ])->columns(2),

                Section::make('Location')
                    ->schema([
                        Forms\Components\TextInput::make('base_latitude')
                            ->required()
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90),
                        Forms\Components\TextInput::make('base_longitude')
                            ->required()
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180),
                    ])->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('state')
                    ->searchable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('base_latitude')
                    ->numeric(8),
                Tables\Columns\TextColumn::make('base_longitude')
                    ->numeric(8),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('country')
                    ->options(fn () => $this->ownerRecord->cities()->pluck('country', 'country')->unique()),
                Tables\Filters\SelectFilter::make('state')
                    ->options(fn () => $this->ownerRecord->cities()->pluck('state', 'state')->unique()),
                Tables\Filters\TernaryFilter::make('is_active'),
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
