<?php

namespace App\Providers;

use App\Services\ConfigurationService;
use Illuminate\Support\ServiceProvider;

class ConfigurationServiceProvider extends ServiceProvider
{
    
    public function register(): void
    {

    }

    
    public function boot(): void
    {

        if ($this->app->environment() !== 'testing' && $this->shouldApplyConfig()) {
            try {
                ConfigurationService::applyDatabaseConfig();
            } catch (\Exception $e) {

                logger()->warning('Failed to apply database configurations: ' . $e->getMessage());
            }
        }
    }

    
    private function shouldApplyConfig(): bool
    {

        if ($this->app->runningInConsole()) {
            $command = $_SERVER['argv'][1] ?? '';

            $skipCommands = ['migrate', 'migrate:fresh', 'migrate:reset', 'migrate:rollback', 'db:seed'];

            foreach ($skipCommands as $skipCommand) {
                if (str_contains($command, $skipCommand)) {
                    return false;
                }
            }
        }

        return true;
    }
}
