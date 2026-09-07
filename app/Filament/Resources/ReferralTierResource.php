<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;

use App\Filament\Resources\ReferralTierResource\Pages;
use App\Models\ReferralTier;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ReferralTierResource extends Resource
{
    protected static ?string $model = ReferralTier::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-trophy';
    protected static string | \UnitEnum | null $navigationGroup = 'Marketing';
    protected static ?int $navigationSort = 6;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Tier Name')
                    ->maxLength(255),

                Forms\Components\TextInput::make('milestone_count')
                    ->label('Milestone Count (e.g., 5)')
                    ->numeric()
                    ->required(),

                Forms\Components\TextInput::make('bonus_amount')
                    ->label('Bonus Amount')
                    ->numeric()
                    ->prefix('₹')
                    ->required(),

                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),

                Forms\Components\Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('milestone_count')
                    ->label('Milestone')
                    ->sortable(),

                Tables\Columns\TextColumn::make('bonus_amount')
                    ->money('INR')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
                 EditAction::make(),
                 DeleteAction::make(),
            ])
            ->bulkActions([
                 BulkActionGroup::make([
                     DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('milestone_count');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferralTiers::route('/'),
            'create' => Pages\CreateReferralTier::route('/create'),
            'edit' => Pages\EditReferralTier::route('/{record}/edit'),
        ];
    }
}
