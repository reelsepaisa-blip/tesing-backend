<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Filament\Notifications\Notification;
use App\Services\EnvFileService;
use Illuminate\Support\Facades\Artisan;

class DemoModeToggleWidget extends Widget
{
    protected string $view = 'filament.widgets.demo-mode-toggle-widget';

    protected int | string | array $columnSpan = 'full';

    public bool $demoMode = false;

    public function mount(): void
    {
        $this->demoMode = config('app.demo_mode', false);
    }

    public function toggleDemoMode(): void
    {
        if (!$this->canAccess()) {
            Notification::make()
                ->title('Access Denied')
                ->body('You do not have permission to change demo mode settings.')
                ->danger()
                ->send();
            return;
        }

        // Toggle the value
        $enabled = !$this->demoMode;

        try {
            $value = $enabled ? 'true' : 'false';
            $success = EnvFileService::updateEnvVariable('DEMO_MODE', $value);

            if ($success) {
                // Clear config cache to reload the new value
                Artisan::call('config:clear');

                Notification::make()
                    ->title('Demo Mode ' . ($enabled ? 'Enabled' : 'Disabled'))
                    ->body('Demo mode has been ' . ($enabled ? 'enabled' : 'disabled') . '. The configuration cache has been cleared.')
                    ->success()
                    ->send();

                // Update the local state
                $this->demoMode = $enabled;
            } else {
                Notification::make()
                    ->title('Failed to Update')
                    ->body('Failed to update .env file. Please check file permissions.')
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error')
                ->body('An error occurred while updating demo mode: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Check if the current user can access this widget
     */
    protected function canAccess(): bool
    {
        return static::canView();
    }

    /**
     * Determine if the widget should be visible
     * Always return false - widget should not be displayed on dashboard
     * The demo mode toggle is only available in the user menu dropdown
     */
    public static function canView(): bool
    {
        return false;
    }
}
