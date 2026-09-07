<?php

namespace App\Filament\Resources\SystemConfigurationResource\Pages;

use App\Filament\Resources\SystemConfigurationResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListSystemConfigurations extends ListRecords
{
    protected static string $resource = SystemConfigurationResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add Configuration')
                ->icon('heroicon-o-plus'),

            Actions\Action::make('sync_env')
                ->label('Sync with .env')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->action(function () {
                    $this->syncWithEnvFile();
                })
                ->requiresConfirmation()
                ->modalHeading('Sync with Environment File')
                ->modalDescription('This will update configurations based on your .env file values. Are you sure?'),
        ];
    }

    protected function syncWithEnvFile(): void
    {
        $envMappings = [

            'razorpay_key_id' => ['env' => 'RAZORPAY_KEY_ID', 'category' => 'payment'],
            'razorpay_key_secret' => ['env' => 'RAZORPAY_KEY_SECRET', 'category' => 'payment'],
            'razorpay_webhook_secret' => ['env' => 'RAZORPAY_WEBHOOK_SECRET', 'category' => 'payment'],
            'stripe_key' => ['env' => 'STRIPE_KEY', 'category' => 'payment'],
            'stripe_secret' => ['env' => 'STRIPE_SECRET', 'category' => 'payment'],
            'stripe_webhook_secret' => ['env' => 'STRIPE_WEBHOOK_SECRET', 'category' => 'payment'],

            'fcm_server_key' => ['env' => 'FCM_SERVER_KEY', 'category' => 'notification'],
            'fcm_sender_id' => ['env' => 'FCM_SENDER_ID', 'category' => 'notification'],
            'fcm_project_id' => ['env' => 'FCM_PROJECT_ID', 'category' => 'notification'],

            'twilio_sid' => ['env' => 'TWILIO_SID', 'category' => 'sms'],
            'twilio_token' => ['env' => 'TWILIO_TOKEN', 'category' => 'sms'],
            'twilio_from' => ['env' => 'TWILIO_FROM', 'category' => 'sms'],
            'msg91_api_key' => ['env' => 'MSG91_API_KEY', 'category' => 'sms'],
            'msg91_sender_id' => ['env' => 'MSG91_SENDER_ID', 'category' => 'sms'],

            'google_maps_api_key' => ['env' => 'GOOGLE_MAPS_API_KEY', 'category' => 'maps'],

            'google_client_id' => ['env' => 'GOOGLE_CLIENT_ID', 'category' => 'social'],
            'google_client_secret' => ['env' => 'GOOGLE_CLIENT_SECRET', 'category' => 'social'],
        ];

        $synced = 0;
        foreach ($envMappings as $key => $config) {
            $envValue = env($config['env']);
            if ($envValue) {
                \App\Models\SystemConfiguration::setValue(
                    $key,
                    $envValue,
                    $config['category'],
                    "Synced from {$config['env']} environment variable"
                );
                $synced++;
            }
        }

        \Filament\Notifications\Notification::make()
            ->title('Sync Completed')
            ->body("Successfully synced {$synced} configurations from .env file")
            ->success()
            ->send();
    }
}
