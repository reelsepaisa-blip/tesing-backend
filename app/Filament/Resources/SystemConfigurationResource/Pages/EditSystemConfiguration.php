<?php

namespace App\Filament\Resources\SystemConfigurationResource\Pages;

use App\Filament\Resources\SystemConfigurationResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

class EditSystemConfiguration extends EditRecord
{
    protected static string $resource = SystemConfigurationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),

            Actions\Action::make('test_connection')
                ->label('Test Configuration')
                ->icon('heroicon-o-signal')
                ->color('info')
                ->action(function () {
                    $this->testConfiguration();
                })
                ->visible(function () {
                    return in_array($this->record->category, ['payment', 'notification', 'sms', 'maps']);
                }),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Configuration updated successfully';
    }

    protected function testConfiguration(): void
    {
        $config = $this->record;
        $testResult = false;
        $message = 'Test not implemented for this configuration type';

        try {
            switch ($config->category) {
                case 'payment':
                    $testResult = $this->testPaymentGateway($config);
                    break;
                case 'notification':
                    $testResult = $this->testFCM($config);
                    break;
                case 'sms':
                    $testResult = $this->testSMS($config);
                    break;
                case 'maps':
                    $testResult = $this->testGoogleMaps($config);
                    break;
            }

            if ($testResult) {
                \Filament\Notifications\Notification::make()
                    ->title('Test Successful')
                    ->body('Configuration test successful')
                    ->success()
                    ->send();
            } else {
                \Filament\Notifications\Notification::make()
                    ->title('Test Warning')
                    ->body($message)
                    ->warning()
                    ->send();
            }
        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('Test Failed')
                ->body('Test failed: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function testPaymentGateway($config): bool
    {

        if (str_contains($config->key, 'razorpay')) {
            return !empty($config->value) && strlen($config->value) > 10;
        }
        if (str_contains($config->key, 'stripe')) {
            return !empty($config->value) && (str_starts_with($config->value, 'pk_') || str_starts_with($config->value, 'sk_'));
        }
        return false;
    }

    private function testFCM($config): bool
    {

        return !empty($config->value) && strlen($config->value) > 20;
    }

    private function testSMS($config): bool
    {

        if (str_contains($config->key, 'twilio')) {
            return !empty($config->value) && strlen($config->value) > 10;
        }
        if (str_contains($config->key, 'msg91')) {
            return !empty($config->value) && strlen($config->value) > 10;
        }
        return false;
    }

    private function testGoogleMaps($config): bool
    {

        return !empty($config->value) && strlen($config->value) > 20;
    }
}
