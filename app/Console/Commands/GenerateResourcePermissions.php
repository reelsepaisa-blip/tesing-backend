<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;

class GenerateResourcePermissions extends Command
{
    protected $signature = 'permissions:generate';
    protected $description = 'Generate permissions for all Filament resources';

    public function handle()
    {
        $resourcePath = app_path('Filament/Resources');
        $files = File::files($resourcePath);

        foreach ($files as $file) {
            $className = 'App\\Filament\\Resources\\' . $file->getBasename('.php');
            
            if (class_exists($className) && is_subclass_of($className, 'App\\Filament\\Resources\\BaseResource')) {
                $this->info("Generating permissions for {$className}");
                $className::createResourcePermissions();
            }
        }

        $this->info('All resource permissions have been generated.');
    }
}








