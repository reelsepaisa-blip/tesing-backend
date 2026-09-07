<?php

namespace App\Filament\Pages;

use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use App\Models\SystemConfiguration;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class PaymentSettings extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-credit-card';
    protected static ?string $navigationLabel = 'Payment Methods';
    protected static string | \UnitEnum | null $navigationGroup = 'Finance Management';
    protected static ?int $navigationSort = 8;
    protected string $view = 'filament.pages.payment-settings';

    public ?array $data = [];


    protected function isOnlineGatewayEnabled(Get $get, ?string $excludeField = null): bool
    {
        $razorpayEnabled = false;
        $stripeEnabled = false;

        if ($excludeField !== 'razorpay_enabled') {

            $razorpayFormState = (bool) ($get('razorpay_enabled') ?? false);

            if (!$razorpayFormState && isset($this->data['razorpay_enabled'])) {
                $razorpayEnabled = (bool) $this->data['razorpay_enabled'];
            } else {
                $razorpayEnabled = $razorpayFormState;
            }
        }

        if ($excludeField !== 'stripe_enabled') {

            $stripeFormState = (bool) ($get('stripe_enabled') ?? false);

            if (!$stripeFormState && isset($this->data['stripe_enabled'])) {
                $stripeEnabled = (bool) $this->data['stripe_enabled'];
            } else {
                $stripeEnabled = $stripeFormState;
            }
        }

        return $razorpayEnabled || $stripeEnabled;
    }

    private function shouldMaskData(): bool
    {
        return auth()->check() && auth()->id() === 2;
    }

    private function maskSensitiveData(?string $value): string
    {
        if (empty($value)) {
            return '';
        }
        if (!$this->shouldMaskData()) {
            return $value;
        }
        $length = strlen($value);
        if ($length <= 5) {
            return str_repeat('x', $length);
        }
        return substr($value, 0, $length - 5) . str_repeat('x', 5);
    }

    private function getOriginalValue(string $key): string
    {
        return SystemConfiguration::getValue($key, '');
    }

    public function mount(): void
    {
        $this->form->fill([

            'razorpay_enabled' => SystemConfiguration::getValue('razorpay_enabled', false),
            'razorpay_key_id' => $this->maskSensitiveData(SystemConfiguration::getValue('razorpay_key_id', '')),
            'razorpay_secret_key' => $this->maskSensitiveData(SystemConfiguration::getValue('razorpay_secret_key', '')),
            'razorpay_webhook_url' => SystemConfiguration::getValue('razorpay_webhook_url', ''),
            'razorpay_webhook_secret' => $this->maskSensitiveData(SystemConfiguration::getValue('razorpay_webhook_secret', '')),

            'stripe_enabled' => SystemConfiguration::getValue('stripe_enabled', false),
            'stripe_mode' => SystemConfiguration::getValue('stripe_mode', 'test'),
            'stripe_publishable_key' => $this->maskSensitiveData(SystemConfiguration::getValue('stripe_publishable_key', '')),
            'stripe_secret_key' => $this->maskSensitiveData(SystemConfiguration::getValue('stripe_secret_key', '')),
            'stripe_webhook_url' => SystemConfiguration::getValue('stripe_webhook_url', ''),
            'stripe_webhook_secret' => $this->maskSensitiveData(SystemConfiguration::getValue('stripe_webhook_secret', '')),
            'stripe_currency' => SystemConfiguration::getValue('stripe_currency', 'INR'),

            'paytm_enabled' => SystemConfiguration::getValue('paytm_enabled', false),
            'paytm_mode' => SystemConfiguration::getValue('paytm_mode', 'production'),
            'paytm_merchant_key' => $this->maskSensitiveData(SystemConfiguration::getValue('paytm_merchant_key', '')),
            'paytm_merchant_id' => $this->maskSensitiveData(SystemConfiguration::getValue('paytm_merchant_id', '')),
            'paytm_website' => SystemConfiguration::getValue('paytm_website', 'DEFAULT'),
            'paytm_industry_type' => SystemConfiguration::getValue('paytm_industry_type', 'Retail'),

            'cod_enabled' => SystemConfiguration::getValue('cod_enabled', true),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make('Razorpay Payments')
                    ->description('Configure Razorpay payment gateway settings')
                    ->schema([
                        Toggle::make('razorpay_enabled')
                            ->label('Razorpay Payments [ Enable / Disable ]')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set, Get $get) {

                                if (!$state) {

                                    $stripeEnabled = (bool) ($get('stripe_enabled') ?? false);

                                    if (!$stripeEnabled) {

                                        $set('razorpay_enabled', true);
                                        Notification::make()
                                            ->title('Cannot Disable Razorpay')
                                            ->body('At least one of Razorpay OR Stripe must be active. Please enable Stripe first before disabling Razorpay.')
                                            ->danger()
                                            ->send();
                                    }
                                }
                            })
                            ->columnSpanFull(),

                        TextInput::make('razorpay_key_id')
                            ->label('Razorpay key ID')
                            ->maxLength(255)
                            ->dehydrateStateUsing(function ($state) {
                                // If masked value (ends with 5 x's) and user is ID 2, keep original; otherwise save new value
                                if ($this->shouldMaskData() && preg_match('/x{5}$/', $state)) {
                                    return $this->getOriginalValue('razorpay_key_id');
                                }
                                return $state;
                            })
                            ->columnSpan(1),

                        TextInput::make('razorpay_secret_key')
                            ->label('Secret Key')
                            ->maxLength(255)
                            ->dehydrateStateUsing(function ($state) {
                                if ($this->shouldMaskData() && preg_match('/x{5}$/', $state)) {
                                    return $this->getOriginalValue('razorpay_secret_key');
                                }
                                return $state;
                            })
                            ->columnSpan(1),

                        TextInput::make('razorpay_webhook_url')
                            ->label('Payment Endpoint URL (Set this as Endpoint URL in your Razorpay account)')
                            ->url()
                            ->maxLength(500)
                            ->columnSpanFull(),

                        TextInput::make('razorpay_webhook_secret')
                            ->label('Webhook Secret Key')
                            ->maxLength(255)
                            ->dehydrateStateUsing(function ($state) {
                                if ($this->shouldMaskData() && preg_match('/x{5}$/', $state)) {
                                    return $this->getOriginalValue('razorpay_webhook_secret');
                                }
                                return $state;
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Section::make('Stripe Payments')
                    ->description('Configure Stripe payment gateway settings')
                    ->schema([
                        Toggle::make('stripe_enabled')
                            ->label('Stripe Payments [ Enable / Disable ]')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set, Get $get) {

                                if (!$state) {

                                    $razorpayEnabled = (bool) ($get('razorpay_enabled') ?? false);

                                    if (!$razorpayEnabled) {

                                        $set('stripe_enabled', true);
                                        Notification::make()
                                            ->title('Cannot Disable Stripe')
                                            ->body('At least one of Razorpay OR Stripe must be active. Please enable Razorpay first before disabling Stripe.')
                                            ->danger()
                                            ->send();
                                    }
                                }
                            })
                            ->columnSpanFull(),

                        Select::make('stripe_mode')
                            ->label('Payment Mode [sandbox / live]')
                            ->options([
                                'test' => 'Test',
                                'live' => 'Live',
                            ])
                            ->default('test')
                            ->columnSpan(1),

                        TextInput::make('stripe_currency')
                            ->label('Currency Code [Stripe supported]')
                            ->default('INR')
                            ->maxLength(10)
                            ->columnSpan(1),

                        TextInput::make('stripe_webhook_url')
                            ->label('Payment Endpoint URL (Set this as Endpoint URL in your Stripe account)')
                            ->url()
                            ->maxLength(500)
                            ->columnSpanFull(),

                        TextInput::make('stripe_publishable_key')
                            ->label('Stripe Publishable Key')
                            ->maxLength(255)
                            ->dehydrateStateUsing(function ($state) {
                                if ($this->shouldMaskData() && preg_match('/x{5}$/', $state)) {
                                    return $this->getOriginalValue('stripe_publishable_key');
                                }
                                return $state;
                            })
                            ->columnSpan(1),

                        TextInput::make('stripe_secret_key')
                            ->label('Stripe Secret Key')
                            ->maxLength(255)
                            ->dehydrateStateUsing(function ($state) {
                                if ($this->shouldMaskData() && preg_match('/x{5}$/', $state)) {
                                    return $this->getOriginalValue('stripe_secret_key');
                                }
                                return $state;
                            })
                            ->columnSpan(1),

                        TextInput::make('stripe_webhook_secret')
                            ->label('Stripe Webhook Secret Key')
                            ->maxLength(255)
                            ->dehydrateStateUsing(function ($state) {
                                if ($this->shouldMaskData() && preg_match('/x{5}$/', $state)) {
                                    return $this->getOriginalValue('stripe_webhook_secret');
                                }
                                return $state;
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Section::make('Paytm Payments')
                    ->description('Configure Paytm payment gateway settings')
                    ->schema([
                        Toggle::make('paytm_enabled')
                            ->label('Paytm Payments [ Enable / Disable ]')
                            ->default(false)
                            ->columnSpanFull(),

                        Select::make('paytm_mode')
                            ->label('Paytm Mode [sandbox / live]')
                            ->options([
                                'production' => 'Production',
                                'sandbox' => 'Sandbox',
                            ])
                            ->default('production')
                            ->columnSpan(1),

                        TextInput::make('paytm_merchant_key')
                            ->label('Paytm Merchant Key')
                            ->maxLength(255)
                            ->dehydrateStateUsing(function ($state) {
                                if ($this->shouldMaskData() && preg_match('/x{5}$/', $state)) {
                                    return $this->getOriginalValue('paytm_merchant_key');
                                }
                                return $state;
                            })
                            ->columnSpan(1),

                        TextInput::make('paytm_merchant_id')
                            ->label('Paytm Merchant ID')
                            ->maxLength(255)
                            ->dehydrateStateUsing(function ($state) {
                                if ($this->shouldMaskData() && preg_match('/x{5}$/', $state)) {
                                    return $this->getOriginalValue('paytm_merchant_id');
                                }
                                return $state;
                            })
                            ->columnSpan(1),

                        TextInput::make('paytm_website')
                            ->label('Paytm Website [click here to know]')
                            ->default('DEFAULT')
                            ->maxLength(255)
                            ->columnSpan(1),

                        TextInput::make('paytm_industry_type')
                            ->label('Industry Type ID [click here to know]')
                            ->default('Retail')
                            ->maxLength(255)
                            ->columnSpan(1),
                    ])
                    ->columns(2)
                    ->hidden()
                    ->collapsible(),

                Section::make('Cash On Delivery')
                    ->description('Configure Cash on Delivery payment method')
                    ->schema([
                        Toggle::make('cod_enabled')
                            ->label('COD [ Enable / Disable ]')
                            ->default(true)
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ])
            ->statePath('data');
    }

    public function resetSettings(): void
    {
        $this->mount();
        Notification::make()
            ->title('Settings reset to default values')
            ->warning()
            ->send();
    }

    public function save(): void
    {
        // Check if user is restricted (ID 2)
        $userId = auth()->id();
        if ($userId === 2) {
            Notification::make()
                ->title('Access Restricted')
                ->body('You do not have permission to update payment settings.')
                ->danger()
                ->send();
            return;
        }

        $data = $this->form->getState();

        $razorpayEnabled = (bool) ($data['razorpay_enabled'] ?? false);
        $stripeEnabled = (bool) ($data['stripe_enabled'] ?? false);

        if (!$razorpayEnabled && !$stripeEnabled) {
            Notification::make()
                ->title('Validation Error')
                ->body('At least one of Razorpay OR Stripe must be enabled. This is mandatory for the system to function properly.')
                ->danger()
                ->send();
            return;
        }

        SystemConfiguration::setValue('razorpay_enabled', $data['razorpay_enabled'] ?? false, 'payment', 'Razorpay enabled status');
        SystemConfiguration::setValue('razorpay_key_id', $data['razorpay_key_id'] ?? '', 'payment', 'Razorpay Key ID');
        SystemConfiguration::setValue('razorpay_secret_key', $data['razorpay_secret_key'] ?? '', 'payment', 'Razorpay Secret Key');
        SystemConfiguration::setValue('razorpay_webhook_url', $data['razorpay_webhook_url'] ?? '', 'payment', 'Razorpay Webhook URL');
        SystemConfiguration::setValue('razorpay_webhook_secret', $data['razorpay_webhook_secret'] ?? '', 'payment', 'Razorpay Webhook Secret');

        SystemConfiguration::setValue('stripe_enabled', $data['stripe_enabled'] ?? false, 'payment', 'Stripe enabled status');
        SystemConfiguration::setValue('stripe_mode', $data['stripe_mode'] ?? 'test', 'payment', 'Stripe mode');
        SystemConfiguration::setValue('stripe_publishable_key', $data['stripe_publishable_key'] ?? '', 'payment', 'Stripe Publishable Key');
        SystemConfiguration::setValue('stripe_secret_key', $data['stripe_secret_key'] ?? '', 'payment', 'Stripe Secret Key');
        SystemConfiguration::setValue('stripe_webhook_url', $data['stripe_webhook_url'] ?? '', 'payment', 'Stripe Webhook URL');
        SystemConfiguration::setValue('stripe_webhook_secret', $data['stripe_webhook_secret'] ?? '', 'payment', 'Stripe Webhook Secret');
        SystemConfiguration::setValue('stripe_currency', $data['stripe_currency'] ?? 'INR', 'payment', 'Stripe Currency');

        SystemConfiguration::setValue('paytm_enabled', $data['paytm_enabled'] ?? false, 'payment', 'Paytm enabled status');
        SystemConfiguration::setValue('paytm_mode', $data['paytm_mode'] ?? 'production', 'payment', 'Paytm mode');
        SystemConfiguration::setValue('paytm_merchant_key', $data['paytm_merchant_key'] ?? '', 'payment', 'Paytm Merchant Key');
        SystemConfiguration::setValue('paytm_merchant_id', $data['paytm_merchant_id'] ?? '', 'payment', 'Paytm Merchant ID');
        SystemConfiguration::setValue('paytm_website', $data['paytm_website'] ?? 'DEFAULT', 'payment', 'Paytm Website');
        SystemConfiguration::setValue('paytm_industry_type', $data['paytm_industry_type'] ?? 'Retail', 'payment', 'Paytm Industry Type');

        SystemConfiguration::setValue('cod_enabled', $data['cod_enabled'] ?? true, 'payment', 'Cash on Delivery enabled status');

        Notification::make()
            ->title('Payment settings saved successfully')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getHeading(): string
    {
        return 'Payment Methods';
    }

    public function getSubheading(): string
    {
        return 'Configure payment gateway settings and methods';
    }
}
