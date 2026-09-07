<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\BulkAction;
use App\Filament\Resources\BannerImageResource\Pages\ListBannerImages;
use App\Filament\Resources\BannerImageResource\Pages\CreateBannerImage;
use App\Filament\Resources\BannerImageResource\Pages\EditBannerImage;
use App\Filament\Resources\BannerImageResource\Pages;
use App\Filament\Resources\BannerImageResource\RelationManagers;
use App\Models\BannerImage;
use App\Models\City;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Filament\Forms\Components\Actions\Action;

class BannerImageResource extends Resource
{
    protected static ?string $model = BannerImage::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-photo';
    protected static ?string $navigationLabel = 'Banner Images';
    protected static ?string $modelLabel = 'Banner Image';
    protected static ?string $pluralModelLabel = 'Banner Images';
    protected static string | \UnitEnum | null $navigationGroup = 'Content Management';

    protected static ?int $navigationSort = 201;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Banner Image Details')
                    ->description('Upload and configure banner images for the mobile app')
                    ->schema([
                        Select::make('city_ids')
                            ->label('Cities')
                            ->options(fn() => City::query()
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn(City $city) => [
                                    $city->id => collect([$city->name, $city->state, $city->country])
                                        ->filter()
                                        ->implode(', '),
                                ]))
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload()
                            ->columnSpanFull()
                            ->helperText('Select one or more cities to create copies of this banner')
                            ->visibleOn('create'),

                        Select::make('city_id')
                            ->label('City')
                            ->relationship('city', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Select the city for this banner')
                            ->visibleOn('edit')
                            ->getOptionLabelFromRecordUsing(fn(City $record): string => "{$record->name}, {$record->state}, {$record->country}"),

                        FileUpload::make('image_path')
                            ->label('Banner Image')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->rules(['mimes:jpg,jpeg,png,webp'])
                            ->maxSize(1024) // 1MB
                            ->helperText('Upload banner image (PNG, JPG, or WebP, max 312x180px for first row, max 1MB)')
                            ->disk('public')
                            ->directory('banner-images')
                            ->visibility('public')
                            ->required()
                            ->columnSpanFull()
                            ->afterStateUpdated(function ($state, $set) {

                                if ($state) {
                                    $set('image_url', url(Storage::url($state)));
                                }
                            }),

                        TextInput::make('title')
                            ->label('Title')
                            ->maxLength(255)
                            ->helperText('Optional title for the banner'),

                        Textarea::make('description')
                            ->label('Description')
                            ->maxLength(500)
                            ->rows(3)
                            ->helperText('Optional description for the banner'),

                        Select::make('row_position')
                            ->label('Row Position')
                            ->options([
                                'first' => 'First Row',
                                'second' => 'Second Row',
                            ])
                            ->required()
                            ->default('first')
                            ->helperText('Choose which row this banner appears in'),

                        TextInput::make('sort_order')
                            ->label('Sort Order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Lower numbers appear first'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active banners are shown in the app'),
                    ])
                    ->columns(2),

                Section::make('Link Configuration')
                    ->description('Optional link settings for the banner')
                    ->schema([
                        TextInput::make('link_url')
                            ->label('Link URL')
                            ->url()
                            ->maxLength(500)
                            ->helperText('Optional URL to redirect when banner is tapped'),

                        TextInput::make('link_text')
                            ->label('Link Text')
                            ->maxLength(100)
                            ->helperText('Optional text to display for the link'),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Image')
                    ->disk('public')
                    ->size(60)
                    ->square(),

                TextColumn::make('city.name')
                    ->label('City')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn($record) => $record->city
                        ? "{$record->city->name}, {$record->city->state}"
                        : 'N/A')
                    ->badge()
                    ->color('info'),

                TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                TextColumn::make('row_position')
                    ->label('Row')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'first' => 'success',
                        'second' => 'warning',
                    }),

                TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable()
                    ->alignCenter(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('file_size')
                    ->label('Size')
                    ->formatStateUsing(fn($record) => $record->getFormattedSize())
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
                    ->preload()
                    ->multiple(),

                SelectFilter::make('row_position')
                    ->label('Row Position')
                    ->options([
                        'first' => 'First Row',
                        'second' => 'Second Row',
                    ]),

                TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->placeholder('All banners')
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
                            ->body('The banner image has been deleted.')
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
                                ->body(count($records) . ' banner image(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('activate')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn($records) => $records->each->update(['is_active' => true]))
                        ->requiresConfirmation(),
                    BulkAction::make('deactivate')
                        ->icon('heroicon-o-x-circle')
                        ->action(fn($records) => $records->each->update(['is_active' => false]))
                        ->requiresConfirmation(),
                ]),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order');
    }

    public static function getRelations(): array
    {
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBannerImages::route('/'),
            'create' => CreateBannerImage::route('/create'),
            'edit' => EditBannerImage::route('/{record}/edit'),
        ];
    }
}
