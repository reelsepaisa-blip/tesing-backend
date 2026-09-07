<?php

namespace App\Filament\Resources;

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

use App\Filament\Resources\TermsConditionResource\Pages;
use App\Models\TermsCondition;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TermsConditionResource extends Resource
{
    protected static ?string $model = TermsCondition::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Terms & Conditions';
    protected static ?string $modelLabel = 'Terms & Conditions';
    protected static ?string $pluralModelLabel = 'Terms & Conditions';
    protected static string | \UnitEnum | null $navigationGroup = 'Content Management';
    protected static ?int $navigationSort = 200;

    public static function getNavigationUrl(): string
    {

        return static::getUrl('index');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Terms & Conditions Content')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Title')
                            ->default('Terms & Conditions')
                            ->maxLength(255)
                            ->required(),

                        Forms\Components\Textarea::make('intro_text')
                            ->label('Introduction Text')
                            ->rows(3)
                            ->maxLength(1000)
                            ->helperText('Opening text before the terms list'),

                        Forms\Components\Repeater::make('sections')
                            ->label('Terms Sections')
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label('Section Title')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\RichEditor::make('content')
                                    ->label('Section Content')
                                    ->required(),
                                Forms\Components\TextInput::make('order')
                                    ->label('Order')
                                    ->numeric()
                                    ->default(0)
                                    ->hidden(),
                            ])
                            ->columnSpanFull()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                            ->defaultItems(1)
                            ->orderColumn('order'),

                        Forms\Components\Textarea::make('conclusion_text')
                            ->label('Conclusion Text')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Optional closing text'),

                        Forms\Components\TextInput::make('version')
                            ->label('Version')
                            ->default('1.0')
                            ->maxLength(50)
                            ->helperText('Version number for tracking changes'),

                        Forms\Components\DateTimePicker::make('effective_date')
                            ->label('Effective Date')
                            ->default(now())
                            ->helperText('When these terms become effective'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active terms are shown in the app'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('version')
                    ->label('Version')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('effective_date')
                    ->label('Effective Date')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->actions([
                 ViewAction::make(),
                 EditAction::make(),
                 DeleteAction::make()
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        // Block deletion for restricted users (ID 2)
                        $userId = auth()->id();
                        if ($userId === 2) {
                            \Filament\Notifications\Notification::make()
                                ->title('Access Restricted')
                                ->body('In demo mode you are not deleting data...')
                                ->danger()
                                ->persistent()
                                ->send();
                            return false;
                        }
                        
                        // Proceed with normal deletion
                        $record->delete();
                        
                        \Filament\Notifications\Notification::make()
                            ->title('Deleted')
                            ->body('The terms & conditions have been deleted.')
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
                                \Filament\Notifications\Notification::make()
                                    ->title('Access Restricted')
                                    ->body('In demo mode you are not deleting data...')
                                    ->danger()
                                    ->persistent()
                                    ->send();
                                return;
                            }
                            
                            // Default bulk delete behavior
                            foreach ($records as $record) {
                                $record->delete();
                            }
                            
                            \Filament\Notifications\Notification::make()
                                ->title('Deleted')
                                ->body(count($records) . ' terms & condition(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('effective_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTermsConditions::route('/'),
            'create' => Pages\CreateTermsCondition::route('/create'),
            'edit' => Pages\EditTermsCondition::route('/{record}/edit'),
        ];
    }
}
