<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use App\Filament\Resources\SystemConfigurationResource\Pages;
use App\Models\SystemConfiguration;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\BulkAction;
use Filament\Schemas\Components\Section;

class SystemConfigurationResource extends BaseResource
{
    protected static ?string $model = SystemConfiguration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'API Keys & Config';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Configuration Details')
                    ->schema([
                        Forms\Components\Select::make('category')
                            ->required()
                            ->options([
                                'payment' => 'Payment Gateways',
                                'notification' => 'Notifications',
                                'sms' => 'SMS Services',
                                'maps' => 'Maps & Location',
                                'general' => 'General Settings',
                                'social' => 'Social Login',
                                'driver' => 'Driver Settings',
                                'support' => 'Support & Refunds',
                            ])
                            ->reactive()
                            ->afterStateUpdated(fn($state, callable $set) => $set('key', '')),

                        Forms\Components\Select::make('key')
                            ->required()
                            ->options(function (callable $get) {
                                $category = $get('category');
                                return match ($category) {
                                    'payment' => [
                                        'razorpay_key_id' => 'Razorpay Key ID',
                                        'razorpay_key_secret' => 'Razorpay Key Secret',
                                        'razorpay_webhook_secret' => 'Razorpay Webhook Secret',
                                        'stripe_key' => 'Stripe Publishable Key',
                                        'stripe_secret' => 'Stripe Secret Key',
                                        'stripe_webhook_secret' => 'Stripe Webhook Secret',
                                    ],
                                    'notification' => [
                                        'fcm_server_key' => 'FCM Server Key',
                                        'fcm_sender_id' => 'FCM Sender ID',
                                        'fcm_project_id' => 'FCM Project ID',
                                    ],
                                    'sms' => [
                                        'twilio_sid' => 'Twilio Account SID',
                                        'twilio_token' => 'Twilio Auth Token',
                                        'twilio_from' => 'Twilio Phone Number',
                                        'msg91_api_key' => 'MSG91 API Key',
                                        'msg91_sender_id' => 'MSG91 Sender ID',
                                    ],
                                    'maps' => [
                                        'google_maps_api_key' => 'Google Maps API Key',
                                    ],
                                    'social' => [
                                        'google_client_id' => 'Google OAuth Client ID',
                                        'google_client_secret' => 'Google OAuth Client Secret',
                                        'apple_client_id' => 'Apple Sign-In Client ID',
                                        'apple_client_secret' => 'Apple Sign-In Client Secret',
                                    ],
                                    'general' => [
                                        'app_name' => 'Application Name',
                                        'support_email' => 'Support Email',
                                        'support_phone' => 'Support Phone',
                                        'company_address' => 'Company Address',
                                    ],
                                    'driver' => [
                                        'document_upload_deadline_hours' => 'Document Upload Deadline (Hours)',
                                    ],
                                    'support' => [
                                        'refund_required_hours' => 'Refund Required Hours',
                                    ],
                                    default => [],
                                };
                            })
                            ->searchable(),

                        Forms\Components\Textarea::make('value')
                            ->required()
                            ->label('Value')
                            ->helperText(function (callable $get) {
                                $key = $get('key');
                                return match ($key) {
                                    'document_upload_deadline_hours' => 'Number of hours drivers have to upload new documents (default: 24)',
                                    'refund_required_hours' => 'Number of hours support requires to review refund requests (default: 48)',
                                    default => 'This value will be encrypted and stored securely',
                                };
                            })
                            ->maxLength(1000)
                            ->rows(3),

                        Forms\Components\TextInput::make('description')
                            ->maxLength(255)
                            ->helperText('Optional description for this configuration'),

                        Forms\Components\Toggle::make('is_encrypted')
                            ->label('Encrypt Value')
                            ->default(true)
                            ->helperText('Whether to encrypt this value in the database'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Whether this configuration is active'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'payment' => 'success',
                        'notification' => 'info',
                        'sms' => 'warning',
                        'maps' => 'primary',
                        'social' => 'secondary',
                        'driver' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('key')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('value')
                    ->label('Value')
                    ->formatStateUsing(function ($state, $record) {
                        if ($record->is_encrypted) {
                            return str_repeat('*', min(strlen($state), 20));
                        }
                        return \Str::limit($state, 30);
                    })
                    ->color('gray'),

                Tables\Columns\TextColumn::make('description')
                    ->limit(50)
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        $state = $column->getState();
                        return strlen($state) > 50 ? $state : null;
                    }),

                Tables\Columns\IconColumn::make('is_encrypted')
                    ->label('Encrypted')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options([
                        'payment' => 'Payment Gateways',
                        'notification' => 'Notifications',
                        'sms' => 'SMS Services',
                        'maps' => 'Maps & Location',
                        'general' => 'General Settings',
                        'social' => 'Social Login',
                        'driver' => 'Driver Settings',
                        'support' => 'Support & Refunds',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),

                Tables\Filters\TernaryFilter::make('is_encrypted')
                    ->label('Encryption Status'),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('activate')
                        ->label('Activate Selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->action(function ($records) {
                            $records->each->update(['is_active' => true]);
                        }),
                    BulkAction::make('deactivate')
                        ->label('Deactivate Selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->action(function ($records) {
                            $records->each->update(['is_active' => false]);
                        }),
                ]),
            ])
            ->defaultSort('category')
            ->striped();
    }

    public static function getRelations(): array
    {
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSystemConfigurations::route('/'),
            'create' => Pages\CreateSystemConfiguration::route('/create'),
            'view' => Pages\ViewSystemConfiguration::route('/{record}'),
            'edit' => Pages\EditSystemConfiguration::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->orderBy('category')
            ->orderBy('key');
    }
}
