<?php

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Repeater;
use Exception;
use Filament\Actions\Action;
use App\Models\SystemConfiguration;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class AppSettings extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'App Settings';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.pages.app-settings';

    public ?array $data = [];

    public function mount(): void
    {
        // Standard settings keys
        $standardSettings = [
            'app_name',
            'app_version',
            'support_email',
            'support_phone',
            'app_description',
            'minimum_app_version',
            'maintenance_mode',
            'maintenance_message',
            'appstore_id',
            'apple_share_link',
            'android_share_link',
            'currency',
            'country_code',
        ];

        // Load existing settings
        $allSettings = SystemConfiguration::active()
            ->where('category', 'app_settings')
            ->get();

        $settings = [];
        $customSettings = [];

        foreach ($allSettings as $config) {
            if (in_array($config->key, $standardSettings)) {
                // Handle boolean values
                if ($config->key === 'maintenance_mode') {
                    $settings[$config->key] = $config->value === '1' || $config->value === 'true';
                } else {
                    $settings[$config->key] = $config->value;
                }
            } else {
                // This is a custom setting
                $customSettings[] = [
                    'key' => $config->key,
                    'value' => $config->value,
                ];
            }
        }

        $settings['custom_settings'] = $customSettings;
        $this->form->fill($settings);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General App Settings')
                    ->description('These settings will be available via the public API endpoint without authentication.')
                    ->schema([
                        TextInput::make('app_name')
                            ->label('App Name')
                            ->helperText('The name of your application')
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('app_version')
                            ->label('App Version')
                            ->helperText('Current version of the mobile app')
                            ->maxLength(50)
                            ->columnSpanFull(),

                        TextInput::make('support_email')
                            ->label('Support Email')
                            ->helperText('Email address for customer support')
                            ->email()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('support_phone')
                            ->label('Support Phone')
                            ->helperText('Phone number for customer support')
                            ->tel()
                            ->maxLength(50)
                            ->columnSpanFull(),

                        Textarea::make('app_description')
                            ->label('App Description')
                            ->helperText('Brief description of the application')
                            ->rows(3)
                            ->maxLength(500)
                            ->columnSpanFull(),

                        TextInput::make('minimum_app_version')
                            ->label('Minimum App Version')
                            ->helperText('Minimum required app version for users')
                            ->maxLength(50)
                            ->columnSpanFull(),

                        Toggle::make('maintenance_mode')
                            ->label('Maintenance Mode')
                            ->helperText('Enable to put the app in maintenance mode')
                            ->default(false)
                            ->columnSpanFull(),

                        Textarea::make('maintenance_message')
                            ->label('Maintenance Message')
                            ->helperText('Message to display during maintenance mode')
                            ->rows(2)
                            ->maxLength(500)
                            ->visible(fn(Get $get) => $get('maintenance_mode'))
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(false),

                Section::make('App Store & Share Links')
                    ->description('Configure app store IDs and share links for iOS and Android')
                    ->schema([
                        TextInput::make('appstore_id')
                            ->label('App Store ID')
                            ->helperText('Apple App Store ID (e.g., 123456789)')
                            ->maxLength(50)
                            ->numeric()
                            ->columnSpanFull(),

                        TextInput::make('apple_share_link')
                            ->label('Apple Share Link')
                            ->helperText('Share link for iOS app (e.g., https://apps.apple.com/app/id123456789)')
                            ->url()
                            ->maxLength(500)
                            ->columnSpanFull(),

                        TextInput::make('android_share_link')
                            ->label('Android Share Link')
                            ->helperText('Share link for Android app (e.g., https://play.google.com/store/apps/details?id=com.example.app)')
                            ->url()
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(false),

                Section::make('Localization Settings')
                    ->description('Configure currency and country code for the application')
                    ->schema([
                        TextInput::make('currency')
                            ->label('Currency')
                            ->helperText('Default currency code (e.g., USD, INR, EUR)')
                            ->maxLength(10)
                            ->placeholder('INR')
                            ->columnSpanFull(),

                        TextInput::make('country_code')
                            ->label('Country Code')
                            ->helperText('Default country code (e.g., IN, US, GB)')
                            ->maxLength(10)
                            ->placeholder('IN')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(false),

                Section::make('Additional Settings')
                    ->description('Add custom key-value pairs for app settings')
                    ->schema([
                        Repeater::make('custom_settings')
                            ->label('Custom Settings')
                            ->schema([
                                TextInput::make('key')
                                    ->label('Key')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('e.g., feature_enabled')
                                    ->helperText('Setting key (will be used in API response)'),

                                TextInput::make('value')
                                    ->label('Value')
                                    ->required()
                                    ->maxLength(500)
                                    ->placeholder('e.g., true, 100, text value')
                                    ->helperText('Setting value'),
                            ])
                            ->defaultItems(0)
                            ->addActionLabel('Add Custom Setting')
                            ->collapsible()
                            ->itemLabel(fn(array $state): ?string => $state['key'] ?? null)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->hidden()
                    ->collapsed(true),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        // Check demo mode
        if (config('app.demo_mode', false)) {
            Notification::make()
                ->title('Demo Mode Enabled')
                ->body('Demo mode is enabled. Changes to app settings are disabled to protect demo data.')
                ->danger()
                ->send();
            return;
        }

        $data = $this->form->getState();

        try {
            // Save standard settings
            $standardSettings = [
                'app_name',
                'app_version',
                'support_email',
                'support_phone',
                'app_description',
                'minimum_app_version',
                'maintenance_mode',
                'maintenance_message',
                'appstore_id',
                'apple_share_link',
                'android_share_link',
                'currency',
                'country_code',
            ];

            foreach ($standardSettings as $key) {
                if (isset($data[$key])) {
                    SystemConfiguration::updateOrCreate(
                        ['key' => $key],
                        [
                            'value' => is_bool($data[$key]) ? ($data[$key] ? '1' : '0') : (string) $data[$key],
                            'category' => 'app_settings',
                            'description' => $this->getSettingDescription($key),
                            'is_active' => true,
                            'is_encrypted' => false,
                        ]
                    );
                }
            }

            // Save custom settings
            if (isset($data['custom_settings']) && is_array($data['custom_settings'])) {
                foreach ($data['custom_settings'] as $customSetting) {
                    if (!empty($customSetting['key']) && isset($customSetting['value'])) {
                        SystemConfiguration::updateOrCreate(
                            ['key' => $customSetting['key']],
                            [
                                'value' => (string) $customSetting['value'],
                                'category' => 'app_settings',
                                'description' => 'Custom app setting',
                                'is_active' => true,
                                'is_encrypted' => false,
                            ]
                        );
                    }
                }
            }

            Notification::make()
                ->title('Settings saved successfully')
                ->body('App settings have been updated and are now available via the API.')
                ->success()
                ->send();
        } catch (Exception $e) {
            Notification::make()
                ->title('Error saving settings')
                ->body('An error occurred while saving settings: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function getSettingDescription(string $key): string
    {
        $descriptions = [
            'app_name' => 'Application name',
            'app_version' => 'Current app version',
            'support_email' => 'Support email address',
            'support_phone' => 'Support phone number',
            'app_description' => 'Application description',
            'minimum_app_version' => 'Minimum required app version',
            'maintenance_mode' => 'Maintenance mode status',
            'maintenance_message' => 'Maintenance mode message',
            'appstore_id' => 'Apple App Store ID',
            'apple_share_link' => 'Apple App Store share link',
            'android_share_link' => 'Google Play Store share link',
            'currency' => 'Default currency code',
            'country_code' => 'Default country code',
        ];

        return $descriptions[$key] ?? 'App setting';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save Settings')
                ->color('success')
                ->requiresConfirmation(false)
                ->action(fn() => $this->save())
                ->keyBindings(['mod+s']),
        ];
    }

    public function getHeading(): string
    {
        return 'App Settings';
    }

    public function getSubheading(): string
    {
        return 'Manage application settings that are available via the public API endpoint.';
    }
}
