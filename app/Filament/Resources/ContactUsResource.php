<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
use App\Filament\Resources\ContactUsResource\Pages\ListContactUs;
use App\Filament\Resources\ContactUsResource\Pages\CreateContactUs;
use App\Filament\Resources\ContactUsResource\Pages\EditContactUs;
use App\Filament\Resources\ContactUsResource\Pages;
use App\Models\ContactUs;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ContactUsResource extends Resource
{
    protected static ?string $model = ContactUs::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-phone';
    protected static ?string $navigationLabel = 'Contact Us';
    protected static ?string $modelLabel = 'Contact Us';
    protected static ?string $pluralModelLabel = 'Contact Us';
    protected static string | \UnitEnum | null $navigationGroup = 'Content Management';
    protected static ?int $navigationSort = 200;

    public static function getNavigationUrl(): string
    {

        return static::getUrl('index');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Contact Information')
                    ->schema([
                        Textarea::make('intro_text')
                            ->label('Introduction Text')
                            ->rows(3)
                            ->maxLength(1000)
                            ->helperText('Opening message for contact page')
                            ->columnSpanFull(),

                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255)
                            ->helperText('Support email address'),

                        TextInput::make('phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(50)
                            ->helperText('Support phone number'),

                        Textarea::make('office_address')
                            ->label('Office Address')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Physical office address')
                            ->columnSpanFull(),

                        TextInput::make('working_hours')
                            ->label('Working Hours')
                            ->maxLength(255)
                            ->helperText('e.g., Monday-Friday, 9 AM - 6 PM'),

                        Textarea::make('support_message')
                            ->label('Support Message')
                            ->rows(3)
                            ->maxLength(1000)
                            ->helperText('Additional message about support availability')
                            ->columnSpanFull(),

                        Repeater::make('additional_contacts')
                            ->label('Additional Contact Methods')
                            ->schema([
                                Select::make('type')
                                    ->label('Type')
                                    ->options([
                                        'email' => 'Email',
                                        'phone' => 'Phone',
                                        'whatsapp' => 'WhatsApp',
                                        'telegram' => 'Telegram',
                                        'facebook' => 'Facebook',
                                        'twitter' => 'Twitter',
                                        'linkedin' => 'LinkedIn',
                                        'other' => 'Other',
                                    ])
                                    ->required(),
                                TextInput::make('label')
                                    ->label('Label')
                                    ->maxLength(255),
                                TextInput::make('value')
                                    ->label('Contact Value')
                                    ->required()
                                    ->maxLength(500),
                                TextInput::make('icon')
                                    ->label('Icon (Optional)')
                                    ->maxLength(100)
                                    ->helperText('Icon name or URL'),
                            ])
                            ->columnSpanFull()
                            ->collapsible()
                            ->itemLabel(fn(array $state): ?string => ($state['label'] ?? $state['type'] ?? 'Contact') . ': ' . ($state['value'] ?? ''))
                            ->hidden(),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active contact info is shown in the app'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->icon('heroicon-o-envelope')
                    ->limit(30),

                TextColumn::make('phone')
                    ->label('Phone')
                    ->searchable()
                    ->icon('heroicon-o-phone')
                    ->limit(20),

                TextColumn::make('office_address')
                    ->label('Address')
                    ->searchable()
                    ->limit(40)
                    ->toggleable(),

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
                            ->body('The contact us content has been deleted.')
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
                                ->body(count($records) . ' contact us content(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactUs::route('/'),
            'create' => CreateContactUs::route('/create'),
            'edit' => EditContactUs::route('/{record}/edit'),
        ];
    }
}
