<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\CityTaxRuleResource\Pages\ListCityTaxRules;
use App\Filament\Resources\CityTaxRuleResource\Pages\CreateCityTaxRule;
use App\Filament\Resources\CityTaxRuleResource\Pages\EditCityTaxRule;
use App\Filament\Resources\CityTaxRuleResource\Pages;
use App\Models\City;
use App\Models\CityTaxRule;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CityTaxRuleResource extends Resource
{
    protected static ?string $model = CityTaxRule::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';
    protected static string | \UnitEnum | null $navigationGroup = 'Fare Settings';
    protected static ?string $navigationLabel = 'City Tax Rules';
    protected static ?string $modelLabel = 'City Tax Rule';
    protected static ?string $pluralModelLabel = 'City Tax Rules';
    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tax Configuration')
                    ->description('Configure city-specific tax rules for fare calculation')
                    ->schema([
                        Select::make('city_ids')
                            ->label('Cities')
                            ->options(fn() => City::query()->orderBy('name')->pluck('name', 'id'))
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload()







                            ->helperText('Select one or more cities to apply this tax rule')
                            ->visibleOn('create'),

                        Select::make('city_id')
                            ->label('City')
                            ->relationship('city', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->required(),
                                TextInput::make('state'),
                                TextInput::make('country')
                                    ->default('India'),
                            ])
                            ->visibleOn('edit'),

                        TextInput::make('tax_name')
                            ->label('Tax Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., GST, Pollution Tax, Service Tax')
                            ->helperText('Display name for this tax component'),

                        TextInput::make('tax_rate')
                            ->label('Tax Rate (%)')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->suffix('%')
                            ->helperText('Tax rate as percentage of fare'),

                        Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->placeholder('Optional description of this tax component'),

                        TextInput::make('sort_order')
                            ->label('Sort Order')
                            ->numeric()
                            ->default(0)
                            ->hidden()
                            ->helperText('Order in which taxes are applied (lower numbers first)'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active taxes are applied to fares'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('city.name')
                    ->label('City')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('tax_name')
                    ->label('Tax Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('tax_rate')
                    ->label('Tax Rate')
                    ->formatStateUsing(fn($state) => $state . '%')
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Order')
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
                SelectFilter::make('city_id')
                    ->label('City')
                    ->relationship('city', 'name')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ])
            ->actions([
                EditAction::make(),
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
                            ->body('The city tax rule has been deleted.')
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
                                ->body(count($records) . ' city tax rule(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('city_id', 'sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCityTaxRules::route('/'),
            'create' => CreateCityTaxRule::route('/create'),
            'edit' => EditCityTaxRule::route('/{record}/edit'),
        ];
    }
}
