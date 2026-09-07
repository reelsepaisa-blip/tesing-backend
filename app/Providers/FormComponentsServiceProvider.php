<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Blade;

class FormComponentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::HEAD_END,
            fn(): string => view('components.google-maps-script')->render()
        );
    }
}
