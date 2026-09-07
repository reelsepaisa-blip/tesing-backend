<?php

namespace App\Filament\Resources\CityResource\RelationManagers;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\KeyValue;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ZonesRelationManager extends RelationManager
{
    protected static string $relationship = 'zones';

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
                        Toggle::make('status')
                            ->label('Active')
                            ->required()
                            ->rules([
                                function ($livewire) {
                                    return function (string $attribute, $value, Closure $fail) use ($livewire) {
                                        if ($value) { // If trying to activate zone

                                            if ($livewire instanceof RelationManager) {
                                                $city = $livewire->getOwnerRecord();
                                                if ($city && !$city->status) {
                                                    $fail('City is inactive. Cannot activate zone in an inactive city.');
                                                }
                                            }
                                        }
                                    };
                                },
                            ]),
                    ])->columns(2),

                Section::make('Zone Settings')
                    ->schema([
                        TextInput::make('surge_multiplier')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->default(1),
                        Toggle::make('pickup_allowed')
                            ->required(),
                        Toggle::make('drop_allowed')
                            ->required(),
                        TextInput::make('driver_assignment_radius')
                            ->required()
                            ->numeric()
                            ->minValue(100)
                            ->default(5000)
                            ->helperText('Distance in meters'),
                    ])->columns(2),

                Section::make('Zone Coordinates')
                    ->schema([
                        Textarea::make('coordinates')
                            ->required()
                            ->helperText('Enter coordinates in MySQL POLYGON format')
                            ->columnSpanFull(),
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
                IconColumn::make('status')
                    ->boolean()
                    ->label('Active'),
                TextColumn::make('surge_multiplier')
                    ->numeric(2),
                IconColumn::make('pickup_allowed')
                    ->boolean(),
                IconColumn::make('drop_allowed')
                    ->boolean(),
                TextColumn::make('driver_assignment_radius')
                    ->numeric()
                    ->suffix('m'),
            ])
            ->filters([
                TernaryFilter::make('status')
                    ->label('Active'),
                TernaryFilter::make('pickup_allowed'),
                TernaryFilter::make('drop_allowed'),
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
