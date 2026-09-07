<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;

class ViewServiceProvider extends ServiceProvider
{
    
    public function register(): void
    {

    }

    
    public function boot(): void
    {
        Blade::component('impersonation-banner', \App\View\Components\ImpersonationBanner::class);
    }
}
