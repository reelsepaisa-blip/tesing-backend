<?php

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Exception;
use Filament\Actions\Action;
use App\Models\DriverSearchSetting;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ReferralSettings extends Page
{
    protected static ?string $slug = 'referral-amount-settings';
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-currency-rupee';
    protected static ?string $navigationLabel = 'Referral Amount Settings';
    protected static string | \UnitEnum | null $navigationGroup = 'Settings';
    protected static ?int $navigationSort = 12;
    protected string $view = 'filament.pages.referral-settings';

    public ?array $data = [];

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public function mount(): void
    {
        $setting = DriverSearchSetting::getActive();
        $this->form->fill([
            'driver_referrer_reward' => $setting->driver_referrer_reward ?? 100.00,
            'driver_referred_reward' => $setting->driver_referred_reward ?? 100.00,
            'user_referrer_reward' => $setting->user_referrer_reward ?? 100.00,
            'user_referred_reward' => $setting->user_referred_reward ?? 100.00,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Driver Referral Rewards')
                    ->description('Configure referral and earn amounts for drivers (role_id 2)')
                    ->schema([
                        TextInput::make('driver_referrer_reward')
                            ->label('Driver Referrer Reward Amount')
                            ->numeric()
                            ->prefix('₹')
                            ->default(100.00)
                            ->minValue(0)
                            ->required()
                            ->helperText('Amount to credit to the driver who referred someone'),
                        TextInput::make('driver_referred_reward')
                            ->label('Driver Referred Reward Amount')
                            ->numeric()
                            ->prefix('₹')
                            ->default(100.00)
                            ->minValue(0)
                            ->required()
                            ->helperText('Amount to credit to the driver who was referred'),
                    ])
                    ->columns(2)
                    ->collapsible(),
                Section::make('User (Customer) Referral Rewards')
                    ->description('Configure referral and earn amounts for users/customers (role_id 3)')
                    ->schema([
                        TextInput::make('user_referrer_reward')
                            ->label('User Referrer Reward Amount')
                            ->numeric()
                            ->prefix('₹')
                            ->default(100.00)
                            ->minValue(0)
                            ->required()
                            ->helperText('Amount to credit to the user who referred someone'),
                        TextInput::make('user_referred_reward')
                            ->label('User Referred Reward Amount')
                            ->numeric()
                            ->prefix('₹')
                            ->default(100.00)
                            ->minValue(0)
                            ->required()
                            ->helperText('Amount to credit to the user who was referred'),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();

            // Validate driver rewards
            if (!isset($data['driver_referrer_reward']) || $data['driver_referrer_reward'] < 0) {
                Notification::make()
                    ->title('Validation Error')
                    ->body('Driver referrer reward amount is required and must be 0 or greater.')
                    ->danger()
                    ->send();
                return;
            }

            if (!isset($data['driver_referred_reward']) || $data['driver_referred_reward'] < 0) {
                Notification::make()
                    ->title('Validation Error')
                    ->body('Driver referred reward amount is required and must be 0 or greater.')
                    ->danger()
                    ->send();
                return;
            }

            // Validate user rewards
            if (!isset($data['user_referrer_reward']) || $data['user_referrer_reward'] < 0) {
                Notification::make()
                    ->title('Validation Error')
                    ->body('User referrer reward amount is required and must be 0 or greater.')
                    ->danger()
                    ->send();
                return;
            }

            if (!isset($data['user_referred_reward']) || $data['user_referred_reward'] < 0) {
                Notification::make()
                    ->title('Validation Error')
                    ->body('User referred reward amount is required and must be 0 or greater.')
                    ->danger()
                    ->send();
                return;
            }

            $setting = DriverSearchSetting::where('is_active', true)->first();

            if (!$setting) {
                $setting = DriverSearchSetting::create(DriverSearchSetting::defaults());
            }

            $setting->update([
                'driver_referrer_reward' => (float) $data['driver_referrer_reward'],
                'driver_referred_reward' => (float) $data['driver_referred_reward'],
                'user_referrer_reward' => (float) $data['user_referrer_reward'],
                'user_referred_reward' => (float) $data['user_referred_reward'],
            ]);

            Notification::make()
                ->title('Referral settings saved successfully')
                ->body("Driver: Referrer ₹{$data['driver_referrer_reward']}, Referred ₹{$data['driver_referred_reward']} | User: Referrer ₹{$data['user_referrer_reward']}, Referred ₹{$data['user_referred_reward']}")
                ->success()
                ->send();
        } catch (Exception $e) {
            Notification::make()
                ->title('Error saving settings')
                ->body('An error occurred while saving. Please try again.')
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label('Reset to Default')
                ->color('warning')
                ->action(function () {
                    $this->form->fill([
                        'driver_referrer_reward' => 100.00,
                        'driver_referred_reward' => 100.00,
                        'user_referrer_reward' => 100.00,
                        'user_referred_reward' => 100.00,
                    ]);
                    Notification::make()
                        ->title('Settings reset to default values (₹100.00 each)')
                        ->warning()
                        ->send();
                })
                ->requiresConfirmation(),

            Action::make('save')
                ->label('Save Settings')
                ->color('success')
                ->requiresConfirmation(false)
                ->action(function () {
                    $this->save();
                })
                ->keyBindings(['mod+s']),
        ];
    }

    public function getHeading(): string
    {
        return 'Referral Amount Settings';
    }

    public function getSubheading(): string
    {
        return 'Configure separate referral and earn amounts for drivers and users';
    }
}
