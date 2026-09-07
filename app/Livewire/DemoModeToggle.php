<?php

namespace App\Livewire;

use Livewire\Component;
use App\Services\EnvFileService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Filament\Notifications\Notification;

class DemoModeToggle extends Component
{
    public bool $demoMode = false;

    public function mount(): void
    {
        $this->demoMode = config('app.demo_mode', false);
    }

    public function toggle(): void
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
                // Read the fresh value directly from .env file
                $envValue = EnvFileService::getEnvVariable('DEMO_MODE', 'false');
                $freshValue = in_array(strtolower($envValue), ['true', '1', 'yes'], true);

                // Update the config in memory for current request
                Config::set('app.demo_mode', $freshValue);

                // Clear config cache key to force reload on next request
                Cache::forget('config.app.demo_mode');

                // Update the local state
                $this->demoMode = $freshValue;

                Notification::make()
                    ->title('Demo Mode ' . ($enabled ? 'Enabled' : 'Disabled'))
                    ->body('Demo mode has been ' . ($enabled ? 'enabled' : 'disabled') . '. Refreshing page...')
                    ->success()
                    ->send();

                // Refresh the page after a short delay to apply changes
                $this->dispatch('demo-mode-toggled');
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

    protected function canAccess(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        // Check if user is admin (user id = 1, role_id = 1, or has 'admin' role)
        if ($user->id === 1 || (isset($user->role_id) && $user->role_id === 1)) {
            return true;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return true;
        }

        if (method_exists($user, 'roles')) {
            return $user->roles()->where('name', 'admin')->exists();
        }

        return false;
    }

    public function render()
    {
        // Only render if user can access
        if (!$this->canAccess()) {
            return view('livewire.empty');
        }

        return view('livewire.demo-mode-toggle');
    }
}
