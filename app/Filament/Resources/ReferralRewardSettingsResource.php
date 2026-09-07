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

use App\Filament\Resources\ReferralRewardSettingsResource\Pages;
use App\Filament\Resources\ReferralRewardSettingsResource\RelationManagers;
use App\Models\ReferralRewardSettings;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ReferralRewardSettingsResource extends Resource
{
    protected static ?string $model = ReferralRewardSettings::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-gift';

    protected static string | \UnitEnum | null $navigationGroup = 'Marketing';

    protected static ?string $navigationLabel = 'Referral Rewards';

    protected static ?int $navigationSort = 6;

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->default('Default Referral Settings'),

                Forms\Components\TextInput::make('referrer_reward')
                    ->label('Referrer Reward Amount')
                    ->required()
                    ->numeric()
                    ->prefix('₹')
                    ->default(100.00)
                    ->helperText('Amount to credit to the person who referred someone'),

                Forms\Components\TextInput::make('referred_reward')
                    ->label('Referred User Reward Amount')
                    ->required()
                    ->numeric()
                    ->prefix('₹')
                    ->default(100.00)
                    ->helperText('Amount to credit to the person who was referred'),

                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Only one setting can be active at a time'),

                Forms\Components\Textarea::make('description')
                    ->label('Description')
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

                Tables\Columns\TextColumn::make('referrer_reward')
                    ->label('Referrer Reward')
                    ->money('INR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('referred_reward')
                    ->label('Referred Reward')
                    ->money('INR')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
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
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ])
            ->actions([
                 EditAction::make(),
                 Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn($record) => !$record->is_active)
                    ->action(function ($record) {

                        ReferralRewardSettings::where('id', '!=', $record->id)
                            ->update(['is_active' => false]);

                        $record->update(['is_active' => true]);
                    })
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                 BulkActionGroup::make([
                     DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferralRewardSettings::route('/'),
            'create' => Pages\CreateReferralRewardSettings::route('/create'),
            'edit' => Pages\EditReferralRewardSettings::route('/{record}/edit'),
        ];
    }
}
