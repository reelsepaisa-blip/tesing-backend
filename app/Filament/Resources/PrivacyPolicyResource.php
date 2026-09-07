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

use App\Filament\Resources\PrivacyPolicyResource\Pages;
use App\Models\PrivacyPolicy;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PrivacyPolicyResource extends Resource
{
    protected static ?string $model = PrivacyPolicy::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Privacy Policy';
    protected static ?string $modelLabel = 'Privacy Policy';
    protected static ?string $pluralModelLabel = 'Privacy Policies';
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
                Section::make('Privacy Policy Content')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Title')
                            ->default('Privacy Policy')
                            ->maxLength(255)
                            ->required(),

                        Forms\Components\Textarea::make('intro_text')
                            ->label('Introduction Text')
                            ->rows(3)
                            ->maxLength(1000)
                            ->helperText('Opening statement about privacy commitment'),

                        Forms\Components\Repeater::make('sections')
                            ->label('Policy Sections')
                            ->schema([
                                Forms\Components\Select::make('type')
                                    ->label('Section Type')
                                    ->options([
                                        'what_we_collect' => 'What We Collect',
                                        'how_we_use' => 'How We Use It',
                                        'data_sharing' => 'Data Sharing',
                                        'security' => 'Security',
                                        'user_rights' => 'User Rights',
                                        'other' => 'Other',
                                    ])
                                    ->required(),
                                Forms\Components\TextInput::make('title')
                                    ->label('Section Title')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\RichEditor::make('content')
                                    ->label('Section Content')
                                    ->required(),
                                Forms\Components\Repeater::make('items')
                                    ->label('List Items')
                                    ->schema([
                                        Forms\Components\TextInput::make('item')
                                            ->label('Item')
                                            ->required()
                                            ->maxLength(500),
                                    ])
                                    ->defaultItems(0)
                                    ->collapsible(),
                            ])
                            ->columnSpanFull()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                            ->defaultItems(1),

                        Forms\Components\Textarea::make('data_sharing_text')
                            ->label('Data Sharing Statement')
                            ->rows(3)
                            ->maxLength(1000)
                            ->helperText('Statement about data sharing with third parties'),

                        Forms\Components\Textarea::make('user_rights_text')
                            ->label('User Rights Statement')
                            ->rows(3)
                            ->maxLength(1000)
                            ->helperText('Information about user rights to access/edit/delete data'),

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
                            ->helperText('When this policy becomes effective'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active policy is shown in the app'),
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
                            ->body('The privacy policy has been deleted.')
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
                                ->body(count($records) . ' privacy policy(ies) have been deleted.')
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
            'index' => Pages\ListPrivacyPolicies::route('/'),
            'create' => Pages\CreatePrivacyPolicy::route('/create'),
            'edit' => Pages\EditPrivacyPolicy::route('/{record}/edit'),
        ];
    }
}
