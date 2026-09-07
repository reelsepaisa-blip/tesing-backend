<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\DocumentListResource\Pages\ListDocumentLists;
use App\Filament\Resources\DocumentListResource\Pages\CreateDocumentList;
use App\Filament\Resources\DocumentListResource\Pages\EditDocumentList;
use App\Filament\Resources\DocumentListResource\Pages;
use App\Filament\Resources\DocumentListResource\RelationManagers;
use App\Models\DocumentList;
use App\Models\SystemConfiguration;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DocumentListResource extends Resource
{
    protected static ?string $model = DocumentList::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static string | \UnitEnum | null $navigationGroup = 'Driver Management';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Select::make('type')
                    ->options([
                        'driver' => 'Driver Document',
                        'vehicle' => 'Vehicle Document',
                    ])
                    ->required(),





                Toggle::make('is_required')
                    ->label('Required')
                    ->default(true)
                    ->hidden(),

                TextInput::make('sort_order')
                    ->label('Sort Order')
                    ->numeric()
                    ->default(0),

                Textarea::make('description')
                    ->maxLength(1000),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),

                Toggle::make('is_new')
                    ->label('Is New Requirement')
                    ->helperText('Enable when this document is newly required so drivers are notified.')
                    ->reactive()
                    ->default(false),

                TextInput::make('upload_deadline_hours')
                    ->label('Document Upload Deadline (Hours)')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(720)
                    ->default(fn() => SystemConfiguration::getValue('document_upload_deadline_hours', 24))
                    ->hidden(fn(callable $get) => !$get('is_new'))
                    ->required(fn(callable $get) => (bool) $get('is_new'))
                    ->mutateDehydratedStateUsing(function ($state, callable $get) {
                        return $get('is_new') ? (int) $state : null;
                    })
                    ->hint('Deadline for drivers to upload the new document once notified.'),


            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'driver' => 'success',
                        'vehicle' => 'info',
                    }),

                TextColumn::make('description')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_required')
                    ->label('Required')
                    ->hidden()
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('sort_order')
                    ->label('Sort Order')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'driver' => 'Driver Document',
                        'vehicle' => 'Vehicle Document',
                    ]),

                TernaryFilter::make('is_active')
                    ->label('Active'),
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
                            ->body('The document list has been deleted.')
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
                                ->body(count($records) . ' document list(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('sort_order');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentLists::route('/'),
            'create' => CreateDocumentList::route('/create'),
            'edit' => EditDocumentList::route('/{record}/edit'),
        ];
    }
}
