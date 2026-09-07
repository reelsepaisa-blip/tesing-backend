<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\AboutUsResource\Pages\ListAboutUs;
use App\Filament\Resources\AboutUsResource\Pages\CreateAboutUs;
use App\Filament\Resources\AboutUsResource\Pages\EditAboutUs;
use App\Filament\Resources\AboutUsResource\Pages;
use App\Models\AboutUs;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AboutUsResource extends Resource
{
    protected static ?string $model = AboutUs::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-information-circle';
    protected static ?string $navigationLabel = 'About Us';
    protected static ?string $modelLabel = 'About Us';
    protected static ?string $pluralModelLabel = 'About Us';
    protected static string | \UnitEnum | null $navigationGroup = 'Content Management';
    protected static ?int $navigationSort = 11;

    public static function getNavigationUrl(): string
    {

        return static::getUrl('index');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('About Us Content')
                    ->schema([
                        TextInput::make('title')
                            ->label('Title')
                            ->maxLength(255)
                            ->helperText('Main title for the About Us page'),

                        Textarea::make('intro_text')
                            ->label('Introduction Text')
                            ->rows(3)
                            ->maxLength(1000)
                            ->helperText('Brief introduction or opening text'),

                        RichEditor::make('content')
                            ->label('Content')
                            ->columnSpanFull()
                            ->helperText('Main content for About Us'),

                        TextInput::make('image_url')
                            ->label('Image URL')
                            ->url()
                            ->maxLength(500)
                            ->helperText('Optional image URL')
                            ->hidden(),

                        Repeater::make('sections')
                            ->label('Sections')
                            ->schema([
                                TextInput::make('title')
                                    ->label('Section Title')
                                    ->required()
                                    ->maxLength(255),
                                RichEditor::make('content')
                                    ->label('Section Content')
                                    ->required(),
                            ])
                            ->columnSpanFull()
                            ->collapsible()
                            ->itemLabel(fn(array $state): ?string => $state['title'] ?? null),

                        TextInput::make('sort_order')
                            ->label('Sort Order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Lower numbers appear first')
                            ->hidden(),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active content is shown in the app'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable()
                    ->limit(50),

                TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable()
                    ->hidden()
                    ->alignCenter(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
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
                            Notification::make()
                                ->title('Access Restricted')
                                ->body('In demo mode you are not deleting data...')
                                ->danger()
                                ->persistent()
                                ->send();
                            return false;
                        }
                        
                        // Proceed with normal deletion
                        $record->delete();
                        
                        Notification::make()
                            ->title('Deleted')
                            ->body('The about us content has been deleted.')
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
                                    ->persistent()
                                    ->send();
                                return;
                            }
                            
                            // Default bulk delete behavior
                            foreach ($records as $record) {
                                $record->delete();
                            }
                            
                            Notification::make()
                                ->title('Deleted')
                                ->body(count($records) . ' about us content(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAboutUs::route('/'),
            'create' => CreateAboutUs::route('/create'),
            'edit' => EditAboutUs::route('/{record}/edit'),
        ];
    }
}
